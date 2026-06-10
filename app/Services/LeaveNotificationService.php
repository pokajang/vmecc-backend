<?php

namespace App\Services;

use App\Models\Leave;
use Illuminate\Support\Collection;

class LeaveNotificationService
{
    public function __construct(private readonly WorkflowNotificationService $notificationService)
    {
    }

    public function emit(
        string $eventType,
        Leave $leave,
        array $actor,
        array $targetRoles = [],
        array $targetUserIds = [],
        bool $actionRequired = false,
        ?string $remarks = null,
        array $metadata = [],
        bool $excludeOwner = false,
    ): array {
        $row = $this->notificationService->emit(
            module: 'leave',
            eventType: $eventType,
            recordType: 'leave',
            recordId: $leave->id,
            recordDisplayId: (string) $leave->display_id,
            ownerUserId: (int) $leave->user_id,
            actor: $actor,
            targetRoles: $targetRoles,
            targetUserIds: $targetUserIds,
            actionRequired: $actionRequired,
            remarks: $remarks,
            metadata: array_merge([
                'module' => 'leave',
                'status' => $leave->status,
                'workflowStage' => $leave->workflow_stage,
                'nextActionRole' => $leave->next_action_role,
            ], $metadata),
            excludeOwner: $excludeOwner,
        );

        return [
            'id' => $row->id,
            'module' => $row->module,
            'eventType' => $row->event_type,
        ];
    }

    public function forViewer(
        int $userId,
        bool $unreadOnly = false,
        bool $actionRequiredOnly = false,
        int $limit = 50,
    ): Collection {
        return $this->notificationService->forViewer($userId, $unreadOnly, $actionRequiredOnly, $limit, 'leave');
    }

    public function unreadCount(int $userId): int
    {
        return $this->notificationService->unreadCount($userId, 'leave');
    }

    public function markRead(int $notificationId, int $userId): void
    {
        $this->notificationService->markRead($notificationId, $userId);
    }

    public function markAllRead(int $userId): void
    {
        $this->notificationService->markAllRead($userId, 'leave');
    }

    public function emitAllocationUpdated(int $ownerUserId, string $ownerName, int $year, array $actor): void
    {
        $this->notificationService->emit(
            module: 'leave',
            eventType: 'allocation_updated',
            recordType: 'leave_allocation',
            recordId: null,
            recordDisplayId: null,
            ownerUserId: $ownerUserId,
            actor: $actor,
            targetUserIds: [$ownerUserId],
            actionRequired: false,
            metadata: [
                'module' => 'leave',
                'ownerName' => $ownerName,
                'year' => $year,
                'status' => 'info',
                'workflowStage' => 'done',
                'nextActionRole' => null,
            ],
        );
    }

    public function emitAllocationDeleted(
        int $ownerUserId,
        string $ownerName,
        int $year,
        string $leaveType,
        array $actor,
    ): void {
        $this->notificationService->emit(
            module: 'leave',
            eventType: 'allocation_deleted',
            recordType: 'leave_allocation',
            recordId: null,
            recordDisplayId: null,
            ownerUserId: $ownerUserId,
            actor: $actor,
            targetUserIds: [$ownerUserId],
            actionRequired: false,
            metadata: [
                'module' => 'leave',
                'ownerName' => $ownerName,
                'year' => $year,
                'leaveType' => $leaveType,
                'status' => 'info',
                'workflowStage' => 'done',
                'nextActionRole' => null,
            ],
        );
    }
}
