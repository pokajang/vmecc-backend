<?php

namespace App\Http\Controllers;

use App\Models\Leave;
use App\Models\LeaveAssignment;
use App\Models\OvertimeRecord;
use App\Models\PayrollClaim;
use App\Models\Report;
use App\Models\Roster;
use App\Models\Team;
use App\Models\TeamMember;
use App\Services\DashboardStatsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DashboardController extends Controller
{
    public function payrollStats(Request $request, DashboardStatsService $statsService): JsonResponse
    {
        return $this->moduleStats($request, 'payroll', $statsService);
    }

    public function overtimeStats(Request $request, DashboardStatsService $statsService): JsonResponse
    {
        return $this->moduleStats($request, 'overtime', $statsService);
    }

    public function leaveStats(Request $request, DashboardStatsService $statsService): JsonResponse
    {
        return $this->moduleStats($request, 'leave', $statsService);
    }

    public function rosterStats(Request $request, DashboardStatsService $statsService): JsonResponse
    {
        return $this->moduleStats($request, 'roster', $statsService);
    }

    public function reportStats(Request $request, DashboardStatsService $statsService): JsonResponse
    {
        return $this->moduleStats($request, 'reports', $statsService);
    }

    private function moduleStats(
        Request $request,
        string $module,
        DashboardStatsService $statsService,
    ): JsonResponse {
        $data = $request->validate([
            'period' => ['nullable', 'string', Rule::in(DashboardStatsService::PERIODS)],
        ]);

        return response()->json(
            $statsService->stats($module, (string) ($data['period'] ?? 'this_month')),
        );
    }

    public function me(Request $request): JsonResponse
    {
        $user   = $request->user();
        $userId = $user->id;
        $now    = now();
        $year   = $now->year;
        $month  = $now->month;

        // ── Leave ─────────────────────────────────────────────────────────────
        $pendingLeave = Leave::where('user_id', $userId)
            ->whereIn('status', ['Submitted', 'Pending'])
            ->count();

        $approvedLeaveDaysThisMonth = (float) Leave::where('user_id', $userId)
            ->where('status', 'Approved')
            ->whereYear('start_date', $year)
            ->whereMonth('start_date', $month)
            ->sum('days');

        $leaveBalance = LeaveAssignment::where('user_id', $userId)
            ->where('year', $year)
            ->get(['leave_type', 'entitlement', 'used', 'pending'])
            ->map(fn($a) => [
                'leaveType'   => $a->leave_type,
                'entitlement' => (float) $a->entitlement,
                'used'        => (float) $a->used,
                'pending'     => (float) $a->pending,
                'remaining'   => (float) max(0, $a->entitlement - $a->used - $a->pending),
            ])
            ->values();

        // ── Overtime ──────────────────────────────────────────────────────────
        $pendingOt = OvertimeRecord::where('user_id', $userId)
            ->whereIn('status', ['Submitted', 'Pending'])
            ->count();

        $approvedOtMinutes = (int) OvertimeRecord::where('user_id', $userId)
            ->where('status', 'Approved')
            ->whereYear('claim_date', $year)
            ->whereMonth('claim_date', $month)
            ->sum('duration_minutes');

        // ── Payroll ───────────────────────────────────────────────────────────
        $pendingClaims = PayrollClaim::where('user_id', $userId)
            ->where('status', 'Pending')
            ->count();

        $approvedUnpaid = PayrollClaim::where('user_id', $userId)
            ->where('status', 'Approved')
            ->whereNull('paid_at')
            ->selectRaw('COUNT(*) as cnt, COALESCE(SUM(projected_net_payout), 0) as total')
            ->first();

        // ── Reports (non-inspection) ──────────────────────────────────────────
        $pendingReports = Report::where('owner_user_id', $userId)
            ->where('report_type', '!=', 'inspection')
            ->whereIn('status', ['Submitted', 'Reviewed'])
            ->count();

        $reportDrafts = Report::where('owner_user_id', $userId)
            ->where('report_type', '!=', 'inspection')
            ->where('status', 'Draft')
            ->count();

        // ── Inspection ────────────────────────────────────────────────────────
        $pendingInspections = Report::where('owner_user_id', $userId)
            ->where('report_type', 'inspection')
            ->whereIn('status', ['Submitted', 'Reviewed'])
            ->count();

        $inspectionDrafts = Report::where('owner_user_id', $userId)
            ->where('report_type', 'inspection')
            ->where('status', 'Draft')
            ->count();

        // ── Roster ────────────────────────────────────────────────────────────
        $membership = TeamMember::where('user_id', $userId)->first();
        $teamName   = null;
        $nextShift  = null;

        if ($membership) {
            $team     = Team::find($membership->team_id);
            $teamName = $team?->name;

            $next = Roster::where('team_id', $membership->team_id)
                ->where('date', '>=', $now->toDateString())
                ->where('status', 'Published')
                ->orderBy('date')
                ->first();

            if ($next) {
                $nextShift = [
                    'date'  => $next->date->toDateString(),
                    'shift' => $next->shift,
                ];
            }
        }

        return response()->json([
            'leave' => [
                'pending'                  => $pendingLeave,
                'approvedDaysThisMonth'    => $approvedLeaveDaysThisMonth,
                'balance'                  => $leaveBalance,
            ],
            'overtime' => [
                'pending'                  => $pendingOt,
                'approvedHoursThisMonth'   => round($approvedOtMinutes / 60, 1),
            ],
            'payroll' => [
                'pending'                  => $pendingClaims,
                'approvedUnpaidCount'      => (int) ($approvedUnpaid->cnt ?? 0),
                'approvedUnpaidTotalMyr'   => (float) ($approvedUnpaid->total ?? 0),
            ],
            'reports' => [
                'pending'                  => $pendingReports,
                'drafts'                   => $reportDrafts,
            ],
            'inspection' => [
                'pending'                  => $pendingInspections,
                'drafts'                   => $inspectionDrafts,
            ],
            'roster' => [
                'teamName'                 => $teamName,
                'nextShift'                => $nextShift,
            ],
        ]);
    }
}
