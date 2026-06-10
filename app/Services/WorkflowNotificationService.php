<?php

namespace App\Services;

use App\Jobs\SendWorkflowNotificationEmailJob;
use App\Models\User;
use App\Models\UserRoleAssignment;
use App\Models\WorkflowNotification;
use App\Models\WorkflowNotificationDismissal;
use App\Models\WorkflowNotificationRead;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class WorkflowNotificationService
{
    public function __construct(private readonly AssignmentAuthorizationService $authorizationService)
    {
    }

    private const EVENT_TITLES = [
        'submitted' => 'Request submitted',
        'edited' => 'Request updated',
        'checked' => 'Request checked',
        'reviewed' => 'Request reviewed',
        'recommended' => 'Request recommended',
        'approved' => 'Request approved',
        'rejected' => 'Request rejected',
        'cancelled' => 'Request cancelled',
        'allocation_updated' => 'Allocation updated',
        'allocation_deleted' => 'Allocation deleted',
        'set_salary' => 'Salary assigned',
        'updated_salary' => 'Salary assignment updated',
        'deleted_salary' => 'Salary assignment deleted',
        // Team events
        'member_assigned' => 'Team assignment',
        'roster_changed'  => 'Roster updated',
        'team_disbanded'  => 'Team disbanded',
    ];

    public function emit(
        string $module,
        string $eventType,
        string $recordType,
        ?int $recordId,
        ?string $recordDisplayId,
        int $ownerUserId,
        array $actor,
        array $targetRoles = [],
        array $targetUserIds = [],
        bool $actionRequired = false,
        ?string $remarks = null,
        array $metadata = [],
        bool $excludeOwner = false,
    ): WorkflowNotification {
        $recipientIds = $this->resolveRecipients($targetRoles, $targetUserIds, $ownerUserId, $excludeOwner);
        $normalizedMetadata = $this->buildStandardMetadata(
            $module,
            $recordType,
            $recordId,
            $recordDisplayId,
            $ownerUserId,
            $metadata,
        );

        $title = self::EVENT_TITLES[$eventType] ?? ucfirst($eventType);
        $message = $this->buildMessage($module, $eventType, $recordDisplayId, $actor, $remarks);

        $notification = WorkflowNotification::create([
            'module' => $module,
            'event_type' => $eventType,
            'record_type' => $recordType,
            'record_id' => $recordId,
            'record_display_id' => $recordDisplayId,
            'owner_user_id' => $ownerUserId,
            'actor_data' => [
                'userId' => $actor['userId'] ?? null,
                'name' => $actor['name'] ?? '',
                'email' => $actor['email'] ?? '',
            ],
            'recipient_user_ids' => array_values(array_unique($recipientIds)),
            'action_required' => $actionRequired,
            'title' => $title,
            'message' => $message,
            'metadata' => !empty($normalizedMetadata) ? $normalizedMetadata : null,
            'created_at' => now(),
        ]);

        if ($this->shouldDispatchWorkflowEmail($module, $recordType)) {
            SendWorkflowNotificationEmailJob::dispatch($notification->id);
        }

        return $notification;
    }

    public function forViewer(
        int $userId,
        bool $unreadOnly = false,
        bool $actionRequiredOnly = false,
        int $limit = 50,
        ?string $module = null,
    ): Collection {
        [$normalizedViewerRoles, $isSystemAdministrator] = $this->viewerContext($userId);

        $queryLimit = $actionRequiredOnly ? min(max($limit * 3, $limit), 300) : $limit;

        $dismissedIds = WorkflowNotificationDismissal::where('user_id', $userId)
            ->pluck('notification_id')
            ->all();

        $query = WorkflowNotification::query()
            ->tap(fn ($builder) => $this->applyViewerVisibility($builder, $userId, $isSystemAdministrator))
            ->when(!empty($dismissedIds), fn ($q) => $q->whereNotIn('id', $dismissedIds))
            ->with(['reads' => fn ($builder) => $builder->where('user_id', $userId)])
            ->orderByDesc('created_at')
            ->limit($queryLimit);

        if ($module) {
            $query->where('module', $module);
        }

        if ($actionRequiredOnly) {
            $query->where('action_required', true)->whereNull('resolved_at');
        }

        $notifications = $query->get();

        if ($unreadOnly) {
            $notifications = $notifications->filter(fn ($item) => $item->reads->isEmpty());
        }

        $formatted = $notifications->map(
            fn (WorkflowNotification $item) => $this->format($item, $normalizedViewerRoles, $isSystemAdministrator),
        );

        if ($actionRequiredOnly) {
            $formatted = $formatted->filter(fn ($item) => ($item['actionRequiredForViewer'] ?? false) === true);
        }

        return $formatted->values()->take($limit)->values();
    }

    public function unreadCount(int $userId, ?string $module = null): int
    {
        [, $isSystemAdministrator] = $this->viewerContext($userId);

        $dismissedIds = WorkflowNotificationDismissal::where('user_id', $userId)
            ->pluck('notification_id')
            ->all();

        $total = WorkflowNotification::query()
            ->tap(fn ($builder) => $this->applyViewerVisibility($builder, $userId, $isSystemAdministrator))
            ->when($module, fn ($builder) => $builder->where('module', $module))
            ->when(!empty($dismissedIds), fn ($q) => $q->whereNotIn('id', $dismissedIds))
            ->count();

        $read = WorkflowNotificationRead::query()
            ->where('user_id', $userId)
            ->whereHas('notification', function ($builder) use ($userId, $isSystemAdministrator, $module, $dismissedIds) {
                $this->applyViewerVisibility($builder, $userId, $isSystemAdministrator);
                $builder
                    ->when($module, fn ($q) => $q->where('module', $module))
                    ->when(!empty($dismissedIds), fn ($q) => $q->whereNotIn('id', $dismissedIds));
            })
            ->count();

        return max(0, $total - $read);
    }

    public function markRead(int $notificationId, int $userId): void
    {
        WorkflowNotificationRead::firstOrCreate(
            ['notification_id' => $notificationId, 'user_id' => $userId],
            ['read_at' => now()],
        );
    }

    public function markAllRead(int $userId, ?string $module = null): void
    {
        [, $isSystemAdministrator] = $this->viewerContext($userId);

        $ids = WorkflowNotification::query()
            ->tap(fn ($builder) => $this->applyViewerVisibility($builder, $userId, $isSystemAdministrator))
            ->when($module, fn ($builder) => $builder->where('module', $module))
            ->pluck('id');

        foreach ($ids as $id) {
            WorkflowNotificationRead::firstOrCreate(
                ['notification_id' => $id, 'user_id' => $userId],
                ['read_at' => now()],
            );
        }
    }

    public function dismiss(int $notificationId, int $userId): void
    {
        WorkflowNotificationDismissal::firstOrCreate(
            ['notification_id' => $notificationId, 'user_id' => $userId],
            ['dismissed_at' => now()],
        );
    }

    public function dismissAll(int $userId): void
    {
        [, $isSystemAdministrator] = $this->viewerContext($userId);

        $ids = WorkflowNotification::query()
            ->tap(fn ($builder) => $this->applyViewerVisibility($builder, $userId, $isSystemAdministrator))
            ->pluck('id');

        foreach ($ids as $id) {
            WorkflowNotificationDismissal::firstOrCreate(
                ['notification_id' => $id, 'user_id' => $userId],
                ['dismissed_at' => now()],
            );
        }
    }

    private function viewerContext(int $userId): array
    {
        $viewer = User::find($userId);
        $viewerRoles = $viewer ? $this->authorizationService->getActiveRoleNames($viewer)->all() : [];
        $normalizedViewerRoles = array_values(array_filter(array_map(
            fn ($role) => $this->normalizeRole($role),
            $viewerRoles,
        )));

        return [
            $normalizedViewerRoles,
            in_array('system administrator', $normalizedViewerRoles, true),
        ];
    }

    private function applyViewerVisibility(Builder $builder, int $userId, bool $isSystemAdministrator): Builder
    {
        if ($isSystemAdministrator) {
            return $builder;
        }

        return $builder->where(function ($query) use ($userId) {
            $query->where('owner_user_id', $userId)
                ->orWhereJsonContains('recipient_user_ids', $userId);
        });
    }

    private function resolveRecipients(array $roles, array $userIds, int $ownerUserId, bool $excludeOwner): array
    {
        $resolved = array_map('intval', $userIds);

        if (!empty($roles)) {
            $normalizedRoles = array_values(array_unique(array_filter(array_map(
                fn ($role) => strtolower(trim((string) $role)),
                $roles,
            ))));

            $today = now()->toDateString();
            $roleUsers = UserRoleAssignment::query()
                ->where(function ($query) use ($today) {
                    $query->whereNull('start_date')->orWhereDate('start_date', '<=', $today);
                })
                ->where(function ($query) use ($today) {
                    $query->whereNull('end_date')->orWhereDate('end_date', '>=', $today);
                })
                ->whereHas('role', function ($builder) use ($normalizedRoles) {
                    if (empty($normalizedRoles)) {
                        $builder->whereRaw('1 = 0');
                        return;
                    }
                    $builder->whereIn(DB::raw('LOWER(TRIM(name))'), $normalizedRoles);
                })
                ->whereHas('user', function ($builder) {
                    $builder->whereNull('deleted_at')
                        ->where(function ($query) {
                            $query->whereNull('status')
                                ->orWhereRaw("LOWER(TRIM(status)) = 'active'");
                        });
                })
                ->pluck('user_id')
                ->map('intval')
                ->values()
                ->all();
            $resolved = array_merge($resolved, $roleUsers);
        }

        if (!$excludeOwner) {
            $resolved[] = $ownerUserId;
        }

        $resolved = array_values(array_unique(array_filter($resolved, fn ($id) => $id > 0)));
        if (empty($resolved)) {
            return [];
        }

        return User::query()
            ->whereIn('id', $resolved)
            ->whereNull('deleted_at')
            ->where(function ($query) {
                $query->whereNull('status')
                    ->orWhereRaw("LOWER(TRIM(status)) = 'active'");
            })
            ->pluck('id')
            ->map('intval')
            ->values()
            ->all();
    }

    private function buildMessage(
        string $module,
        string $eventType,
        ?string $recordDisplayId,
        array $actor,
        ?string $remarks,
    ): string {
        $actorName = trim((string) ($actor['name'] ?? 'Someone')) ?: 'Someone';
        $displayId = trim((string) ($recordDisplayId ?? 'record')) ?: 'record';
        $moduleLabel = ucfirst(strtolower($module));

        $message = match ($eventType) {
            'submitted' => "{$actorName} submitted {$moduleLabel} {$displayId}.",
            'edited' => "{$actorName} updated {$moduleLabel} {$displayId}.",
            'checked' => "{$moduleLabel} {$displayId} has been checked by {$actorName}.",
            'reviewed' => "{$moduleLabel} {$displayId} has been reviewed by {$actorName}.",
            'recommended' => "{$moduleLabel} {$displayId} has been recommended by {$actorName}.",
            'approved' => "{$moduleLabel} {$displayId} has been approved by {$actorName}.",
            'rejected' => "{$moduleLabel} {$displayId} has been rejected by {$actorName}.",
            'cancelled' => "{$moduleLabel} {$displayId} has been cancelled by {$actorName}.",
            'allocation_updated' => "{$moduleLabel} allocation has been updated by {$actorName}.",
            'allocation_deleted' => "{$moduleLabel} allocation has been removed by {$actorName}.",
            'set_salary' => "{$moduleLabel} assignment {$displayId} has been set by {$actorName}.",
            'updated_salary' => "{$moduleLabel} assignment {$displayId} has been updated by {$actorName}.",
            'deleted_salary' => "{$moduleLabel} assignment {$displayId} has been deleted by {$actorName}.",
            'member_assigned' => "You have been assigned to Team {$displayId}.",
            'roster_changed'  => "Team {$displayId} roster has been updated.",
            'team_disbanded'  => "Team {$displayId} has been disbanded.",
            default => "{$actorName} performed {$eventType} on {$moduleLabel} {$displayId}.",
        };

        $trimmedRemarks = trim((string) $remarks);
        if ($trimmedRemarks !== '') {
            $message .= " Remarks: {$trimmedRemarks}";
        }

        return $message;
    }

    private function format(WorkflowNotification $item, array $viewerRoles, bool $isSystemAdministrator): array
    {
        $metadata = is_array($item->metadata) ? $item->metadata : [];
        $recordType = trim((string) $item->record_type);
        $recordId = $item->record_id ? (string) $item->record_id : '';
        $ownerUserId = $item->owner_user_id ? (string) $item->owner_user_id : '';

        if ($recordType !== '' && $recordId !== '' && !array_key_exists('detailRouteKey', $metadata)) {
            $metadata['detailRouteKey'] = $ownerUserId !== '' ? "{$ownerUserId}::{$recordId}" : $recordId;
        }

        $actionRequiredForViewer = $this->computeActionRequiredForViewer($item, $metadata, $viewerRoles, $isSystemAdministrator);

        return [
            'id' => $item->id,
            'module' => $item->module,
            'eventType' => $item->event_type,
            'recordType' => $item->record_type,
            'recordId' => $item->record_id,
            'recordDisplayId' => $item->record_display_id,
            'ownerUserId' => $item->owner_user_id,
            'actor' => $item->actor_data,
            'actionRequired' => (bool) $item->action_required,
            'actionRequiredForViewer' => $actionRequiredForViewer,
            'resolvedAt' => optional($item->resolved_at)->toIso8601String(),
            'title' => $item->title,
            'message' => $item->message,
            'metadata' => $metadata,
            'createdAt' => optional($item->created_at)->toIso8601String(),
            'read' => $item->reads->isNotEmpty(),
        ];
    }

    private function computeActionRequiredForViewer(
        WorkflowNotification $item,
        array $metadata,
        array $viewerRoles,
        bool $isSystemAdministrator,
    ): bool {
        if (!$item->action_required || $item->resolved_at !== null) {
            return false;
        }

        $status = strtolower(trim((string) ($metadata['status'] ?? 'pending')));
        if (!in_array($status, ['pending', 'in progress', 'in_progress'], true)) {
            return false;
        }

        $requiredRole = $this->normalizeRole($metadata['nextActionRole'] ?? $metadata['next_action_role'] ?? '');
        if ($requiredRole === '') {
            return false;
        }

        if ($isSystemAdministrator) {
            return true;
        }

        return in_array($requiredRole, $viewerRoles, true);
    }

    private function normalizeRole(mixed $value): string
    {
        return strtolower(trim((string) $value));
    }

    private function buildStandardMetadata(
        string $module,
        string $recordType,
        ?int $recordId,
        ?string $recordDisplayId,
        int $ownerUserId,
        array $metadata,
    ): array {
        $normalized = is_array($metadata) ? $metadata : [];
        $normalized['module'] = trim((string) ($normalized['module'] ?? $module)) ?: $module;
        $normalized['recordType'] = trim((string) ($normalized['recordType'] ?? $recordType)) ?: $recordType;
        $normalized['recordId'] = $normalized['recordId'] ?? $recordId;
        $normalized['recordDisplayId'] = trim((string) ($normalized['recordDisplayId'] ?? $recordDisplayId));
        if ($normalized['recordDisplayId'] === '') {
            $normalized['recordDisplayId'] = $recordDisplayId;
        }

        $existingRouteKey = trim((string) ($normalized['detailRouteKey'] ?? ''));
        if ($existingRouteKey === '') {
            $normalized['detailRouteKey'] = $this->resolveDetailRouteKey(
                $normalized['module'],
                $normalized['recordType'],
                $recordId,
                $normalized['recordDisplayId'],
                $ownerUserId,
            );
        } else {
            $normalized['detailRouteKey'] = $existingRouteKey;
        }

        return $normalized;
    }

    private function resolveDetailRouteKey(
        string $module,
        string $recordType,
        ?int $recordId,
        ?string $recordDisplayId,
        int $ownerUserId,
    ): ?string {
        $normalizedModule = strtolower(trim($module));
        $normalizedRecordType = strtolower(trim($recordType));
        $displayId = trim((string) ($recordDisplayId ?? ''));

        if ($normalizedModule === 'overtime' || $normalizedRecordType === 'overtime') {
            if ($ownerUserId > 0 && $recordId) {
                return "{$ownerUserId}::{$recordId}";
            }
            return null;
        }

        if (
            in_array($normalizedModule, ['salary', 'expense', 'exceptional'], true) ||
            $normalizedRecordType === 'payroll_claim'
        ) {
            if ($displayId !== '') {
                return $displayId;
            }
            return $recordId ? (string) $recordId : null;
        }

        if ($normalizedRecordType === 'salary_assignment') {
            return $recordId ? (string) $recordId : null;
        }

        if ($ownerUserId > 0 && $recordId) {
            return "{$ownerUserId}::{$recordId}";
        }

        return null;
    }

    private function shouldDispatchWorkflowEmail(string $module, string $recordType): bool
    {
        if (!config('mail.workflow_notifications.enabled', false)) {
            return false;
        }

        $moduleGates = config('mail.workflow_notifications.modules', []);
        if (!is_array($moduleGates) || empty($moduleGates)) {
            return true;
        }

        $normalizedModule = strtolower(trim($module));
        $normalizedRecordType = strtolower(trim($recordType));

        if ($normalizedRecordType !== '' && array_key_exists($normalizedRecordType, $moduleGates)) {
            return (bool) $moduleGates[$normalizedRecordType];
        }

        if ($normalizedModule !== '' && array_key_exists($normalizedModule, $moduleGates)) {
            return (bool) $moduleGates[$normalizedModule];
        }

        return false;
    }
}
