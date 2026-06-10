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

class DashboardStatsService
{
    public const PERIODS = ['this_month', 'last_month', '3m', '6m', 'ytd'];

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
            ->whereBetween('submitted_at', [$from, $to])
            ->get();

        $paidClaims = PayrollClaim::query()
            ->where('status', 'Paid')
            ->whereBetween('paid_at', [$from, $to])
            ->get();

        $approvedUnpaid = PayrollClaim::query()
            ->where('status', 'Approved')
            ->whereNull('paid_at')
            ->get();

        $activeAssignments = SalaryAssignment::query()
            ->whereRaw('LOWER(status) = ?', ['active'])
            ->count();

        $activeUsers = User::query()
            ->whereRaw('LOWER(COALESCE(status, ?)) = ?', ['active', 'active'])
            ->count();

        $assignedUserIds = SalaryAssignment::query()
            ->whereRaw('LOWER(status) = ?', ['active'])
            ->whereNotNull('employee_user_id')
            ->distinct()
            ->count('employee_user_id');

        return [
            'pendingApprovals' => PayrollClaim::query()->where('status', 'Pending')->count(),
            'approvedUnpaidCount' => $approvedUnpaid->count(),
            'approvedUnpaidTotalMyr' => round((float) $approvedUnpaid->sum('projected_net_payout'), 2),
            'paidThisMonthCount' => $paidClaims->count(),
            'paidThisMonthTotalMyr' => round((float) $paidClaims->sum('projected_net_payout'), 2),
            'byType' => [
                'salary' => $periodClaims->where('claim_type', 'salary')->count(),
                'expense' => $periodClaims->where('claim_type', 'expense')->count(),
                'other' => $periodClaims->whereNotIn('claim_type', ['salary', 'expense'])->count(),
            ],
            'byStatus' => [
                'pending' => $periodClaims->where('status', 'Pending')->count(),
                'pendingReview' => $periodClaims
                    ->where('status', 'Pending')
                    ->whereIn('workflow_stage', ['check', 'review'])
                    ->count(),
                'pendingApproval' => $periodClaims
                    ->where('status', 'Pending')
                    ->whereIn('workflow_stage', ['recommend', 'approve'])
                    ->count(),
                'approved' => $periodClaims->where('status', 'Approved')->count(),
                'paid' => $periodClaims->where('status', 'Paid')->count(),
                'rejected' => $periodClaims->where('status', 'Rejected')->count(),
                'cancelled' => $periodClaims->where('status', 'Cancelled')->count(),
            ],
            'monthlyTrend' => $this->monthTrend($from, $to, $periodClaims, 'submitted_at', 'count'),
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
            ->whereBetween('claim_date', [$from->toDateString(), $to->toDateString()])
            ->get();

        $approved = $periodRecords->where('status', 'Approved');

        return [
            'pendingApprovals' => OvertimeRecord::query()->where('status', 'Pending')->count(),
            'approvedHoursThisPeriod' => round(((int) $approved->sum('duration_minutes')) / 60, 1),
            'staffWithOpenRequests' => OvertimeRecord::query()
                ->where('status', 'Pending')
                ->distinct()
                ->count('user_id'),
            'submittedThisPeriod' => $periodRecords->count(),
            'approvedRequestsThisPeriod' => $approved->count(),
            'byType' => [
                'weekday' => $periodRecords->where('overtime_type', 'weekday')->count(),
                'weekend' => $periodRecords->where('overtime_type', 'weekend')->count(),
                'holiday' => $periodRecords->where('overtime_type', 'holiday')->count(),
            ],
            'byStatus' => $this->statusCounts($periodRecords, ['Pending', 'Approved', 'Rejected', 'Cancelled']),
            'byTeam' => $this->recordsByTeam($periodRecords, 'user_id'),
            'monthlyTrend' => $this->monthTrend($from, $to, $periodRecords, 'claim_date', 'count'),
        ];
    }

    private function leave(Carbon $from, Carbon $to): array
    {
        $periodLeaves = Leave::query()
            ->whereBetween('start_date', [$from->toDateString(), $to->toDateString()])
            ->get();

        $today = now()->toDateString();
        $approved = $periodLeaves->where('status', 'Approved');

        return [
            'pendingApprovals' => Leave::query()->where('status', 'Pending')->count(),
            'approvedDaysThisPeriod' => round((float) $approved->sum('days'), 1),
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
            'byTeam' => $this->recordsByTeam($periodLeaves, 'user_id'),
            'monthlyTrend' => $this->monthTrend($from, $to, $periodLeaves, 'start_date', 'count'),
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

    private function recordsByTeam(Collection $records, string $userIdKey): array
    {
        $userIds = $records->pluck($userIdKey)->filter()->unique()->values();
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

        $teamNames = $records->map(function ($record) use ($teamByUser, $legacyTeamByUser, $userIdKey) {
            $userId = data_get($record, $userIdKey);
            return $teamByUser[$userId] ?? ($legacyTeamByUser[$userId] ?: 'Unassigned');
        });

        return $this->topCounts($teamNames, 'team');
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
