<?php

namespace Tests\Feature;

use App\Jobs\SendWorkflowDigestEmailJob;
use App\Jobs\SendWorkflowImmediateEmailJob;
use App\Mail\WorkflowDigestNotificationMail;
use App\Mail\WorkflowImmediateNotificationMail;
use App\Models\User;
use App\Models\WorkflowNotification;
use App\Models\WorkflowNotificationRecipientState;
use App\Services\WorkflowNotifications\WorkflowNotificationChannelPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SendWorkflowNotificationEmailJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_immediate_job_sends_once_and_marks_delivery_state(): void
    {
        Mail::fake();
        config([
            'mail.workflow_notifications.enabled' => true,
            'mail.workflow_notifications.modules.report' => true,
        ]);

        $owner = User::factory()->create();
        $reviewer = User::factory()->create(['email' => 'reviewer@example.com']);

        $notification = WorkflowNotification::create([
            'module' => 'report',
            'event_type' => 'submitted',
            'record_type' => 'report',
            'record_id' => 77,
            'record_display_id' => 'RPT-77',
            'owner_user_id' => $owner->id,
            'actor_data' => ['name' => $owner->name],
            'recipient_user_ids' => [$reviewer->id],
            'action_required' => true,
            'category' => 'action_required_review',
            'severity' => 'attention',
            'channel_policy' => WorkflowNotificationChannelPolicy::IN_APP_PLUS_IMMEDIATE_PLUS_DIGEST_REMINDER,
            'dedupe_key' => 'report|report|77|action_required_review|review',
            'title' => 'Request submitted',
            'message' => 'A report needs review.',
            'metadata' => [
                'status' => 'Submitted',
                'workflowStage' => 'review',
                'nextActionRole' => 'Contract Manager',
                'reportType' => 'drill',
                'reportUid' => 'report-drill-77',
                'detailRouteKey' => 'report-drill-77',
            ],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        WorkflowNotificationRecipientState::create([
            'notification_id' => $notification->id,
            'user_id' => $reviewer->id,
            'channel_policy' => WorkflowNotificationChannelPolicy::IN_APP_PLUS_IMMEDIATE_PLUS_DIGEST_REMINDER,
        ]);

        $resolver = app(\App\Services\WorkflowNotifications\WorkflowNotificationLinkResolver::class);
        $job = app()->make(SendWorkflowImmediateEmailJob::class, [
            'notificationId' => $notification->id,
            'userId' => $reviewer->id,
        ]);

        $job->handle($resolver);
        $job->handle($resolver);

        Mail::assertSent(WorkflowImmediateNotificationMail::class, 1);
        $this->assertSame(1, \App\Models\WorkflowEmailDelivery::query()
            ->where('notification_id', $notification->id)
            ->where('user_id', $reviewer->id)
            ->where('delivery_kind', 'immediate')
            ->count());
        $this->assertDatabaseHas('workflow_email_deliveries', [
            'notification_id' => $notification->id,
            'user_id' => $reviewer->id,
            'delivery_kind' => 'immediate',
            'status' => 'sent',
        ]);
        $this->assertNotNull(WorkflowNotificationRecipientState::query()
            ->where('notification_id', $notification->id)
            ->where('user_id', $reviewer->id)
            ->value('emailed_immediate_at'));
    }

    public function test_digest_job_sends_deferred_updates_and_reminders_once_per_window(): void
    {
        Mail::fake();
        config([
            'mail.workflow_notifications.enabled' => true,
            'mail.workflow_notifications.modules.report' => true,
            'mail.workflow_notifications.modules.roster' => true,
        ]);

        $recipient = User::factory()->create(['email' => 'digest@example.com']);
        $windowEnd = now()->startOfHour();
        $windowStart = $windowEnd->copy()->subHours(12);

        $deferred = WorkflowNotification::create([
            'module' => 'roster',
            'event_type' => 'published',
            'record_type' => 'roster',
            'record_id' => 10,
            'record_display_id' => 'July 2026',
            'owner_user_id' => $recipient->id,
            'actor_data' => ['name' => 'System'],
            'recipient_user_ids' => [$recipient->id],
            'action_required' => false,
            'category' => 'administrative_info',
            'severity' => 'info',
            'channel_policy' => WorkflowNotificationChannelPolicy::IN_APP_PLUS_DIGEST,
            'dedupe_key' => 'roster|roster|10|administrative_info',
            'title' => 'Roster published',
            'message' => 'Roster published.',
            'metadata' => ['detailRouteKey' => 'roster'],
            'created_at' => $windowStart->copy()->addHour(),
            'updated_at' => $windowStart->copy()->addHour(),
        ]);

        $reminder = WorkflowNotification::create([
            'module' => 'report',
            'event_type' => 'submitted',
            'record_type' => 'report',
            'record_id' => 11,
            'record_display_id' => 'RPT-11',
            'owner_user_id' => $recipient->id,
            'actor_data' => ['name' => 'System'],
            'recipient_user_ids' => [$recipient->id],
            'action_required' => true,
            'category' => 'action_required_review',
            'severity' => 'attention',
            'channel_policy' => WorkflowNotificationChannelPolicy::IN_APP_PLUS_IMMEDIATE_PLUS_DIGEST_REMINDER,
            'dedupe_key' => 'report|report|11|action_required_review|review',
            'title' => 'Request submitted',
            'message' => 'A report needs review.',
            'metadata' => [
                'status' => 'Submitted',
                'workflowStage' => 'review',
                'nextActionRole' => 'Contract Manager',
                'reportType' => 'inspection',
                'reportUid' => 'report-ins-11',
                'detailRouteKey' => 'report-ins-11',
            ],
            'created_at' => $windowStart->copy()->subHour(),
            'updated_at' => $windowStart->copy()->subHour(),
        ]);

        WorkflowNotificationRecipientState::insert([
            [
                'notification_id' => $deferred->id,
                'user_id' => $recipient->id,
                'channel_policy' => WorkflowNotificationChannelPolicy::IN_APP_PLUS_DIGEST,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'notification_id' => $reminder->id,
                'user_id' => $recipient->id,
                'channel_policy' => WorkflowNotificationChannelPolicy::IN_APP_PLUS_IMMEDIATE_PLUS_DIGEST_REMINDER,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $resolver = app(\App\Services\WorkflowNotifications\WorkflowNotificationLinkResolver::class);
        $job = app()->make(SendWorkflowDigestEmailJob::class, [
            'userId' => $recipient->id,
            'windowStartIso' => $windowStart->toIso8601String(),
            'windowEndIso' => $windowEnd->toIso8601String(),
        ]);

        $job->handle($resolver);
        $job->handle($resolver);

        Mail::assertSent(WorkflowDigestNotificationMail::class, 1);
        $this->assertSame(2, \App\Models\WorkflowEmailDelivery::query()
            ->where('user_id', $recipient->id)
            ->whereIn('delivery_kind', ['digest', 'reminder'])
            ->count());
        $this->assertDatabaseHas('workflow_email_deliveries', [
            'notification_id' => $deferred->id,
            'user_id' => $recipient->id,
            'delivery_kind' => 'digest',
            'status' => 'sent',
        ]);
        $this->assertDatabaseHas('workflow_email_deliveries', [
            'notification_id' => $reminder->id,
            'user_id' => $recipient->id,
            'delivery_kind' => 'reminder',
            'status' => 'sent',
        ]);
        $this->assertNotNull(WorkflowNotificationRecipientState::query()
            ->where('notification_id', $deferred->id)
            ->where('user_id', $recipient->id)
            ->value('emailed_digest_at'));
        $this->assertNotNull(WorkflowNotificationRecipientState::query()
            ->where('notification_id', $reminder->id)
            ->where('user_id', $recipient->id)
            ->value('last_reminder_at'));
    }
}
