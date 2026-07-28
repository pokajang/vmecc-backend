<?php

namespace App\Services;

use App\Models\DutyCoverageAssignment;
use App\Models\Report;
use App\Models\Team;
use Illuminate\Support\Collection;

class ReportActionContextService
{
    /**
     * Build display-safe routing context for actionable reports without
     * recalculating the team from the recipient's current membership.
     */
    public function forReports(Collection $reports): Collection
    {
        $teamNames = Team::query()
            ->whereIn('id', $reports->pluck('scope_team_id')->filter()->unique())
            ->pluck('name', 'id');
        $coverages = DutyCoverageAssignment::query()
            ->whereIn(
                'id',
                $reports->pluck('next_action_duty_coverage_assignment_id')->filter()->unique(),
            )
            ->get()
            ->keyBy('id');

        return $reports->mapWithKeys(function (Report $report) use ($teamNames, $coverages) {
            $coverage = $report->next_action_duty_coverage_assignment_id
                ? $coverages->get($report->next_action_duty_coverage_assignment_id)
                : null;
            $role = trim((string) $report->next_action_role);
            $teamId = $report->scope_team_id ? (int) $report->scope_team_id : null;

            return [$report->id => [
                'teamId' => $teamId,
                'teamName' => $teamId
                    ? (string) ($teamNames->get($teamId) ?: "Team {$teamId}")
                    : null,
                'actingRole' => $role ?: null,
                'actingRoleCode' => RoleCatalog::abbreviationForRole($role),
                'assignmentSource' => $this->assignmentSource($report),
                'coverageAssignmentId' => $coverage ? (int) $coverage->id : null,
                'coverageFrom' => $coverage?->effective_from?->toIso8601String(),
                'coverageUntil' => $coverage?->effective_until?->toIso8601String(),
                'routingReasonCode' => $report->routing_reason_code,
            ]];
        });
    }

    public function grouped(
        Collection $reports,
        string $action,
        string $route,
    ): array {
        $contexts = $this->forReports($reports);

        return $reports
            ->groupBy(function (Report $report) use ($contexts) {
                $context = $contexts->get($report->id, []);

                return implode('|', [
                    $context['teamId'] ?? 'none',
                    strtolower((string) ($context['actingRole'] ?? '')),
                    $context['assignmentSource'] ?? 'role_assignment',
                    $context['coverageAssignmentId'] ?? 'none',
                ]);
            })
            ->map(function (Collection $rows) use ($contexts, $action, $route) {
                /** @var Report $first */
                $first = $rows->first();
                $context = $contexts->get($first->id, []);
                $query = array_filter([
                    'scope' => 'actionable',
                    'action' => $action,
                    'team_id' => $context['teamId'] ?? null,
                ], fn ($value) => $value !== null && $value !== '');

                return [
                    'action' => $action,
                    'count' => $rows->count(),
                    ...$context,
                    'to' => $route.'?'.http_build_query($query),
                ];
            })
            ->sortBy([
                ['teamName', 'asc'],
                ['actingRole', 'asc'],
                ['assignmentSource', 'asc'],
            ])
            ->values()
            ->all();
    }

    public function groupedSubmissions(
        Collection $reports,
        string $route,
        string $dateFrom,
        string $dateTo,
    ): array {
        $teamNames = Team::query()
            ->whereIn('id', $reports->pluck('scope_team_id')->filter()->unique())
            ->pluck('name', 'id');

        return $reports
            ->groupBy(function (Report $report) {
                $snapshot = is_array($report->workflow_snapshot) ? $report->workflow_snapshot : [];

                return implode('|', [
                    $report->scope_team_id ?: 'none',
                    strtolower(trim((string) ($snapshot['submitterRole'] ?? ''))),
                ]);
            })
            ->map(function (Collection $rows) use ($teamNames, $route, $dateFrom, $dateTo) {
                /** @var Report $first */
                $first = $rows->first();
                $snapshot = is_array($first->workflow_snapshot) ? $first->workflow_snapshot : [];
                $role = trim((string) ($snapshot['submitterRole'] ?? ''));
                $teamId = $first->scope_team_id ? (int) $first->scope_team_id : null;
                $query = array_filter([
                    'scope' => 'all',
                    'team_id' => $teamId,
                    'date_from' => $dateFrom,
                    'date_to' => $dateTo,
                ], fn ($value) => $value !== null && $value !== '');

                return [
                    'action' => 'submitted',
                    'count' => $rows->count(),
                    'teamId' => $teamId,
                    'teamName' => $teamId
                        ? (string) ($teamNames->get($teamId) ?: "Team {$teamId}")
                        : null,
                    'actingRole' => $role ?: null,
                    'actingRoleCode' => RoleCatalog::abbreviationForRole($role),
                    'assignmentSource' => 'submission',
                    'coverageAssignmentId' => null,
                    'coverageFrom' => null,
                    'coverageUntil' => null,
                    'routingReasonCode' => null,
                    'to' => $route.'?'.http_build_query($query),
                ];
            })
            ->sortBy([
                ['teamName', 'asc'],
                ['actingRole', 'asc'],
            ])
            ->values()
            ->all();
    }

    private function assignmentSource(Report $report): string
    {
        return match ($report->routing_reason_code) {
            'team_temporary_coverage' => 'temporary_coverage',
            'fallback_role_assignment' => 'fallback',
            'no_eligible_recipient' => 'unassigned',
            default => 'role_assignment',
        };
    }
}
