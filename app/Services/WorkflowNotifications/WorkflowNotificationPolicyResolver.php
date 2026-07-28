<?php

namespace App\Services\WorkflowNotifications;

class WorkflowNotificationPolicyResolver
{
    public const CATEGORY_FYI_UPDATE = 'fyi_update';

    public const CATEGORY_ACTION_REQUIRED_REVIEW = 'action_required_review';

    public const CATEGORY_ACTION_REQUIRED_APPROVE = 'action_required_approve';

    public const CATEGORY_FINAL_OUTCOME = 'final_outcome';

    public const CATEGORY_ADMINISTRATIVE_INFO = 'administrative_info';

    private const FINAL_OUTCOME_EVENTS = [
        'approved',
        'paid',
        'rejected',
        'cancelled',
    ];

    private const ADMINISTRATIVE_EVENTS = [
        'allocation_updated',
        'allocation_deleted',
        'set_salary',
        'updated_salary',
        'deleted_salary',
        'member_assigned',
        'roster_changed',
        'team_disbanded',
        'published',
        'payment_reopened',
    ];

    public function resolve(
        string $module,
        string $eventType,
        string $recordType,
        bool $actionRequired,
        ?int $recordId,
        ?string $recordDisplayId,
        array $metadata = [],
    ): array {
        $normalizedEventType = strtolower(trim($eventType));
        $workflowStage = $this->text($metadata['workflowStage'] ?? $metadata['workflow_stage'] ?? '');
        $nextActionRole = $this->text($metadata['nextActionRole'] ?? $metadata['next_action_role'] ?? '');

        $category = $this->resolveCategory($normalizedEventType, $actionRequired, $workflowStage, $nextActionRole);
        $family = $this->resolveFamily($category);

        return [
            'category' => $category,
            'severity' => $this->resolveSeverity($category, $normalizedEventType),
            'channelPolicy' => $this->resolveChannelPolicy($category),
            'family' => $family,
            'workflowStage' => $workflowStage !== '' ? $workflowStage : null,
            'nextActionRole' => $nextActionRole !== '' ? $nextActionRole : null,
            'dedupeKey' => $this->buildDedupeKey(
                module: $module,
                recordType: $recordType,
                recordId: $recordId,
                recordDisplayId: $recordDisplayId,
                family: $family,
                workflowStage: $workflowStage,
                eventType: $normalizedEventType,
            ),
        ];
    }

    public function isFinalOutcome(string $eventType): bool
    {
        return in_array(strtolower(trim($eventType)), self::FINAL_OUTCOME_EVENTS, true);
    }

    public function coalescesWithin24Hours(string $category): bool
    {
        return in_array($category, [
            self::CATEGORY_FYI_UPDATE,
            self::CATEGORY_ADMINISTRATIVE_INFO,
        ], true);
    }

    public function coalescesActionRequired(string $category): bool
    {
        return in_array($category, [
            self::CATEGORY_ACTION_REQUIRED_REVIEW,
            self::CATEGORY_ACTION_REQUIRED_APPROVE,
        ], true);
    }

    private function resolveCategory(
        string $eventType,
        bool $actionRequired,
        string $workflowStage,
        string $nextActionRole,
    ): string {
        if ($this->isFinalOutcome($eventType)) {
            return self::CATEGORY_FINAL_OUTCOME;
        }

        if ($actionRequired) {
            $stage = strtolower(trim($workflowStage));
            $role = strtolower(trim($nextActionRole));
            if ($stage === 'approve' || str_contains($role, 'approv')) {
                return self::CATEGORY_ACTION_REQUIRED_APPROVE;
            }

            return self::CATEGORY_ACTION_REQUIRED_REVIEW;
        }

        if (in_array($eventType, self::ADMINISTRATIVE_EVENTS, true)) {
            return self::CATEGORY_ADMINISTRATIVE_INFO;
        }

        return self::CATEGORY_FYI_UPDATE;
    }

    private function resolveFamily(string $category): string
    {
        return match ($category) {
            self::CATEGORY_ACTION_REQUIRED_REVIEW => 'action_required_review',
            self::CATEGORY_ACTION_REQUIRED_APPROVE => 'action_required_approve',
            self::CATEGORY_FINAL_OUTCOME => 'final_outcome',
            self::CATEGORY_ADMINISTRATIVE_INFO => 'administrative_info',
            default => 'fyi_update',
        };
    }

    private function resolveSeverity(string $category, string $eventType): string
    {
        if ($category === self::CATEGORY_FINAL_OUTCOME) {
            return match ($eventType) {
                'approved', 'paid' => 'success',
                'rejected', 'cancelled' => 'warning',
                default => 'info',
            };
        }

        if (in_array($category, [
            self::CATEGORY_ACTION_REQUIRED_REVIEW,
            self::CATEGORY_ACTION_REQUIRED_APPROVE,
        ], true)) {
            return 'attention';
        }

        return 'info';
    }

    private function resolveChannelPolicy(string $category): string
    {
        $configuredPolicy = trim((string) config("mail.workflow_notifications.channel_policies.{$category}", ''));
        if (WorkflowNotificationChannelPolicy::isValid($configuredPolicy)) {
            return $configuredPolicy;
        }

        return match ($category) {
            self::CATEGORY_ACTION_REQUIRED_REVIEW,
            self::CATEGORY_ACTION_REQUIRED_APPROVE => WorkflowNotificationChannelPolicy::IN_APP_PLUS_IMMEDIATE_PLUS_DIGEST_REMINDER,
            self::CATEGORY_FINAL_OUTCOME => WorkflowNotificationChannelPolicy::IN_APP_PLUS_IMMEDIATE_EMAIL,
            self::CATEGORY_FYI_UPDATE,
            self::CATEGORY_ADMINISTRATIVE_INFO => WorkflowNotificationChannelPolicy::IN_APP_PLUS_DIGEST,
            default => WorkflowNotificationChannelPolicy::IN_APP_ONLY,
        };
    }

    private function buildDedupeKey(
        string $module,
        string $recordType,
        ?int $recordId,
        ?string $recordDisplayId,
        string $family,
        string $workflowStage,
        string $eventType,
    ): string {
        $recordKey = $recordId ?: $this->text($recordDisplayId);
        $parts = [
            strtolower(trim($module)),
            strtolower(trim($recordType)),
            (string) $recordKey,
            $family,
        ];

        if ($workflowStage !== '') {
            $parts[] = strtolower(trim($workflowStage));
        }

        if ($family === 'final_outcome') {
            $parts[] = $eventType;
        }

        return implode('|', array_map(fn ($value) => trim((string) $value), $parts));
    }

    private function text(mixed $value): string
    {
        return trim((string) $value);
    }
}
