<?php

namespace App\Http\Controllers;

use App\Models\OvertimeRecord;
use App\Services\AuditLogger;
use App\Services\OvertimeManagementScopeService;
use App\Services\OvertimeWorkflowService;
use App\Services\WorkflowNotificationService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OvertimeWorkflowController extends Controller
{
    public function __construct(
        private readonly OvertimeWorkflowService $workflowService,
        private readonly WorkflowNotificationService $notificationService,
        private readonly OvertimeManagementScopeService $scopeService,
    ) {}

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

    public function requestCorrection(Request $request, int $ownerId, int $recordId): JsonResponse
    {
        return $this->handleAction($request, $ownerId, $recordId, 'request_correction');
    }

    private function handleAction(Request $request, int $ownerId, int $recordId, string $action): JsonResponse
    {
        $actor = $request->user();
        $payload = $request->validate([
            'remarks' => ['nullable', 'string', 'max:1000'],
            'expected_version' => ['nullable', 'integer', 'min:1'],
        ]);

        if ($action === 'request_correction' && ! trim((string) ($payload['remarks'] ?? ''))) {
            throw ValidationException::withMessages([
                'remarks' => ['Remarks are required when requesting correction.'],
            ]);
        }

        $row = DB::transaction(function () use ($ownerId, $recordId, $action, $actor, $payload) {
            $row = OvertimeRecord::query()
                ->where('user_id', $ownerId)
                ->with(['user', 'attachment'])
                ->lockForUpdate()
                ->findOrFail($recordId);

            $this->assertActionAllowed($row, $action, $actor);
            $this->assertExpectedVersion($row, $payload['expected_version'] ?? null);

            $updates = $this->workflowService->advanceWorkflow(
                $row,
                $action,
                (int) $actor->id,
                (string) ($actor->name ?? ''),
                $payload['remarks'] ?? null,
            );

            $updates = $this->toColumnKeys($updates);
            $updates['version'] = ((int) $row->version) + 1;
            $updated = OvertimeRecord::query()
                ->whereKey($row->id)
                ->where('version', $payload['expected_version'] ?? $row->version)
                ->update($updates);
            if ($updated === 0) {
                $this->throwVersionConflict($row);
            }

            return $row->fresh(['attachment']);
        });

        $nextRole = $row->next_action_role;
        $eventType = match ($action) {
            'review' => 'reviewed',
            'recommend' => 'recommended',
            'approve' => 'approved',
            'reject' => 'rejected',
            'cancel' => 'cancelled',
            'request_correction' => 'correction_requested',
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

        if ($action === 'cancel' && ! in_array($record->status, ['Pending', 'Approved'], true)) {
            throw ValidationException::withMessages([
                'status' => ['Only pending or approved overtime records can be cancelled.'],
            ]);
        }

        $expectedStage = trim((string) ($record->workflow_stage ?? ''));
        $requiredStage = match ($action) {
            'review' => 'review',
            'recommend' => 'recommend',
            'approve' => 'approve',
            'reject', 'request_correction' => $expectedStage,
            default => '',
        };

        if ($requiredStage !== '' && $expectedStage !== $requiredStage) {
            throw ValidationException::withMessages([
                'stage' => ["Current workflow stage is '{$expectedStage}', not '{$requiredStage}'."],
            ]);
        }

        $isSystemAdministrator = $this->scopeService->isSystemAdministrator($actor);
        if ($action === 'cancel' && ! $isSystemAdministrator) {
            throw ValidationException::withMessages([
                'role' => ['Only a system administrator can cancel another employee\'s overtime claim.'],
            ]);
        }

        if (! $isSystemAdministrator) {
            $expectedRole = trim((string) ($record->next_action_role ?? ''));
            if ($expectedRole === '' || ! $this->scopeService->canPerformWorkflowRole($actor, $record, $expectedRole)) {
                throw ValidationException::withMessages([
                    'role' => [$expectedRole !== ''
                        ? "This action requires the '{$expectedRole}' role for the employee's team."
                        : 'This overtime claim has no configured workflow action owner.'],
                ]);
            }
        }

        $snapshot = is_array($record->workflow_snapshot) ? $record->workflow_snapshot : [];
        if (($snapshot['enforceDistinctApprovers'] ?? false) === true
            && in_array($action, ['review', 'recommend', 'approve'], true)) {
            $hasAlreadyApproved = collect($record->approval_history ?: [])->contains(
                fn ($entry) => (string) ($entry['byUserId'] ?? '') === (string) $actor->id
                    && in_array((string) ($entry['action'] ?? ''), ['Reviewed', 'Recommended', 'Approved'], true),
            );
            if ($hasAlreadyApproved) {
                throw ValidationException::withMessages([
                    'role' => ['This workflow requires a different approver at each stage.'],
                ]);
            }
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

    private function assertExpectedVersion(OvertimeRecord $row, ?int $expectedVersion): void
    {
        if ($expectedVersion !== null && $expectedVersion !== (int) $row->version) {
            $this->throwVersionConflict($row);
        }
    }

    private function throwVersionConflict(OvertimeRecord $row): never
    {
        throw new HttpResponseException(response()->json([
            'code' => 'OT_VERSION_CONFLICT',
            'message' => 'This overtime claim changed. Reload the latest record before trying again.',
            'currentVersion' => (int) $row->version,
            'currentRecord' => OvertimeController::formatRecord($row),
        ], 409));
    }
}
