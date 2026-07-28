<?php

namespace App\Services;

use App\Models\DutyCoverageAssignment;
use App\Models\Leave;
use App\Models\OvertimeRecord;
use App\Models\PayrollClaim;
use App\Models\Report;
use App\Models\TeamMember;
use App\Models\TeamRoleTransfer;
use App\Models\User;
use App\Models\UserRoleAssignment;
use Illuminate\Support\Collection;

class WorkflowRoutingAuditService
{
    public function __construct(
        private readonly WorkflowRecipientResolver $recipients,
        private readonly AssignmentAuthorizationService $authorization,
    ) {}

    public function issues(): Collection
    {
        return collect()
            ->concat($this->teamMemberIssues())
            ->concat($this->roleAssignmentIssues())
            ->concat($this->reportRoutingIssues())
            ->concat($this->roleWorkflowIssues())
            ->concat($this->salaryPaymentIssues())
            ->concat($this->coverageOverlapIssues())
            ->concat($this->transferHandoverIssues())
            ->values();
    }

    private function roleAssignmentIssues(): Collection
    {
        $today = now()->toDateString();
        $issues = collect();
        $assignments = UserRoleAssignment::query()
            ->with(['role:id,name', 'team:id,name', 'user:id,name'])
            ->where('scope_type', RoleCatalog::SITE)
            ->whereNotNull('team_id')
            ->where(fn ($query) => $query->whereNull('start_date')->orWhereDate('start_date', '<=', $today))
            ->where(fn ($query) => $query->whereNull('end_date')->orWhereDate('end_date', '>=', $today))
            ->get();

        foreach ($assignments as $assignment) {
            $hasMembership = TeamMember::query()
                ->where('user_id', $assignment->user_id)
                ->where('team_id', $assignment->team_id)
                ->where(fn ($query) => $query->whereNull('started_at')->orWhereDate('started_at', '<=', $today))
                ->where(fn ($query) => $query->whereNull('ended_at')->orWhereDate('ended_at', '>=', $today))
                ->whereRaw('LOWER(TRIM(role)) = ?', [strtolower((string) $assignment->role?->name)])
                ->exists();
            if (! $hasMembership) {
                $issues->push([
                    'category' => 'role_assignment',
                    'record_id' => (string) $assignment->id,
                    'display_id' => (string) ($assignment->user?->name ?? $assignment->user_id),
                    'team' => (string) ($assignment->team?->name ?? $assignment->team_id),
                    'role' => (string) $assignment->role?->name,
                    'reason' => 'missing_active_team_membership',
                ]);
            }
        }

        $assignments
            ->groupBy('user_id')
            ->filter(fn (Collection $rows) => $rows->pluck('team_id')->unique()->count() > 1)
            ->each(function (Collection $rows, $userId) use ($issues): void {
                $issues->push([
                    'category' => 'role_assignment',
                    'record_id' => (string) $userId,
                    'display_id' => (string) ($rows->first()?->user?->name ?? $userId),
                    'team' => $rows->pluck('team.name')->filter()->join(', '),
                    'role' => $rows->pluck('role.name')->filter()->unique()->join(', '),
                    'reason' => 'multiple_active_permanent_teams',
                ]);
            });

        return $issues;
    }

    private function teamMemberIssues(): Collection
    {
        $today = now()->toDateString();
        $issues = collect();

        TeamMember::query()
            ->with(['team:id,name', 'user:id,name,status,deleted_at'])
            ->where(fn ($query) => $query->whereNull('started_at')->orWhereDate('started_at', '<=', $today))
            ->where(fn ($query) => $query->whereNull('ended_at')->orWhereDate('ended_at', '>=', $today))
            ->orderBy('id')
            ->each(function (TeamMember $member) use ($issues, $today): void {
                $role = RoleCatalog::canonicalRoleName($member->role);
                if ($role === null || ! RoleCatalog::isScopedRole($role)) {
                    return;
                }

                $reason = null;
                if (! $member->user_id) {
                    $reason = 'unlinked_operational_member';
                } elseif (
                    ! $member->user
                    || $member->user->deleted_at !== null
                    || (
                        $member->user->status !== null
                        && strtolower(trim((string) $member->user->status)) !== 'active'
                    )
                ) {
                    $reason = 'inactive_operational_member';
                } elseif (! UserRoleAssignment::query()
                    ->where('user_id', $member->user_id)
                    ->where('team_id', $member->team_id)
                    ->where(fn ($query) => $query->whereNull('start_date')->orWhereDate('start_date', '<=', $today))
                    ->where(fn ($query) => $query->whereNull('end_date')->orWhereDate('end_date', '>=', $today))
                    ->whereHas(
                        'role',
                        fn ($query) => $query->whereRaw(
                            'LOWER(TRIM(name)) = ?',
                            [strtolower($role)],
                        ),
                    )
                    ->exists()
                ) {
                    $reason = 'missing_team_role_assignment';
                }

                if ($reason !== null) {
                    $issues->push([
                        'category' => 'team_member',
                        'record_id' => (string) $member->id,
                        'display_id' => (string) ($member->name ?: $member->user?->name),
                        'team' => (string) ($member->team?->name ?? $member->team_id),
                        'role' => $role,
                        'reason' => $reason,
                    ]);
                }
            });

        return $issues;
    }

    private function reportRoutingIssues(): Collection
    {
        $issues = collect();

        Report::query()
            ->whereIn('status', ['Submitted', 'Reviewed'])
            ->where(fn ($query) => $query->whereNull('workflow_stage')->orWhere('workflow_stage', '!=', 'done'))
            ->orderBy('id')
            ->each(function (Report $report) use ($issues): void {
                $role = trim((string) $report->next_action_role);
                $teamId = $this->currentTeamScope($report);
                $assignedUserId = (int) ($report->next_action_user_id ?? 0);
                $coverageId = (int) ($report->next_action_duty_coverage_assignment_id ?? 0);
                $reason = null;

                if ($role === '') {
                    $reason = 'no_action_role';
                } elseif ($assignedUserId > 0 && ! $this->activeUserExists($assignedUserId)) {
                    $reason = 'assigned_user_inactive';
                } elseif ($coverageId > 0 && ! DutyCoverageAssignment::query()
                    ->whereKey($coverageId)
                    ->where('user_id', $assignedUserId)
                    ->effectiveAt(now())
                    ->exists()
                ) {
                    $reason = 'assigned_coverage_inactive';
                } elseif ($assignedUserId > 0 && ! $this->recipients
                    ->resolveRole($role, $teamId)
                    ->contains(fn (array $row) => (int) $row['userId'] === $assignedUserId)
                ) {
                    $reason = 'assigned_user_no_longer_eligible';
                } elseif ($assignedUserId === 0 && $this->recipients->resolveRole($role, $teamId)->isEmpty()) {
                    $reason = 'no_eligible_recipient';
                }

                if ($reason !== null) {
                    $issues->push([
                        'category' => 'report',
                        'record_id' => (string) $report->id,
                        'display_id' => (string) $report->display_id,
                        'team' => $teamId ? (string) $teamId : '',
                        'role' => $role,
                        'reason' => $reason,
                    ]);
                }
            });

        return $issues;
    }

    private function coverageOverlapIssues(): Collection
    {
        $issues = collect();

        DutyCoverageAssignment::query()
            ->whereNull('cancelled_at')
            ->orderBy('user_id')
            ->orderBy('effective_from')
            ->get()
            ->groupBy('user_id')
            ->each(function (Collection $assignments, $userId) use ($issues): void {
                $previous = null;
                foreach ($assignments as $assignment) {
                    if (
                        $previous instanceof DutyCoverageAssignment
                        && $assignment->effective_from->lt($previous->effective_until)
                    ) {
                        $issues->push([
                            'category' => 'duty_coverage',
                            'record_id' => (string) $assignment->id,
                            'display_id' => (string) $userId,
                            'team' => (string) $assignment->acting_team_id,
                            'role' => (string) $assignment->acting_role_id,
                            'reason' => 'overlapping_coverage',
                        ]);
                    }

                    if (
                        ! $previous instanceof DutyCoverageAssignment
                        || $assignment->effective_until->gt($previous->effective_until)
                    ) {
                        $previous = $assignment;
                    }
                }
            });

        return $issues;
    }

    private function roleWorkflowIssues(): Collection
    {
        $issues = collect();
        $sources = [
            'overtime' => OvertimeRecord::query()
                ->where('status', 'Pending')
                ->whereNotIn('workflow_stage', ['done', 'correction'])
                ->get(),
            'leave' => Leave::query()
                ->where('status', 'Pending')
                ->whereNotIn('workflow_stage', ['done', 'correction'])
                ->get(),
            'payroll_claim' => PayrollClaim::query()
                ->where('status', 'Pending')
                ->whereNotIn('workflow_stage', ['done'])
                ->get(),
        ];

        foreach ($sources as $category => $records) {
            foreach ($records as $record) {
                $role = trim((string) $record->next_action_role);
                $workflowTeamId = $record->getAttribute('workflow_team_id');
                $teamId = $workflowTeamId
                    ? (int) $workflowTeamId
                    : null;
                $scope = RoleCatalog::scopeForRole($role);
                $reason = null;

                if ($role === '') {
                    $reason = 'no_action_role';
                } elseif (in_array($scope, [RoleCatalog::SITE, RoleCatalog::CLIENT_SITE], true) && ! $teamId) {
                    $reason = 'missing_workflow_team';
                } elseif ($this->recipients->resolveForWorkflowRole(
                    $role,
                    $teamId,
                    now(),
                    (int) $record->user_id,
                )->isEmpty()) {
                    $reason = 'no_eligible_recipient';
                }

                if ($reason !== null) {
                    $issues->push([
                        'category' => $category,
                        'record_id' => (string) $record->id,
                        'display_id' => (string) $record->display_id,
                        'team' => $teamId ? (string) $teamId : '',
                        'role' => $role,
                        'reason' => $reason,
                    ]);
                }
            }
        }

        return $issues;
    }

    private function salaryPaymentIssues(): Collection
    {
        if (! PayrollClaim::query()
            ->where('claim_type', 'salary')
            ->where('status', 'Approved')
            ->whereNull('paid_at')
            ->exists()) {
            return collect();
        }

        $hasPayer = User::query()
            ->whereNull('deleted_at')
            ->whereRaw("LOWER(TRIM(COALESCE(status, ''))) = 'active'")
            ->get()
            ->contains(fn (User $user) => $this->authorization->hasPermission($user, 'staff.salary.pay'));

        if ($hasPayer) {
            return collect();
        }

        return collect([[
            'category' => 'payroll_claim',
            'record_id' => '',
            'display_id' => '',
            'team' => '',
            'role' => 'staff.salary.pay',
            'reason' => 'no_active_salary_payer',
        ]]);
    }

    private function transferHandoverIssues(): Collection
    {
        return TeamRoleTransfer::query()
            ->with(['user:id,name', 'fromTeam:id,name', 'role:id,name'])
            ->withCount('routingEvents')
            ->where('pending_handover_count', '>', 0)
            ->get()
            ->filter(fn (TeamRoleTransfer $transfer) => (
                $transfer->pending_handover_count
                !== (int) $transfer->routing_events_count
            ))
            ->map(fn (TeamRoleTransfer $transfer) => [
                'category' => 'team_role_transfer',
                'record_id' => (string) $transfer->id,
                'display_id' => (string) ($transfer->user?->name ?? $transfer->user_id),
                'team' => (string) ($transfer->fromTeam?->name ?? $transfer->from_team_id),
                'role' => (string) $transfer->role?->name,
                'reason' => 'incomplete_report_handover_audit',
            ])
            ->values();
    }

    private function currentTeamScope(Report $report): ?int
    {
        if (! in_array((string) $report->workflow_stage, ['review', 'approve'], true)
            || ! $report->scope_team_id
            || ! RoleCatalog::isScopedRole($report->next_action_role)) {
            return null;
        }

        $snapshot = is_array($report->workflow_snapshot) ? $report->workflow_snapshot : [];
        $options = is_array($snapshot['options'] ?? null) ? $snapshot['options'] : [];
        if (($options['useTeamScopedAic'] ?? true) === false) {
            return null;
        }

        return (int) $report->scope_team_id;
    }

    private function activeUserExists(int $userId): bool
    {
        return User::query()
            ->whereKey($userId)
            ->whereNull('deleted_at')
            ->where(fn ($query) => $query->whereNull('status')->orWhereRaw("LOWER(TRIM(status)) = 'active'"))
            ->exists();
    }
}
