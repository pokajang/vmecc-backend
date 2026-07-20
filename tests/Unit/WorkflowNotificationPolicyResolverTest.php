<?php

namespace Tests\Unit;

use App\Services\WorkflowNotifications\WorkflowNotificationPolicyResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkflowNotificationPolicyResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolves_action_required_review_to_immediate_plus_digest_reminder(): void
    {
        $resolver = app(WorkflowNotificationPolicyResolver::class);

        $resolved = $resolver->resolve(
            module: 'report',
            eventType: 'submitted',
            recordType: 'report',
            actionRequired: true,
            recordId: 100,
            recordDisplayId: 'RPT-100',
            metadata: [
                'workflowStage' => 'review',
                'nextActionRole' => 'Contract Manager',
            ],
        );

        $this->assertSame('action_required_review', $resolved['category']);
        $this->assertSame('in_app_plus_immediate_plus_digest_reminder', $resolved['channelPolicy']);
    }

    public function test_resolves_final_outcome_to_immediate_email_only(): void
    {
        $resolver = app(WorkflowNotificationPolicyResolver::class);

        $resolved = $resolver->resolve(
            module: 'leave',
            eventType: 'approved',
            recordType: 'leave',
            actionRequired: false,
            recordId: 101,
            recordDisplayId: 'LV-101',
            metadata: [],
        );

        $this->assertSame('final_outcome', $resolved['category']);
        $this->assertSame('in_app_plus_immediate_email', $resolved['channelPolicy']);
    }

    public function test_resolves_non_action_update_to_digest_only(): void
    {
        $resolver = app(WorkflowNotificationPolicyResolver::class);

        $resolved = $resolver->resolve(
            module: 'team',
            eventType: 'roster_changed',
            recordType: 'team',
            actionRequired: false,
            recordId: 44,
            recordDisplayId: 'Alpha',
            metadata: [],
        );

        $this->assertSame('administrative_info', $resolved['category']);
        $this->assertSame('in_app_plus_digest', $resolved['channelPolicy']);
    }

    public function test_channel_policy_can_be_overridden_from_config(): void
    {
        config(['mail.workflow_notifications.channel_policies.fyi_update' => 'in_app_only']);

        $resolver = app(WorkflowNotificationPolicyResolver::class);

        $resolved = $resolver->resolve(
            module: 'report',
            eventType: 'edited',
            recordType: 'report',
            actionRequired: false,
            recordId: 45,
            recordDisplayId: 'RPT-45',
            metadata: [],
        );

        $this->assertSame('fyi_update', $resolved['category']);
        $this->assertSame('in_app_only', $resolved['channelPolicy']);
    }

    public function test_invalid_channel_policy_falls_back_to_safe_known_policy(): void
    {
        config(['mail.workflow_notifications.channel_policies.fyi_update' => 'EMAIL-EVERYONE']);

        $resolved = app(WorkflowNotificationPolicyResolver::class)->resolve(
            module: 'report',
            eventType: 'edited',
            recordType: 'report',
            actionRequired: false,
            recordId: 46,
            recordDisplayId: 'RPT-46',
            metadata: [],
        );

        $this->assertSame('in_app_plus_digest', $resolved['channelPolicy']);
    }
}
