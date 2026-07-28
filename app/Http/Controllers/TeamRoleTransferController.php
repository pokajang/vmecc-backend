<?php

namespace App\Http\Controllers;

use App\Models\Team;
use App\Models\User;
use App\Models\UserRoleAssignment;
use App\Services\AssignmentAuthorizationService;
use App\Services\AuditLogger;
use App\Services\RoleCatalog;
use App\Services\TeamRoleTransferService;
use App\Services\WorkflowNotificationService;
use App\Services\WorkflowRecipientResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class TeamRoleTransferController extends Controller
{
    public function __construct(
        private readonly TeamRoleTransferService $transfers,
        private readonly WorkflowRecipientResolver $recipients,
        private readonly WorkflowNotificationService $notifications,
        private readonly AssignmentAuthorizationService $authorization,
    ) {}

    public function options(Request $request): JsonResponse
    {
        $today = now()->toDateString();
        $permittedTeamIds = $this->authorization->permittedTeamIds(
            $request->user(),
            'roles.assign',
        );
        $rows = UserRoleAssignment::query()
            ->with(['user:id,name,status,deleted_at', 'role:id,name', 'team:id,name'])
            ->where('scope_type', RoleCatalog::SITE)
            ->whereNotNull('team_id')
            ->when(
                $permittedTeamIds !== null,
                fn ($query) => $query->whereIn('team_id', $permittedTeamIds->all()),
            )
            ->where(fn ($query) => $query->whereNull('start_date')->orWhereDate('start_date', '<=', $today))
            ->where(fn ($query) => $query->whereNull('end_date')->orWhereDate('end_date', '>=', $today))
            ->whereHas(
                'role',
                fn ($query) => $query->whereIn('name', [
                    'Assistant Incident Commander',
                    'Tactical Response Team',
                ]),
            )
            ->whereHas(
                'user',
                fn ($query) => $query
                    ->whereNull('deleted_at')
                    ->where(fn ($query) => $query
                        ->whereNull('status')
                        ->orWhereRaw("LOWER(TRIM(status)) = 'active'")),
            )
            ->orderBy('user_id')
            ->get()
            ->map(fn (UserRoleAssignment $assignment) => [
                'userId' => (int) $assignment->user_id,
                'userName' => (string) $assignment->user?->name,
                'assignmentId' => (int) $assignment->id,
                'role' => (string) $assignment->role?->name,
                'teamId' => (int) $assignment->team_id,
                'teamName' => (string) $assignment->team?->name,
            ])
            ->values();

        return response()->json([
            'data' => $rows,
            'meta' => ['effectiveDate' => $today],
        ]);
    }

    public function store(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'assignment_id' => ['required', 'integer', 'exists:user_role_assignments,id'],
            'target_team_id' => ['required', 'integer', 'exists:teams,id'],
            'effective_date' => ['required', 'date'],
            'handover_user_id' => ['nullable', 'integer', 'different:'.$id, 'exists:users,id'],
            'reason' => ['required', 'string', 'max:500'],
        ]);
        if ((string) $data['effective_date'] !== now()->toDateString()) {
            throw ValidationException::withMessages([
                'effective_date' => [
                    'Permanent transfers currently take effect immediately; use temporary duty coverage for scheduled substitutions.',
                ],
            ]);
        }

        $user = User::query()->findOrFail($id);
        $assignment = UserRoleAssignment::query()->findOrFail((int) $data['assignment_id']);
        $targetTeam = Team::query()->findOrFail((int) $data['target_team_id']);
        foreach (array_unique([(int) $assignment->team_id, (int) $targetTeam->id]) as $teamId) {
            if (! $this->authorization->hasPermission($request->user(), 'roles.assign', $teamId)) {
                abort(403, 'You are not authorized to transfer roles for one or more selected teams.');
            }
        }
        $handover = ! empty($data['handover_user_id'])
            ? User::query()->findOrFail((int) $data['handover_user_id'])
            : null;
        $actor = $request->user();
        $result = $this->transfers->transfer(
            $user,
            $assignment,
            $targetTeam,
            $actor,
            $handover,
            trim((string) $data['reason']),
        );
        $transfer = $result['transfer'];

        AuditLogger::log($request, 'team_role_transferred', $user, [
            'transfer_id' => $transfer->id,
            'from_team_id' => $transfer->from_team_id,
            'to_team_id' => $transfer->to_team_id,
            'role' => $transfer->role->name,
            'handover_count' => count($result['handovers']),
            'reason' => $transfer->reason,
        ]);

        try {
            $leaderIds = collect([
                ...$this->recipients->resolveRole('Incident Commander', $transfer->from_team_id)->pluck('userId')->all(),
                ...$this->recipients->resolveRole('Assistant Incident Commander', $transfer->from_team_id)->pluck('userId')->all(),
                ...$this->recipients->resolveRole('Incident Commander', $transfer->to_team_id)->pluck('userId')->all(),
                ...$this->recipients->resolveRole('Assistant Incident Commander', $transfer->to_team_id)->pluck('userId')->all(),
            ])->push($user->id)->map(fn ($userId) => (int) $userId)->unique()->values()->all();
            $actorData = [
                'userId' => $actor->id,
                'name' => $actor->name,
                'email' => $actor->email,
            ];
            $this->notifications->emit(
                module: 'team',
                eventType: 'team_transferred',
                recordType: 'team_role_transfer',
                recordId: $transfer->id,
                recordDisplayId: "{$transfer->fromTeam->name} to {$transfer->toTeam->name}",
                ownerUserId: $user->id,
                actor: $actorData,
                targetUserIds: $leaderIds,
                metadata: [
                    'fromTeamId' => $transfer->from_team_id,
                    'fromTeamName' => $transfer->fromTeam->name,
                    'toTeamId' => $transfer->to_team_id,
                    'toTeamName' => $transfer->toTeam->name,
                    'role' => $transfer->role->name,
                    'effectiveDate' => $transfer->effective_date->toDateString(),
                    'handoverCount' => count($result['handovers']),
                ],
            );
            foreach ($result['handovers'] as $handoverResult) {
                $report = $handoverResult['report'];
                $recipient = $handoverResult['recipient'];
                $this->notifications->emit(
                    module: 'report',
                    eventType: 'workflow_reassigned',
                    recordType: 'report',
                    recordId: $report->id,
                    recordDisplayId: $report->display_id,
                    ownerUserId: (int) $report->owner_user_id,
                    actor: $actorData,
                    targetUserIds: [(int) $recipient['userId']],
                    actionRequired: true,
                    metadata: [
                        'reportUid' => $report->report_uid,
                        'workflowStage' => $report->workflow_stage,
                        'nextActionRole' => $report->next_action_role,
                        'teamId' => $report->scope_team_id,
                        'routingReasonCode' => $report->routing_reason_code,
                        'teamRoleTransferId' => $transfer->id,
                        'status' => $report->status,
                        'reportType' => $report->report_type,
                        'detailRouteKey' => $report->report_uid,
                    ],
                    excludeOwner: true,
                );
            }
        } catch (\Throwable $exception) {
            Log::error('Team role transfer committed, but workflow notification delivery failed.', [
                'transfer_id' => $transfer->id,
                'error' => $exception->getMessage(),
            ]);
        }

        return response()->json([
            'data' => [
                'id' => $transfer->id,
                'userId' => $transfer->user_id,
                'role' => $transfer->role->name,
                'fromTeam' => [
                    'id' => $transfer->fromTeam->id,
                    'name' => $transfer->fromTeam->name,
                ],
                'toTeam' => [
                    'id' => $transfer->toTeam->id,
                    'name' => $transfer->toTeam->name,
                ],
                'effectiveDate' => $transfer->effective_date->toDateString(),
                'handoverCount' => count($result['handovers']),
                'reason' => $transfer->reason,
            ],
        ], 201);
    }
}
