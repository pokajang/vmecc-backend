<?php

namespace App\Http\Controllers;

use App\Models\OvertimeRecord;
use App\Services\AssignmentAuthorizationService;
use App\Services\AuditLogger;
use App\Services\OvertimeWorkflowService;
use App\Services\WorkflowNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class OvertimeWorkflowController extends Controller
{
    public function __construct(
        private readonly OvertimeWorkflowService $workflowService,
        private readonly WorkflowNotificationService $notificationService,
        private readonly AssignmentAuthorizationService $authorizationService,
    ) {
    }

    public function review(Request $request, int $ownerId, int $recordId): JsonResponse
    {
        return $this->handleAction($request, $ownerId, $recordId, 'review');
    }

    public function recommend(Request $request, int $ownerId, int $recordId): JsonResponse
    {
        return $this->handleAction($request, $ownerId, $recordId, 'recommend');
    }

    public function approve(Request $request, int $ownerId, int $recordId): JsonResponse
    {
        return $this->handleAction($request, $ownerId, $recordId, 'approve');
    }

    public function reject(Request $request, int $ownerId, int $recordId): JsonResponse
    {
        return $this->handleAction($request, $ownerId, $recordId, 'reject');
    }

    public function cancel(Request $request, int $ownerId, int $recordId): JsonResponse
    {
        return $this->handleAction($request, $ownerId, $recordId, 'cancel');
    }

    private function handleAction(Request $request, int $ownerId, int $recordId, string $action): JsonResponse
    {
        $actor = $request->user();
        $payload = $request->validate([
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        $row = OvertimeRecord::query()->where('user_id', $ownerId)->with('attachment')->findOrFail($recordId);

        $this->assertActionAllowed($row, $action, $actor);

        $updates = $this->workflowService->advanceWorkflow(
            $row,
            $action,
            (int) $actor->id,
            (string) ($actor->name ?? ''),
            $payload['remarks'] ?? null,
        );

        $row->update($this->toColumnKeys($updates));
        $row->refresh()->load('attachment');

        $nextRole = $row->next_action_role;
        $eventType = match ($action) {
            'review' => 'reviewed',
            'recommend' => 'recommended',
            'approve' => 'approved',
            'reject' => 'rejected',
            'cancel' => 'cancelled',
            default => $action,
        };

        $this->notificationService->emit(
            module: 'overtime',
            eventType: $eventType,
            recordType: 'overtime',
            recordId: $row->id,
            recordDisplayId: $row->display_id,
            ownerUserId: (int) $row->user_id,
            actor: ['userId' => $actor->id, 'name' => $actor->name, 'email' => $actor->email],
            targetRoles: $nextRole ? [$nextRole] : [],
            targetUserIds: [(int) $ownerId],
            actionRequired: (bool) $nextRole,
            remarks: $payload['remarks'] ?? null,
            metadata: [
                'module' => 'overtime',
                'status' => $row->status,
                'workflowStage' => $row->workflow_stage,
                'nextActionRole' => $row->next_action_role,
            ],
        );

        AuditLogger::log($request, "overtime_{$action}", $actor, [
            'overtime_id' => $row->id,
            'display_id' => $row->display_id,
            'owner_id' => $row->user_id,
        ]);

        return response()->json(['data' => OvertimeController::formatRecord($row)]);
    }

    private function assertActionAllowed(OvertimeRecord $record, string $action, $actor): void
    {
        if ($action !== 'cancel' && $record->status !== 'Pending') {
            throw ValidationException::withMessages([
                'status' => ['Only pending overtime records can be processed.'],
            ]);
        }

        if ($action === 'cancel' && !in_array($record->status, ['Pending', 'Approved'], true)) {
            throw ValidationException::withMessages([
                'status' => ['Only pending or approved overtime records can be cancelled.'],
            ]);
        }

        $actorRoles = $this->authorizationService->getActiveRoleNames($actor)->all();
        if (in_array('System Administrator', $actorRoles, true)) {
            return;
        }

        if (in_array($action, ['reject', 'cancel'], true)) {
            return;
        }

        $expectedRole = trim((string) ($record->next_action_role ?? ''));
        if ($expectedRole !== '' && !in_array($expectedRole, $actorRoles, true)) {
            throw ValidationException::withMessages([
                'role' => ["This action requires the '{$expectedRole}' role."],
            ]);
        }

        $expectedStage = trim((string) ($record->workflow_stage ?? ''));
        $requiredStage = match ($action) {
            'review' => 'review',
            'recommend' => 'recommend',
            'approve' => 'approve',
            default => '',
        };

        if ($requiredStage !== '' && $expectedStage !== $requiredStage) {
            throw ValidationException::withMessages([
                'stage' => ["Current workflow stage is '{$expectedStage}', not '{$requiredStage}'."],
            ]);
        }
    }

    private function toColumnKeys(array $updates): array
    {
        $mapped = [];
        foreach ($updates as $key => $value) {
            $mapped[match ($key) {
                'workflowStage' => 'workflow_stage',
                'nextActionRole' => 'next_action_role',
                'approvalHistory' => 'approval_history',
                default => $key,
            }] = $value;
        }
        return $mapped;
    }
}
