<?php

namespace Tests\Feature;

use App\Jobs\SendWorkflowDigestEmailJob;
use App\Models\User;
use App\Models\WorkflowNotification;
use App\Models\WorkflowNotificationRecipientState;
use App\Services\WorkflowNotifications\WorkflowNotificationChannelPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SendWorkflowDigestsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_queues_digest_jobs_for_matching_users(): void
    {
        Queue::fake();
        config([
            'mail.workflow_notifications.enabled' => true,
            'mail.workflow_notifications.digest_window_hours' => 6,
            'mail.workflow_notifications.modules.roster' => true,
        ]);

        $recipient = User::factory()->create(['email' => 'digest-candidate@example.com']);
        $notification = WorkflowNotification::create([
            'module' => 'roster',
            'event_type' => 'published',
            'record_type' => 'roster',
            'record_id' => 44,
            'record_display_id' => 'July 2026',
            'owner_user_id' => $recipient->id,
            'actor_data' => ['name' => 'System'],
            'recipient_user_ids' => [$recipient->id],
            'action_required' => false,
            'category' => 'administrative_info',
            'severity' => 'info',
            'channel_policy' => WorkflowNotificationChannelPolicy::IN_APP_PLUS_DIGEST,
            'dedupe_key' => 'roster|roster|44|administrative_info',
            'title' => 'Roster published',
            'message' => 'Roster published.',
            'metadata' => [],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        WorkflowNotificationRecipientState::create([
            'notification_id' => $notification->id,
            'user_id' => $recipient->id,
            'channel_policy' => WorkflowNotificationChannelPolicy::IN_APP_PLUS_DIGEST,
        ]);

        $this->artisan('workflow:send-digests', ['--window-end' => now()->toIso8601String()])
            ->expectsOutputToContain('Queued workflow digests for 1 users')
            ->assertExitCode(0);

        Queue::assertPushed(SendWorkflowDigestEmailJob::class, 1);
    }

    public function test_command_does_not_queue_digest_for_disabled_module(): void
    {
        Queue::fake();
        config([
            'mail.workflow_notifications.enabled' => true,
            'mail.workflow_notifications.modules.roster' => false,
        ]);

        $recipient = User::factory()->create(['email' => 'disabled-digest@example.com']);
        $notification = WorkflowNotification::create([
            'module' => 'roster',
            'event_type' => 'published',
            'record_type' => 'roster',
            'owner_user_id' => $recipient->id,
            'actor_data' => ['name' => 'System'],
            'recipient_user_ids' => [$recipient->id],
            'action_required' => false,
            'category' => 'administrative_info',
            'severity' => 'info',
            'channel_policy' => WorkflowNotificationChannelPolicy::IN_APP_PLUS_DIGEST,
            'dedupe_key' => 'roster|roster|disabled|administrative_info',
            'title' => 'Roster published',
            'message' => 'Roster published.',
            'metadata' => [],
        ]);
        WorkflowNotificationRecipientState::create([
            'notification_id' => $notification->id,
            'user_id' => $recipient->id,
            'channel_policy' => WorkflowNotificationChannelPolicy::IN_APP_PLUS_DIGEST,
        ]);

        $this->artisan('workflow:send-digests', ['--window-end' => now()->toIso8601String()])
            ->expectsOutputToContain('Queued workflow digests for 0 users')
            ->assertExitCode(0);

        Queue::assertNothingPushed();
    }
}
