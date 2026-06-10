<?php

namespace App\Http\Controllers;

use App\Models\Leave;
use App\Services\AssignmentAuthorizationService;
use App\Services\AuditLogger;
use App\Services\LeaveNotificationService;
use App\Services\LeaveWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LeaveWorkflowController extends Controller
{
    public function __construct(
        private readonly LeaveWorkflowService           $workflowService,
        private readonly LeaveNotificationService       $notificationService,
        private readonly AssignmentAuthorizationService $authorizationService,
    ) {}

    // ── Review ────────────────────────────────────────────────────────────────

    public function review(Request $request, int $userId, int $leaveId): JsonResponse
    {
        return $this->handleAction($request, $userId, $leaveId, 'review');
    }

    // ── Recommend ─────────────────────────────────────────────────────────────

    public function recommend(Request $request, int $userId, int $leaveId): JsonResponse
    {
        return $this->handleAction($request, $userId, $leaveId, 'recommend');
    }

    // ── Approve ───────────────────────────────────────────────────────────────

    public function approve(Request $request, int $userId, int $leaveId): JsonResponse
    {
        return $this->handleAction($request, $userId, $leaveId, 'approve');
    }

    // ── Reject ────────────────────────────────────────────────────────────────

    public function reject(Request $request, int $userId, int $leaveId): JsonResponse
    {
        return $this->handleAction($request, $userId, $leaveId, 'reject');
    }

    // ── Admin cancel (staff override) ─────────────────────────────────────────

    public function adminCancel(Request $request, int $userId, int $leaveId): JsonResponse
    {
        return $this->handleAction($request, $userId, $leaveId, 'cancel');
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

    private function actionToEventType(string $action): string
    {
        return match ($action) {
            'review'    => 'reviewed',
            'recommend' => 'recommended',
            'approve'   => 'approved',
            'reject'    => 'rejected',
            'cancel'    => 'cancelled',
            default     => $action,
        };
    }
}
