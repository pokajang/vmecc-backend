<?php

namespace App\Http\Controllers;

use App\Models\Leave;
use App\Services\AssignmentAuthorizationService;
use App\Services\AuditLogger;
use App\Services\LeaveNotificationService;
use App\Services\LeaveRosterImpactNotificationService;
use App\Services\LeaveWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LeaveWorkflowController extends Controller
{
    public function __construct(
        private readonly LeaveWorkflowService           $workflowService,
        private readonly LeaveNotificationService       $notificationService,
        private readonly LeaveRosterImpactNotificationService $rosterImpactNotifications,
        private readonly AssignmentAuthorizationService $authorizationService,
    ) {}

    // ── Review ────────────────────────────────────────────────────────────────

    public function review(Request $request, int $userId, int $leaveId): JsonResponse
    {
        return $this->handleVersionedAction($request, $userId, $leaveId, 'review');
    }

    // ── Recommend ─────────────────────────────────────────────────────────────

    public function recommend(Request $request, int $userId, int $leaveId): JsonResponse
    {
        return $this->handleVersionedAction($request, $userId, $leaveId, 'recommend');
    }

    // ── Approve ───────────────────────────────────────────────────────────────

    public function approve(Request $request, int $userId, int $leaveId): JsonResponse
    {
        return $this->handleVersionedAction($request, $userId, $leaveId, 'approve');
    }

    // ── Reject ────────────────────────────────────────────────────────────────

    public function reject(Request $request, int $userId, int $leaveId): JsonResponse
    {
        return $this->handleVersionedAction($request, $userId, $leaveId, 'reject');
    }

    // ── Admin cancel (staff override) ─────────────────────────────────────────

    public function adminCancel(Request $request, int $userId, int $leaveId): JsonResponse
    {
        return $this->handleVersionedAction($request, $userId, $leaveId, 'cancel');
    }

    public function requestCorrection(Request $request, int $userId, int $leaveId): JsonResponse
    {
        return $this->handleVersionedAction($request, $userId, $leaveId, 'request_correction');
    }

    private function handleVersionedAction(Request $request, int $userId, int $leaveId, string $action): JsonResponse
    {
        $actor = $request->user();
        $payload = $request->validate([
            'remarks' => ['nullable', 'string', 'max:1000'],
            'declaration_checked' => ['nullable', 'boolean'],
            'expected_version' => ['nullable', 'integer', 'min:1'],
        ]);
        if (in_array($action, ['reject', 'request_correction'], true) && trim((string) ($payload['remarks'] ?? '')) === '') {
            throw ValidationException::withMessages([
                'remarks' => ['Remarks are required for this action.'],
            ]);
        }
        if (in_array($action, ['review', 'recommend', 'approve'], true) && ! ($payload['declaration_checked'] ?? false)) {
            throw ValidationException::withMessages([
                'declaration_checked' => ['Confirmation is required for this action.'],
            ]);
        }

        [$leave, $previousStatus] = DB::transaction(function () use ($actor, $userId, $leaveId, $action, $payload) {
            $leave = Leave::query()->where('user_id', $userId)->with('attachment')->lockForUpdate()->findOrFail($leaveId);
            $this->assertVersionedActionAllowed($leave, $action, $actor);
            $this->assertExpectedVersion($leave, $payload['expected_version'] ?? null);
            $previousStatus = $leave->status;
            $updates = $this->workflowService->advanceWorkflow(
                $leave,
                $action,
                (int) $actor->id,
                (string) $actor->name,
                $payload['remarks'] ?? null,
            );
            $updates['version'] = ((int) $leave->version) + 1;
            $leave->update($updates);
            $fresh = $leave->fresh(['attachment']);

            if ($previousStatus === 'Pending' && $fresh->status === 'Approved') {
                $this->workflowService->onLeaveApproved($fresh);
            } elseif ($previousStatus === 'Pending' && in_array($fresh->status, ['Rejected', 'Cancelled'], true)) {
                $this->workflowService->onLeaveDeclined($fresh);
            } elseif ($previousStatus === 'Approved' && $fresh->status === 'Cancelled') {
                $this->workflowService->onApprovedLeaveCancelled($fresh);
            }

            return [$fresh, $previousStatus];
        });

        $eventType = $this->actionToEventType($action);
        try {
            $this->notificationService->emit(
                $eventType,
                $leave,
                ['userId' => $actor->id, 'name' => $actor->name, 'email' => $actor->email],
                $leave->next_action_role ? [$leave->next_action_role] : [],
                [$userId],
                $leave->next_action_role !== null,
                $payload['remarks'] ?? null,
            );
        } catch (\Throwable $exception) {
            report($exception);
        }

        if ($previousStatus !== 'Approved' && $leave->status === 'Approved') {
            $this->emitRosterImpactNotification($leave, $actor, 'approved');
        } elseif ($previousStatus === 'Approved' && $leave->status === 'Cancelled') {
            $this->emitRosterImpactNotification($leave, $actor, 'cancelled');
        }

        AuditLogger::log($request, "leave_{$action}", $actor, [
            'leave_id' => $leave->id,
            'display_id' => $leave->display_id,
            'owner_id' => $userId,
            'previous_status' => $previousStatus,
        ]);

        return response()->json(['data' => LeaveController::formatLeave($leave)]);
    }

    private function emitRosterImpactNotification(Leave $leave, $actor, string $change): void
    {
        try {
            $this->rosterImpactNotifications->emit($leave, [
                'userId' => $actor->id,
                'name' => $actor->name,
                'email' => $actor->email,
            ], $change);
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    // ── Core handler ──────────────────────────────────────────────────────────

    private function handleAction(Request $request, int $userId, int $leaveId, string $action): JsonResponse
    {
        $actor = $request->user();

        $data = $request->validate([
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        $leave = Leave::where('user_id', $userId)->with('attachment')->findOrFail($leaveId);

        $this->assertActionAllowed($leave, $action, $actor);

        $previousStatus = $leave->status;

        $updates = $this->workflowService->advanceWorkflow(
            $leave,
            $action,
            $actor->id,
            $actor->name,
            $data['remarks'] ?? null,
        );

        DB::transaction(function () use ($leave, $updates) {
            $leave->update($updates);
        });

        // Balance adjustments
        $newStatus = $leave->fresh()->status;
        if ($previousStatus === 'Pending' && $newStatus === 'Approved') {
            $this->workflowService->onLeaveApproved($leave->fresh());
        } elseif ($previousStatus === 'Pending' && in_array($newStatus, ['Rejected', 'Cancelled'], true)) {
            $this->workflowService->onLeaveDeclined($leave->fresh());
        } elseif ($previousStatus === 'Approved' && $newStatus === 'Cancelled') {
            $this->workflowService->onApprovedLeaveCancelled($leave->fresh());
        }

        // Notifications
        $freshLeave = $leave->fresh(['attachment']);
        $eventType  = $this->actionToEventType($action);
        $snapshot   = $freshLeave->workflow_snapshot ?? [];

        $nextRole = $freshLeave->next_action_role;
        $this->notificationService->emit(
            $eventType,
            $freshLeave,
            ['userId' => $actor->id, 'name' => $actor->name, 'email' => $actor->email],
            $nextRole ? [$nextRole] : [],
            [$userId], // notify leave owner
            $nextRole !== null,
            $data['remarks'] ?? null,
        );

        AuditLogger::log($request, "leave_{$action}d", null, [
            'leave_id'   => $leave->id,
            'display_id' => $leave->display_id,
            'owner_id'   => $userId,
            'action'     => $action,
        ]);

        return response()->json(['data' => LeaveController::formatLeave($freshLeave)]);
    }

    // ── Authorization ─────────────────────────────────────────────────────────

    private function assertActionAllowed(Leave $leave, string $action, $actor): void
    {
        // Must be a pending leave for workflow actions
        if ($action !== 'cancel' && $leave->status !== 'Pending') {
            throw ValidationException::withMessages([
                'status' => ['This leave is not in a state that allows this action.'],
            ]);
        }

        if ($action === 'cancel' && !in_array($leave->status, ['Pending', 'Approved'], true)) {
            throw ValidationException::withMessages([
                'status' => ['Only pending or approved leaves can be cancelled.'],
            ]);
        }

        // System admin can do anything
        $actorRoles = $this->authorizationService->getActiveRoleNames($actor)->all();
        if (in_array('System Administrator', $actorRoles, true)) {
            return;
        }

        // For reject/cancel, any manager with the permission is allowed
        if (in_array($action, ['reject', 'cancel'], true)) {
            return;
        }

        // For review/recommend/approve, enforce the workflow role
        $snapshot         = $leave->workflow_snapshot ?? [];
        $expectedStage    = $leave->workflow_stage;
        $expectedRole     = $leave->next_action_role;

        if ($expectedRole && !in_array($expectedRole, $actorRoles, true)) {
            throw ValidationException::withMessages([
                'role' => ["This action requires the '{$expectedRole}' role."],
            ]);
        }

        // Stage must match action
        $stageForAction = match ($action) {
            'review'    => 'review',
            'recommend' => 'recommend',
            'approve'   => 'approve',
            default     => null,
        };

        if ($stageForAction && $expectedStage !== $stageForAction) {
            throw ValidationException::withMessages([
                'stage' => ["Current workflow stage is '{$expectedStage}', not '{$stageForAction}'."],
            ]);
        }
    }

    private function assertVersionedActionAllowed(Leave $leave, string $action, $actor): void
    {
        if ($action === 'cancel') {
            if (! in_array($leave->status, ['Pending', 'Approved'], true)) {
                throw ValidationException::withMessages([
                    'status' => ['Only pending or approved leaves can be cancelled.'],
                ]);
            }
        } elseif ($leave->status !== 'Pending') {
            throw ValidationException::withMessages([
                'status' => ['This leave is not in a state that allows this action.'],
            ]);
        }

        $actorRoles = $this->authorizationService->getActiveRoleNames($actor)->all();
        if (in_array('System Administrator', $actorRoles, true)) {
            return;
        }

        $expectedRole = trim((string) $leave->next_action_role);
        if ($expectedRole === '' || ! in_array($expectedRole, $actorRoles, true)) {
            throw ValidationException::withMessages([
                'role' => [$expectedRole
                    ? "This action requires the '{$expectedRole}' role."
                    : 'This leave has no configured workflow action owner.'],
            ]);
        }

        $requiredStage = match ($action) {
            'review' => 'review',
            'recommend' => 'recommend',
            'approve' => 'approve',
            'reject', 'request_correction', 'cancel' => $leave->workflow_stage,
            default => '',
        };
        if ($requiredStage === '' || $leave->workflow_stage !== $requiredStage) {
            throw ValidationException::withMessages([
                'stage' => ["Current workflow stage is '{$leave->workflow_stage}', not '{$requiredStage}'."],
            ]);
        }

        $snapshot = is_array($leave->workflow_snapshot) ? $leave->workflow_snapshot : [];
        if (($snapshot['enforceDistinctApprovers'] ?? false) === true
            && in_array($action, ['review', 'recommend', 'approve'], true)) {
            $hasActed = collect($leave->approval_history ?: [])->contains(
                fn ($entry) => (string) ($entry['byUserId'] ?? '') === (string) $actor->id
                    && in_array((string) ($entry['action'] ?? ''), ['Reviewed', 'Recommended', 'Approved'], true),
            );
            if ($hasActed) {
                throw ValidationException::withMessages([
                    'role' => ['This workflow requires a different approver at each stage.'],
                ]);
            }
        }
    }

    private function assertExpectedVersion(Leave $leave, ?int $expectedVersion): void
    {
        if ($expectedVersion !== null && $expectedVersion !== (int) $leave->version) {
            throw new HttpResponseException(response()->json([
                'code' => 'LEAVE_VERSION_CONFLICT',
                'message' => 'This leave request changed. Reload the latest record before trying again.',
                'currentVersion' => (int) $leave->version,
                'currentRecord' => LeaveController::formatLeave($leave),
            ], 409));
        }
    }

    private function actionToEventType(string $action): string
    {
        return match ($action) {
            'review'    => 'reviewed',
            'recommend' => 'recommended',
            'approve'   => 'approved',
            'reject'    => 'rejected',
            'cancel'    => 'cancelled',
            'request_correction' => 'correction_requested',
            default     => $action,
        };
    }
}
