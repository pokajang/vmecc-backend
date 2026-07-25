<?php

namespace App\Services;

use App\Models\Leave;
use App\Models\OvertimeRecord;
use App\Models\PayrollClaim;
use App\Models\Report;
use App\Models\Roster;
use App\Models\SalaryAssignment;
use App\Models\SalaryAssignmentDraft;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DashboardStatsService
{
    public const PERIODS = ['this_month', 'last_month', '3m', '6m', 'ytd'];

    private const ACTIVE_STATUS_VALUES = ['Active', 'active', 'ACTIVE'];

    public function stats(string $module, string $period): array
    {
        [$from, $to] = $this->resolvePeriod($period);

        return match ($module) {
            'payroll' => $this->payroll($from, $to),
            'overtime' => $this->overtime($from, $to),
            'leave' => $this->leave($from, $to),
            'roster' => $this->roster($from, $to),
            'reports' => $this->reports($from, $to),
            default => abort(404, 'Dashboard stats module not found.'),
        };
    }

    public function resolvePeriod(string $period): array
    {
        $now = now();

        return match ($period) {
            'last_month' => [
                $now->copy()->subMonthNoOverflow()->startOfMonth(),
                $now->copy()->subMonthNoOverflow()->endOfMonth(),
            ],
            '3m' => [
                $now->copy()->subMonthsNoOverflow(2)->startOfMonth(),
                $now->copy()->endOfDay(),
            ],
            '6m' => [
                $now->copy()->subMonthsNoOverflow(5)->startOfMonth(),
                $now->copy()->endOfDay(),
            ],
            'ytd' => [
                $now->copy()->startOfYear(),
                $now->copy()->endOfDay(),
            ],
            default => [
                $now->copy()->startOfMonth(),
                $now->copy()->endOfDay(),
            ],
        };
    }

    private function payroll(Carbon $from, Carbon $to): array
    {
        $periodClaims = PayrollClaim::query()
            ->whereBetween('submitted_at', [$from, $to]);

        $claimsByType = $this->groupCountBy((clone $periodClaims), 'claim_type');
        $claimsByStatusAndStage = (clone $periodClaims)
            ->select('status', 'workflow_stage')
            ->selectRaw('COUNT(*) as total')
            ->groupBy('status', 'workflow_stage')
            ->get();

        $paidClaims = PayrollClaim::query()
            ->where('status', 'Paid')
            ->whereBetween('paid_at', [$from, $to])
            ->selectRaw('COUNT(*) as total, COALESCE(SUM(projected_net_payout), 0) as payout_total')
            ->first();

        $approvedUnpaid = PayrollClaim::query()
            ->where('status', 'Approved')
            ->whereNull('paid_at')
            ->selectRaw('COUNT(*) as total, COALESCE(SUM(projected_net_payout), 0) as payout_total')
            ->first();

        $activeAssignments = SalaryAssignment::query()
            ->whereIn('status', self::ACTIVE_STATUS_VALUES)
            ->count();

        $activeUsers = User::query()
            ->where(fn (Builder $query) => $query
                ->whereNull('status')
                ->orWhereIn('status', self::ACTIVE_STATUS_VALUES))
            ->count();

        $assignedUserIds = SalaryAssignment::query()
            ->whereIn('status', self::ACTIVE_STATUS_VALUES)
            ->whereNotNull('employee_user_id')
            ->distinct()
            ->count('employee_user_id');

        return [
            'pendingApprovals' => PayrollClaim::query()->where('status', 'Pending')->count(),
            'approvedUnpaidCount' => (int) ($approvedUnpaid->total ?? 0),
            'approvedUnpaidTotalMyr' => round((float) ($approvedUnpaid->payout_total ?? 0), 2),
            'paidThisMonthCount' => (int) ($paidClaims->total ?? 0),
            'paidThisMonthTotalMyr' => round((float) ($paidClaims->payout_total ?? 0), 2),
            'byType' => [
                'salary' => (int) $claimsByType->get('salary', 0),
                'expense' => (int) $claimsByType->get('expense', 0),
                'other' => (int) $claimsByType
                    ->except(['salary', 'expense'])
                    ->sum(),
            ],
            'byStatus' => [
                'pending' => $this->statusStageCount($claimsByStatusAndStage, 'Pending'),
                'pendingReview' => $this->statusStageCount($claimsByStatusAndStage, 'Pending', ['check', 'review']),
                'pendingApproval' => $this->statusStageCount($claimsByStatusAndStage, 'Pending', ['recommend', 'approve']),
                'approved' => $this->statusStageCount($claimsByStatusAndStage, 'Approved'),
                'paid' => $this->statusStageCount($claimsByStatusAndStage, 'Paid'),
                'rejected' => $this->statusStageCount($claimsByStatusAndStage, 'Rejected'),
                'cancelled' => $this->statusStageCount($claimsByStatusAndStage, 'Cancelled'),
            ],
            'monthlyTrend' => $this->monthlyCountTrend((clone $periodClaims), 'submitted_at', $from, $to),
            'incompleteContracts' => max(0, $activeUsers - $assignedUserIds),
            'staffWithOpenClaims' => PayrollClaim::query()
                ->whereIn('status', ['Pending', 'Approved'])
                ->distinct()
                ->count('user_id'),
            'activeAssignments' => $activeAssignments,
            'assignmentDrafts' => SalaryAssignmentDraft::query()->count(),
        ];
    }

    private function overtime(Carbon $from, Carbon $to): array
    {
        $periodRecords = OvertimeRecord::query()
            ->whereBetween('claim_date', [$from->toDateString(), $to->toDateString()]);

        $recordsByType = $this->groupCountBy((clone $periodRecords), 'overtime_type');
        $recordsByStatus = $this->groupCountBy((clone $periodRecords), 'status');
        $approved = (clone $periodRecords)
            ->where('status', 'Approved')
            ->selectRaw('COUNT(*) as total, COALESCE(SUM(duration_minutes), 0) as duration_total')
            ->first();
        $recordsByUser = (clone $periodRecords)
            ->whereNotNull('user_id')
            ->select('user_id')
            ->selectRaw('COUNT(*) as total')
            ->groupBy('user_id')
            ->pluck('total', 'user_id');
        $submittedThisPeriod = (clone $periodRecords)->count();

        return [
            'pendingApprovals' => OvertimeRecord::query()->where('status', 'Pending')->count(),
            'approvedHoursThisPeriod' => round(((int) ($approved->duration_total ?? 0)) / 60, 1),
            'staffWithOpenRequests' => OvertimeRecord::query()
                ->where('status', 'Pending')
                ->distinct()
                ->count('user_id'),
            'submittedThisPeriod' => $submittedThisPeriod,
            'approvedRequestsThisPeriod' => (int) ($approved->total ?? 0),
            'byType' => [
                'weekday' => (int) $recordsByType->get('weekday', 0),
                'weekend' => (int) $recordsByType->get('weekend', 0),
                'holiday' => (int) $recordsByType->get('holiday', 0),
            ],
            'byStatus' => $this->statusCountsFromGroupedValues($recordsByStatus, ['Pending', 'Approved', 'Rejected', 'Cancelled']),
            'byTeam' => $this->countsByTeamForUsers($recordsByUser),
            'monthlyTrend' => $this->monthlyCountTrend((clone $periodRecords), 'claim_date', $from, $to),
        ];
    }

    private function leave(Carbon $from, Carbon $to): array
    {
        $periodLeaves = Leave::query()
            ->whereBetween('start_date', [$from->toDateString(), $to->toDateString()]);

        $today = now()->toDateString();
        $approved = (clone $periodLeaves)
            ->where('status', 'Approved')
            ->selectRaw('COALESCE(SUM(days), 0) as approved_days_total')
            ->first();
        $recordsByUser = (clone $periodLeaves)
            ->whereNotNull('user_id')
            ->select('user_id')
            ->selectRaw('COUNT(*) as total')
            ->groupBy('user_id')
            ->pluck('total', 'user_id');

        return [
            'pendingApprovals' => Leave::query()->where('status', 'Pending')->count(),
            'approvedDaysThisPeriod' => round((float) ($approved->approved_days_total ?? 0), 1),
            'staffWithPendingRequests' => Leave::query()
                ->where('status', 'Pending')
                ->distinct()
                ->count('user_id'),
            'staffCurrentlyOnLeave' => Leave::query()
                ->where('status', 'Approved')
                ->whereDate('start_date', '<=', $today)
                ->whereDate('end_date', '>=', $today)
                ->distinct()
                ->count('user_id'),
            'byTeam' => $this->countsByTeamForUsers($recordsByUser),
            'monthlyTrend' => $this->monthlyCountTrend((clone $periodLeaves), 'start_date', $from, $to),
        ];
    }

    private function roster(Carbon $from, Carbon $to): array
    {
        $periodRosters = Roster::query()
            ->where('status', 'published')
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->get();

        $teamIds = $periodRosters->pluck('team_id')->unique()->values()->all();
        $teams = Team::query()
            ->whereIn('id', $teamIds)
            ->orderBy('name')
            ->get();

        $memberCounts = TeamMember::query()
            ->whereIn('team_id', $teamIds)
            ->where(fn (Builder $query) => $this->activeMembership($query))
            ->selectRaw('team_id, COUNT(*) as total')
            ->groupBy('team_id')
            ->pluck('total', 'team_id');

        return [
            'teamsOnDuty' => Team::query()->whereRaw('LOWER(status) = ?', ['on duty'])->count(),
            'draftsPendingPublish' => Roster::query()
                ->where('status', 'draft')
                ->distinct()
                ->count('date'),
            'teams' => $teams->map(function (Team $team) use ($periodRosters, $memberCounts) {
                $rows = $periodRosters->where('team_id', $team->id);
                $day = $rows->where('shift', 'day')->count();
                $night = $rows->where('shift', 'night')->count();

                return [
                    'name' => $team->name,
                    'memberCount' => (int) ($memberCounts[$team->id] ?? 0),
                    'dayShifts' => $day,
                    'nightShifts' => $night,
                    'totalShifts' => $day + $night,
                ];
            })->values()->all(),
            'monthlyTrend' => $this->rosterMonthTrend($from, $to, $periodRosters),
        ];
    }

    private function reports(Carbon $from, Carbon $to): array
    {
        $periodReports = Report::query()
            ->with('owner:id,name')
            ->whereBetween('submitted_at', [$from, $to])
            ->get();

        $ercoReports = $periodReports->where('report_type', 'erco');

        return [
            'pendingReview' => Report::query()->where('status', 'Submitted')->count(),
            'pendingApproval' => Report::query()->where('status', 'Reviewed')->count(),
            'submittedThisPeriod' => $periodReports->count(),
            'byType' => [
                'erco' => $periodReports->where('report_type', 'erco')->count(),
                'drill' => $periodReports->where('report_type', 'drill')->count(),
                'fitnessTest' => $periodReports->where('report_type', 'fitness-test')->count(),
            ],
            'ercoByIncidentType' => $this->topCounts(
                $ercoReports->map(fn (Report $report) => $this->payloadValue($report, 'incidentType', 'Unspecified')),
                'type',
            ),
            'byPersonnel' => $this->topCounts(
                $periodReports->map(fn (Report $report) => $report->owner?->name ?: 'Unassigned'),
                'name',
            ),
            'monthlyTrend' => $this->monthTrend($from, $to, $periodReports, 'submitted_at', 'count'),
        ];
    }

    private function monthTrend(Carbon $from, Carbon $to, Collection $records, string $dateKey, string $valueKey): array
    {
        return collect($this->monthBuckets($from, $to))->map(function (Carbon $month) use ($records, $dateKey, $valueKey) {
            $count = $records->filter(function ($record) use ($dateKey, $month) {
                $value = data_get($record, $dateKey);
                if (! $value) {
                    return false;
                }
                $date = $value instanceof Carbon ? $value : Carbon::parse($value);

                return $date->isSameMonth($month);
            })->count();

            return [
                'month' => $month->format('M'),
                $valueKey => $count,
            ];
        })->values()->all();
    }

    private function monthlyCountTrend(
        Builder $query,
        string $dateColumn,
        Carbon $from,
        Carbon $to,
        string $valueKey = 'count',
    ): array {
        $monthExpression = $this->monthBucketExpression($query, $dateColumn);
        $countsByMonth = (clone $query)
            ->whereNotNull($dateColumn)
            ->selectRaw("{$monthExpression} as month_bucket, COUNT(*) as aggregate_count")
            ->groupBy(DB::raw($monthExpression))
            ->orderBy(DB::raw($monthExpression))
            ->pluck('aggregate_count', 'month_bucket');

        return collect($this->monthBuckets($from, $to))->map(function (Carbon $month) use ($countsByMonth, $valueKey) {
            $monthKey = $month->format('Y-m');

            return [
                'month' => $month->format('M'),
                $valueKey => (int) ($countsByMonth->get($monthKey, 0)),
            ];
        })->values()->all();
    }

    private function rosterMonthTrend(Carbon $from, Carbon $to, Collection $records): array
    {
        return collect($this->monthBuckets($from, $to))->map(function (Carbon $month) use ($records) {
            $scheduledDays = $records->filter(function (Roster $record) use ($month) {
                return $record->date && $record->date->isSameMonth($month);
            })->map(fn (Roster $record) => $record->date->toDateString())->unique()->count();

            return [
                'month' => $month->format('M'),
                'scheduledDays' => $scheduledDays,
            ];
        })->values()->all();
    }

    private function monthBuckets(Carbon $from, Carbon $to): array
    {
        $months = [];
        $cursor = $from->copy()->startOfMonth();
        $end = $to->copy()->startOfMonth();

        while ($cursor->lte($end)) {
            $months[] = $cursor->copy();
            $cursor->addMonthNoOverflow();
        }

        return $months;
    }

    private function statusCounts(Collection $records, array $statuses): array
    {
        $counts = [];
        foreach ($statuses as $status) {
            $counts[lcfirst($status)] = $records->where('status', $status)->count();
        }

        return $counts;
    }

    private function statusCountsFromGroupedValues(Collection $counts, array $statuses): array
    {
        $results = [];

        foreach ($statuses as $status) {
            $results[lcfirst($status)] = (int) $counts->get($status, 0);
        }

        return $results;
    }

    private function countsByTeamForUsers(Collection $countsByUser): array
    {
        $userIds = $countsByUser->keys()->filter()->values();
        if ($userIds->isEmpty()) {
            return [];
        }

        $teamByUser = TeamMember::query()
            ->with('team:id,name')
            ->whereIn('user_id', $userIds)
            ->where(fn (Builder $query) => $this->activeMembership($query))
            ->get()
            ->groupBy('user_id')
            ->map(fn (Collection $members) => $members->first()?->team?->name)
            ->filter();

        $legacyTeamByUser = User::query()
            ->whereIn('id', $userIds)
            ->pluck('team', 'id');

        $countsByTeam = collect();
        foreach ($countsByUser as $userId => $count) {
            $teamName = $teamByUser[$userId] ?? ($legacyTeamByUser[$userId] ?: 'Unassigned');
            $label = trim((string) $teamName) ?: 'Unassigned';
            $countsByTeam[$label] = (int) ($countsByTeam[$label] ?? 0) + (int) $count;
        }

        return $countsByTeam
            ->sortDesc()
            ->take(5)
            ->map(fn (int $count, string $team) => ['team' => $team, 'count' => $count])
            ->values()
            ->all();
    }

    private function topCounts(Collection $values, string $labelKey): array
    {
        return $values
            ->map(fn ($value) => trim((string) $value) ?: 'Unspecified')
            ->countBy()
            ->sortDesc()
            ->take(5)
            ->map(fn (int $count, string $label) => [$labelKey => $label, 'count' => $count])
            ->values()
            ->all();
    }

    private function payloadValue(Report $report, string $key, string $fallback): string
    {
        $payload = $report->payload ?? [];
        $snakeKey = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $key));

        return trim((string) ($payload[$key] ?? $payload[$snakeKey] ?? $payload[strtolower($key)] ?? $fallback)) ?: $fallback;
    }

    private function groupCountBy(Builder $query, string $column): Collection
    {
        return (clone $query)
            ->whereNotNull($column)
            ->select($column)
            ->selectRaw('COUNT(*) as total')
            ->groupBy($column)
            ->pluck('total', $column);
    }

    private function statusStageCount(Collection $rows, string $status, ?array $workflowStages = null): int
    {
        return (int) $rows
            ->filter(function (object $row) use ($status, $workflowStages) {
                if ($row->status !== $status) {
                    return false;
                }

                if ($workflowStages === null) {
                    return true;
                }

                return in_array($row->workflow_stage, $workflowStages, true);
            })
            ->sum('total');
    }

    private function monthBucketExpression(Builder $query, string $dateColumn): string
    {
        $driver = $query->getConnection()->getDriverName();

        return match ($driver) {
            'sqlite' => "strftime('%Y-%m', {$dateColumn})",
            'pgsql' => "to_char({$dateColumn}, 'YYYY-MM')",
            'sqlsrv' => "FORMAT({$dateColumn}, 'yyyy-MM')",
            default => "DATE_FORMAT({$dateColumn}, '%Y-%m')",
        };
    }

    private function activeMembership(Builder $query): void
    {
        $today = now()->toDateString();

        $query
            ->where(function (Builder $query) use ($today) {
                $query->whereNull('started_at')->orWhereDate('started_at', '<=', $today);
            })
            ->where(function (Builder $query) use ($today) {
                $query->whereNull('ended_at')->orWhereDate('ended_at', '>=', $today);
            });
    }
}
