<?php

namespace App\Services;

use App\Models\DutyCoverageAssignment;
use App\Models\Report;
use App\Models\ReportRoutingEvent;
use App\Models\Team;
use App\Models\TeamRoleTransfer;
use App\Models\User;
use App\Models\UserRoleAssignment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TeamRoleTransferService
{
    private const TRANSFERABLE_ROLES = [
        'Assistant Incident Commander',
        'Tactical Response Team',
    ];

    public function __construct(
        private readonly TeamMemberSyncService $teamMemberSync,
        private readonly WorkflowRecipientResolver $recipientResolver,
    ) {}

    public function transfer(
        User $user,
        UserRoleAssignment $assignment,
        Team $targetTeam,
        User $actor,
        ?User $requestedHandover,
        string $reason,
    ): array {
        return DB::transaction(function () use (
            $user,
            $assignment,
            $targetTeam,
            $actor,
            $requestedHandover,
            $reason,
        ) {
            $locked = UserRoleAssignment::query()
                ->with(['role', 'team'])
                ->lockForUpdate()
                ->findOrFail($assignment->id);
            $this->validateTransfer($user, $locked, $targetTeam);

            $today = now()->toDateString();
            $sourceTeam = $locked->team;
            $role = (string) $locked->role->name;
            $pending = Report::query()
                ->where('scope_team_id', $sourceTeam->id)
                ->where('next_action_user_id', $user->id)
                ->whereIn('status', ['Submitted', 'Reviewed'])
                ->whereIn('workflow_stage', ['review', 'approve'])
                ->lockForUpdate()
                ->get();

            $locked->update([
                'end_date' => Carbon::parse($today)->subDay()->toDateString(),
                'is_primary' => false,
            ]);
            $newAssignment = UserRoleAssignment::query()->create([
                'user_id' => $user->id,
                'role_id' => $locked->role_id,
                'scope_type' => RoleCatalog::SITE,
                'team_id' => $targetTeam->id,
                'start_date' => $today,
                'end_date' => null,
                'is_primary' => true,
            ]);
            $this->teamMemberSync->syncForUser($user, false, false);

            $transfer = TeamRoleTransfer::query()->create([
                'user_id' => $user->id,
                'role_id' => $locked->role_id,
                'from_team_id' => $sourceTeam->id,
                'to_team_id' => $targetTeam->id,
                'from_assignment_id' => $locked->id,
                'to_assignment_id' => $newAssignment->id,
                'handover_user_id' => $requestedHandover?->id,
                'transferred_by_user_id' => $actor->id,
                'effective_date' => $today,
                'pending_handover_count' => $pending->count(),
                'reason' => $reason,
            ]);

            $handovers = $pending->map(function (Report $report) use (
                $user,
                $sourceTeam,
                $actor,
                $requestedHandover,
                $transfer,
            ) {
                $recipient = $this->resolveHandoverRecipient(
                    $report,
                    $sourceTeam->id,
                    $user->id,
                    $requestedHandover?->id,
                );
                if ($recipient === null) {
                    throw ValidationException::withMessages([
                        'handover_user_id' => [
                            "Transfer would strand {$report->display_id}; assign a qualified replacement first.",
                        ],
                    ]);
                }

                $previousUserId = (int) $report->next_action_user_id;
                $report->update([
                    'next_action_role' => $recipient['role'],
                    'next_action_user_id' => $recipient['userId'],
                    'next_action_duty_coverage_assignment_id' => $recipient['dutyCoverageAssignmentId'],
                    'routing_reason_code' => $recipient['routingReasonCode'],
                ]);
                ReportRoutingEvent::query()->create([
                    'report_id' => $report->id,
                    'team_role_transfer_id' => $transfer->id,
                    'event_type' => 'team_transfer_handover',
                    'from_user_id' => $previousUserId,
                    'to_user_id' => $recipient['userId'],
                    'team_id' => $sourceTeam->id,
                    'required_role' => $recipient['role'],
                    'created_by_user_id' => $actor->id,
                    'metadata' => [
                        'routingReasonCode' => $recipient['routingReasonCode'],
                        'dutyCoverageAssignmentId' => $recipient['dutyCoverageAssignmentId'],
                    ],
                ]);

                return [
                    'report' => $report->fresh(),
                    'recipient' => $recipient,
                ];
            })->values()->all();

            return [
                'transfer' => $transfer->fresh(['user', 'role', 'fromTeam', 'toTeam']),
                'handovers' => $handovers,
            ];
        }, 3);
    }

    private function validateTransfer(
        User $user,
        UserRoleAssignment $assignment,
        Team $targetTeam,
    ): void {
        $today = now()->toDateString();
        $role = RoleCatalog::canonicalRoleName($assignment->role?->name);
        if ((int) $assignment->user_id !== (int) $user->id) {
            throw ValidationException::withMessages([
                'assignment_id' => ['The selected role assignment does not belong to this user.'],
            ]);
        }
        if (
            ! in_array($role, self::TRANSFERABLE_ROLES, true)
            || $assignment->scope_type !== RoleCatalog::SITE
            || ! $assignment->team_id
        ) {
            throw ValidationException::withMessages([
                'assignment_id' => ['Only active team-scoped AIC and TRT assignments can be transferred.'],
            ]);
        }
        if (
            $user->status !== null
            && strtolower(trim((string) $user->status)) !== 'active'
        ) {
            throw ValidationException::withMessages([
                'assignment_id' => ['Only an active linked user can be transferred.'],
            ]);
        }
        if ((int) $assignment->team_id === (int) $targetTeam->id) {
            throw ValidationException::withMessages([
                'target_team_id' => ['The target team must differ from the current team.'],
            ]);
        }
        if (
            ($assignment->start_date && $assignment->start_date->toDateString() > $today)
            || ($assignment->end_date && $assignment->end_date->toDateString() < $today)
        ) {
            throw ValidationException::withMessages([
                'assignment_id' => ['The selected role assignment is not currently active.'],
            ]);
        }
        if ($assignment->start_date?->toDateString() === $today) {
            throw ValidationException::withMessages([
                'assignment_id' => [
                    'An assignment created today cannot be transferred again on the same day.',
                ],
            ]);
        }
        $hasOtherActiveTeam = UserRoleAssignment::query()
            ->where('user_id', $user->id)
            ->whereKeyNot($assignment->id)
            ->whereNotNull('team_id')
            ->where(fn ($query) => $query->whereNull('start_date')->orWhereDate('start_date', '<=', $today))
            ->where(fn ($query) => $query->whereNull('end_date')->orWhereDate('end_date', '>=', $today))
            ->exists();
        if ($hasOtherActiveTeam) {
            throw ValidationException::withMessages([
                'assignment_id' => ['Resolve the user’s other active team assignment before transferring.'],
            ]);
        }
        if (DutyCoverageAssignment::query()->where('user_id', $user->id)->effectiveAt(now())->exists()) {
            throw ValidationException::withMessages([
                'assignment_id' => ['Cancel or complete active temporary duty coverage before a permanent transfer.'],
            ]);
        }
    }

    private function resolveHandoverRecipient(
        Report $report,
        int $sourceTeamId,
        int $outgoingUserId,
        ?int $requestedUserId,
    ): ?array {
        $role = trim((string) $report->next_action_role);
        $teamId = RoleCatalog::isScopedRole($role) ? $sourceTeamId : null;
        $candidates = $this->recipientResolver
            ->resolveRole($role, $teamId, excludeUserId: (int) $report->owner_user_id)
            ->reject(fn (array $candidate) => (int) $candidate['userId'] === $outgoingUserId);
        $candidate = $requestedUserId
            ? $candidates->first(fn (array $row) => (int) $row['userId'] === $requestedUserId)
            : $candidates->first();

        if (! $candidate && $report->workflow_stage === 'review') {
            $snapshot = is_array($report->workflow_snapshot) ? $report->workflow_snapshot : [];
            $fallbackRole = trim((string) ($snapshot['fallbackReviewRole'] ?? 'Incident Commander'));
            $fallbackTeamId = RoleCatalog::isScopedRole($fallbackRole) ? $sourceTeamId : null;
            $fallback = $this->recipientResolver
                ->resolveRole(
                    $fallbackRole,
                    $fallbackTeamId,
                    excludeUserId: (int) $report->owner_user_id,
                )
                ->reject(fn (array $row) => (int) $row['userId'] === $outgoingUserId);
            $candidate = $requestedUserId
                ? $fallback->first(fn (array $row) => (int) $row['userId'] === $requestedUserId)
                : $fallback->first();
            if ($candidate) {
                $role = $fallbackRole;
            }
        }

        if (! is_array($candidate)) {
            return null;
        }

        return [
            ...$candidate,
            'role' => $role,
            'routingReasonCode' => ($candidate['source'] ?? '') === 'temporary_coverage'
                ? 'team_temporary_coverage'
                : ($teamId === null || $role !== trim((string) $report->next_action_role)
                    ? 'fallback_role_assignment'
                    : 'team_role_assignment'),
        ];
    }
}
