<?php

namespace App\Services;

use App\Jobs\DispatchWorkflowChannelsJob;
use App\Models\User;
use App\Models\UserRoleAssignment;
use App\Models\WorkflowNotification;
use App\Models\WorkflowNotificationRecipientState;
use App\Services\WorkflowNotifications\WorkflowEmailModuleGate;
use App\Services\WorkflowNotifications\WorkflowNotificationChannelPolicy;
use App\Services\WorkflowNotifications\WorkflowNotificationPolicyResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WorkflowNotificationService
{
    public function __construct(
        private readonly AssignmentAuthorizationService $authorizationService,
        private readonly WorkflowNotificationPolicyResolver $policyResolver,
    ) {}

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
        'member_assigned' => 'Team assignment',
        'roster_changed' => 'Roster updated',
        'team_disbanded' => 'Team disbanded',
        'published' => 'Roster published',
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
        $explicitRecipientIds = $this->resolveRecipients($targetRoles, $targetUserIds, $ownerUserId, $excludeOwner);
        $resolvedMetadata = $this->buildStandardMetadata(
            $module,
            $recordType,
            $recordId,
            $recordDisplayId,
            $ownerUserId,
            $metadata,
        );
        $policy = $this->policyResolver->resolve(
            module: $module,
            eventType: $eventType,
            recordType: $recordType,
            actionRequired: $actionRequired,
            recordId: $recordId,
            recordDisplayId: $recordDisplayId,
            metadata: $resolvedMetadata,
        );

        $resolvedAt = $this->policyResolver->isFinalOutcome($eventType) ? now() : null;
        $normalizedMetadata = array_merge($resolvedMetadata, [
            'ownerUserId' => $ownerUserId,
            'category' => $policy['category'],
            'severity' => $policy['severity'],
            'channelPolicy' => $policy['channelPolicy'],
            'workflowStage' => $policy['workflowStage'] ?? ($resolvedMetadata['workflowStage'] ?? null),
            'nextActionRole' => $policy['nextActionRole'] ?? ($resolvedMetadata['nextActionRole'] ?? null),
        ]);

        $viewerIds = collect($explicitRecipientIds)
            ->push($ownerUserId)
            ->filter(fn ($id) => (int) $id > 0)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $title = self::EVENT_TITLES[$eventType] ?? ucfirst($eventType);
        $message = $this->buildMessage($module, $eventType, $recordDisplayId, $actor, $remarks);

        $notification = DB::transaction(function () use (
            $module,
            $eventType,
            $recordType,
            $recordId,
            $recordDisplayId,
            $ownerUserId,
            $actor,
            $explicitRecipientIds,
            $actionRequired,
            $title,
            $message,
            $normalizedMetadata,
            $policy,
            $resolvedAt,
            $viewerIds,
        ): WorkflowNotification {
            $notification = $this->findCoalescingNotification(
                module: $module,
                recordType: $recordType,
                recordId: $recordId,
                recordDisplayId: $recordDisplayId,
                category: $policy['category'],
                dedupeKey: (string) $policy['dedupeKey'],
            );

            $payload = [
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
                'recipient_user_ids' => array_values(array_unique($explicitRecipientIds)),
                'action_required' => $actionRequired,
                'resolved_at' => $resolvedAt,
                'title' => $title,
                'message' => $message,
                'metadata' => ! empty($normalizedMetadata) ? $normalizedMetadata : null,
                'category' => $policy['category'],
                'severity' => $policy['severity'],
                'channel_policy' => $policy['channelPolicy'],
                'dedupe_key' => $policy['dedupeKey'],
                'updated_at' => now(),
            ];

            if ($notification) {
                $notification->fill($payload)->save();
            } else {
                $notification = WorkflowNotification::create($payload + [
                    'created_at' => now(),
                ]);
            }

            $this->syncRecipientStates(
                $notification,
                $viewerIds->all(),
                $explicitRecipientIds,
                (string) $policy['channelPolicy'],
                $resolvedAt,
            );

            $this->resolveSupersededNotifications($notification, (string) $policy['category']);

            return $notification->fresh();
        });

        if ($this->shouldDispatchWorkflowEmail($module, $recordType)) {
            try {
                DispatchWorkflowChannelsJob::dispatch($notification->id);
            } catch (\Throwable $exception) {
                Log::warning('Workflow notification persisted, but email dispatch could not be queued.', [
                    'notification_id' => $notification->id,
                    'module' => $module,
                    'record_type' => $recordType,
                    'error' => $exception->getMessage(),
                ]);
            }
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

        $rows = $this->viewerQuery($userId, $isSystemAdministrator)
            ->when($module, fn ($query) => $query->where('workflow_notifications.module', $module))
            ->when($unreadOnly, fn ($query) => $query->whereNull('viewer_state.read_at'))
            ->when($actionRequiredOnly, function ($query) {
                $query->where('workflow_notifications.action_required', true)
                    ->whereNull(DB::raw('COALESCE(viewer_state.resolved_at, workflow_notifications.resolved_at)'));
            })
            ->orderByDesc('workflow_notifications.created_at')
            ->limit($queryLimit)
            ->get();

        $formatted = $rows->map(
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

        return (int) $this->viewerQuery($userId, $isSystemAdministrator)
            ->when($module, fn ($query) => $query->where('workflow_notifications.module', $module))
            ->whereNull('viewer_state.read_at')
            ->count('workflow_notifications.id');
    }

    public function markRead(int $notificationId, int $userId): void
    {
        $notification = $this->visibleNotificationForViewer($notificationId, $userId);
        if (! $notification) {
            return;
        }

        $state = WorkflowNotificationRecipientState::firstOrNew([
            'notification_id' => $notificationId,
            'user_id' => $userId,
        ]);
        if (! $state->exists) {
            $state->channel_policy = WorkflowNotificationChannelPolicy::IN_APP_ONLY;
            $state->resolved_at = $notification->resolved_at;
        }
        $state->read_at = now();
        $state->save();
    }

    public function markAllRead(int $userId, ?string $module = null): void
    {
        [, $isSystemAdministrator] = $this->viewerContext($userId);

        $notifications = $this->viewerQuery($userId, $isSystemAdministrator)
            ->when($module, fn ($query) => $query->where('workflow_notifications.module', $module))
            ->get(['workflow_notifications.id', 'workflow_notifications.resolved_at']);

        foreach ($notifications as $notification) {
            $state = WorkflowNotificationRecipientState::firstOrNew([
                'notification_id' => $notification->id,
                'user_id' => $userId,
            ]);
            if (! $state->exists) {
                $state->channel_policy = WorkflowNotificationChannelPolicy::IN_APP_ONLY;
                $state->resolved_at = $notification->resolved_at;
            }
            $state->read_at = now();
            $state->save();
        }
    }

    public function dismiss(int $notificationId, int $userId): void
    {
        $notification = $this->visibleNotificationForViewer($notificationId, $userId);
        if (! $notification) {
            return;
        }

        $state = WorkflowNotificationRecipientState::firstOrNew([
            'notification_id' => $notificationId,
            'user_id' => $userId,
        ]);
        if (! $state->exists) {
            $state->channel_policy = WorkflowNotificationChannelPolicy::IN_APP_ONLY;
            $state->resolved_at = $notification->resolved_at;
        }
        $state->dismissed_at = now();
        $state->save();
    }

    public function dismissAll(int $userId): void
    {
        [, $isSystemAdministrator] = $this->viewerContext($userId);

        $notifications = $this->viewerQuery($userId, $isSystemAdministrator)
            ->get(['workflow_notifications.id', 'workflow_notifications.resolved_at']);

        foreach ($notifications as $notification) {
            $state = WorkflowNotificationRecipientState::firstOrNew([
                'notification_id' => $notification->id,
                'user_id' => $userId,
            ]);
            if (! $state->exists) {
                $state->channel_policy = WorkflowNotificationChannelPolicy::IN_APP_ONLY;
                $state->resolved_at = $notification->resolved_at;
            }
            $state->dismissed_at = now();
            $state->save();
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

    private function viewerQuery(int $userId, bool $isSystemAdministrator): Builder
    {
        return WorkflowNotification::query()
            ->select([
                'workflow_notifications.*',
                'viewer_state.user_id as viewer_state_user_id',
                'viewer_state.read_at as viewer_read_at',
                'viewer_state.dismissed_at as viewer_dismissed_at',
                'viewer_state.resolved_at as viewer_resolved_at',
                'viewer_state.channel_policy as viewer_channel_policy',
            ])
            ->leftJoin('workflow_notification_recipient_states as viewer_state', function ($join) use ($userId) {
                $join->on('viewer_state.notification_id', '=', 'workflow_notifications.id')
                    ->where('viewer_state.user_id', '=', $userId);
            })
            ->when(! $isSystemAdministrator, fn ($query) => $query->whereNotNull('viewer_state.user_id'))
            ->whereNull('viewer_state.dismissed_at');
    }

    private function visibleNotificationForViewer(int $notificationId, int $userId): ?WorkflowNotification
    {
        [, $isSystemAdministrator] = $this->viewerContext($userId);

        return $this->viewerQuery($userId, $isSystemAdministrator)
            ->where('workflow_notifications.id', $notificationId)
            ->first();
    }

    private function resolveRecipients(array $roles, array $userIds, int $ownerUserId, bool $excludeOwner): array
    {
        $resolved = array_map('intval', $userIds);

        if (! empty($roles)) {
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
                        ->whereRaw("LOWER(TRIM(COALESCE(status, ''))) = 'active'");
                })
                ->pluck('user_id')
                ->map('intval')
                ->values()
                ->all();
            $resolved = array_merge($resolved, $roleUsers);
        }

        if (! $excludeOwner) {
            $resolved[] = $ownerUserId;
        }

        $resolved = array_values(array_unique(array_filter($resolved, fn ($id) => $id > 0)));
        if (empty($resolved)) {
            return [];
        }

        return User::query()
            ->whereIn('id', $resolved)
            ->whereNull('deleted_at')
            ->whereRaw("LOWER(TRIM(COALESCE(status, ''))) = 'active'")
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
            'roster_changed' => "Team {$displayId} roster has been updated.",
            'team_disbanded' => "Team {$displayId} has been disbanded.",
            'published' => "{$moduleLabel} {$displayId} has been published.",
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

        if ($recordType !== '' && $recordId !== '' && ! array_key_exists('detailRouteKey', $metadata)) {
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
            'resolvedAt' => optional($item->viewer_resolved_at ?? $item->resolved_at)->toIso8601String(),
            'title' => $item->title,
            'message' => $item->message,
            'category' => $item->category,
            'severity' => $item->severity,
            'channelPolicy' => $item->viewer_channel_policy ?? $item->channel_policy,
            'metadata' => $metadata,
            'createdAt' => optional($item->created_at)->toIso8601String(),
            'updatedAt' => optional($item->updated_at)->toIso8601String(),
            'read' => $item->viewer_read_at !== null,
        ];
    }

    private function computeActionRequiredForViewer(
        WorkflowNotification $item,
        array $metadata,
        array $viewerRoles,
        bool $isSystemAdministrator,
    ): bool {
        if (! $item->action_required || ($item->viewer_resolved_at ?? $item->resolved_at) !== null) {
            return false;
        }

        $status = strtolower(trim((string) ($metadata['status'] ?? 'pending')));
        if (! in_array($status, ['pending', 'submitted', 'reviewed', 'in progress', 'in_progress'], true)) {
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
                $normalized,
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
        array $metadata,
    ): ?string {
        $normalizedModule = strtolower(trim($module));
        $normalizedRecordType = strtolower(trim($recordType));
        $displayId = trim((string) ($recordDisplayId ?? ''));
        $reportUid = trim((string) ($metadata['reportUid'] ?? ''));

        if (($normalizedModule === 'report' || $normalizedRecordType === 'report') && $reportUid !== '') {
            return $reportUid;
        }

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

        if ($normalizedModule === 'team' && $recordId) {
            return (string) $recordId;
        }

        if ($ownerUserId > 0 && $recordId) {
            return "{$ownerUserId}::{$recordId}";
        }

        return null;
    }

    private function findCoalescingNotification(
        string $module,
        string $recordType,
        ?int $recordId,
        ?string $recordDisplayId,
        string $category,
        string $dedupeKey,
    ): ?WorkflowNotification {
        if ($dedupeKey === '') {
            return null;
        }

        $query = WorkflowNotification::query()
            ->where('module', $module)
            ->where('record_type', $recordType)
            ->where('dedupe_key', $dedupeKey)
            ->where(function ($builder) use ($recordId, $recordDisplayId) {
                if ($recordId !== null) {
                    $builder->where('record_id', $recordId);

                    return;
                }

                $builder->where('record_display_id', $recordDisplayId);
            })
            ->orderByDesc('updated_at')
            ->orderByDesc('id');

        if ($this->policyResolver->coalescesWithin24Hours($category)) {
            $windowHours = max(1, (int) config('mail.workflow_notifications.coalesce_window_hours', 24));

            return $query->where('created_at', '>=', now()->subHours($windowHours))->first();
        }

        if ($this->policyResolver->coalescesActionRequired($category)) {
            return $query->whereNull('resolved_at')->first();
        }

        return null;
    }

    private function syncRecipientStates(
        WorkflowNotification $notification,
        array $viewerIds,
        array $explicitRecipientIds,
        string $channelPolicy,
        mixed $resolvedAt,
    ): void {
        $viewerIds = collect($viewerIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values();
        $explicitLookup = collect($explicitRecipientIds)
            ->map(fn ($id) => (int) $id)
            ->flip();

        WorkflowNotificationRecipientState::query()
            ->where('notification_id', $notification->id)
            ->when($viewerIds->isNotEmpty(), fn ($query) => $query->whereNotIn('user_id', $viewerIds->all()))
            ->update([
                'resolved_at' => now(),
                'dismissed_at' => now(),
                'updated_at' => now(),
            ]);

        $rows = $viewerIds
            ->map(function (int $userId) use ($notification, $explicitLookup, $channelPolicy, $resolvedAt) {
                return [
                    'notification_id' => $notification->id,
                    'user_id' => $userId,
                    'read_at' => null,
                    'dismissed_at' => null,
                    'emailed_digest_at' => null,
                    'channel_policy' => $explicitLookup->has($userId)
                        ? $channelPolicy
                        : WorkflowNotificationChannelPolicy::IN_APP_ONLY,
                    'resolved_at' => $resolvedAt,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            })
            ->values()
            ->all();

        if (! empty($rows)) {
            WorkflowNotificationRecipientState::query()->upsert(
                $rows,
                ['notification_id', 'user_id'],
                ['read_at', 'dismissed_at', 'emailed_digest_at', 'channel_policy', 'resolved_at', 'updated_at'],
            );
        }
    }

    private function resolveSupersededNotifications(WorkflowNotification $current, string $category): void
    {
        $recordType = (string) $current->record_type;
        $recordDisplayId = $current->record_display_id;
        $recordId = $current->record_id;

        $query = WorkflowNotification::query()
            ->where('id', '!=', $current->id)
            ->where('record_type', $recordType)
            ->where(function ($builder) use ($recordId, $recordDisplayId) {
                if ($recordId !== null) {
                    $builder->where('record_id', $recordId);

                    return;
                }

                $builder->where('record_display_id', $recordDisplayId);
            })
            ->whereNull('resolved_at');

        if ($category === WorkflowNotificationPolicyResolver::CATEGORY_FINAL_OUTCOME) {
            $ids = $query->pluck('id');
        } elseif ($this->policyResolver->coalescesActionRequired($category)) {
            $ids = $query
                ->where('action_required', true)
                ->where('dedupe_key', '!=', $current->dedupe_key)
                ->pluck('id');
        } else {
            return;
        }

        if ($ids->isEmpty()) {
            return;
        }

        WorkflowNotification::query()
            ->whereIn('id', $ids)
            ->update([
                'resolved_at' => now(),
                'updated_at' => now(),
            ]);

        WorkflowNotificationRecipientState::query()
            ->whereIn('notification_id', $ids)
            ->update([
                'resolved_at' => now(),
                'updated_at' => now(),
            ]);
    }

    private function shouldDispatchWorkflowEmail(string $module, string $recordType): bool
    {
        if (! config('mail.workflow_notifications.enabled', false)) {
            return false;
        }

        return WorkflowEmailModuleGate::enabledFor($module, $recordType);
    }
}
