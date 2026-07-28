<?php

namespace Tests\Feature;

use App\Jobs\SendWorkflowDigestEmailJob;
use App\Jobs\SendWorkflowImmediateEmailJob;
use App\Mail\WorkflowDigestNotificationMail;
use App\Mail\WorkflowImmediateNotificationMail;
use App\Models\User;
use App\Models\WorkflowEmailDelivery;
use App\Models\WorkflowNotification;
use App\Models\WorkflowNotificationRecipientState;
use App\Services\WorkflowNotifications\WorkflowNotificationChannelPolicy;
use App\Services\WorkflowNotifications\WorkflowNotificationLinkResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
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

        $resolver = app(WorkflowNotificationLinkResolver::class);
        $job = app()->make(SendWorkflowImmediateEmailJob::class, [
            'notificationId' => $notification->id,
            'userId' => $reviewer->id,
        ]);

        $job->handle($resolver);
        $job->handle($resolver);

        Mail::assertSent(WorkflowImmediateNotificationMail::class, 1);
        $this->assertSame(1, WorkflowEmailDelivery::query()
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

    public function test_immediate_job_rechecks_module_gate_before_sending(): void
    {
        Mail::fake();
        config([
            'mail.workflow_notifications.enabled' => true,
            'mail.workflow_notifications.modules.report' => false,
        ]);

        [$notification, $recipient] = $this->createImmediateNotification();

        $this->runImmediateJob($notification, $recipient);

        Mail::assertNothingSent();
        $this->assertDatabaseMissing('workflow_email_deliveries', [
            'notification_id' => $notification->id,
            'user_id' => $recipient->id,
        ]);
    }

    public function test_immediate_job_skips_resolved_action_but_sends_final_outcome(): void
    {
        Mail::fake();
        config([
            'mail.workflow_notifications.enabled' => true,
            'mail.workflow_notifications.modules.report' => true,
        ]);

        [$resolvedAction, $actionRecipient, $actionState] = $this->createImmediateNotification();
        $resolvedAction->forceFill(['resolved_at' => now()])->save();
        $actionState->forceFill(['resolved_at' => now()])->save();

        [$finalOutcome, $outcomeRecipient] = $this->createImmediateNotification([
            'event_type' => 'approved',
            'action_required' => false,
            'category' => 'final_outcome',
            'resolved_at' => now(),
        ]);

        $this->runImmediateJob($resolvedAction, $actionRecipient);
        $this->runImmediateJob($finalOutcome, $outcomeRecipient);

        Mail::assertSent(WorkflowImmediateNotificationMail::class, 1);
        $this->assertDatabaseMissing('workflow_email_deliveries', [
            'notification_id' => $resolvedAction->id,
            'user_id' => $actionRecipient->id,
        ]);
        $this->assertDatabaseHas('workflow_email_deliveries', [
            'notification_id' => $finalOutcome->id,
            'user_id' => $outcomeRecipient->id,
            'status' => 'sent',
        ]);
    }

    public function test_immediate_job_recovers_a_stale_unsent_reservation(): void
    {
        Mail::fake();
        config([
            'mail.workflow_notifications.enabled' => true,
            'mail.workflow_notifications.modules.report' => true,
        ]);

        [$notification, $recipient, $state] = $this->createImmediateNotification();
        $reservedAt = now()->subMinutes(20);
        $state->forceFill(['emailed_immediate_at' => $reservedAt])->save();
        $staleDelivery = WorkflowEmailDelivery::create([
            'notification_id' => $notification->id,
            'user_id' => $recipient->id,
            'recipient_email' => $recipient->email,
            'delivery_kind' => 'immediate',
            'status' => 'queued',
            'attempts' => 0,
        ]);
        $staleDelivery->forceFill(['created_at' => $reservedAt])->saveQuietly();

        $this->runImmediateJob($notification, $recipient);

        Mail::assertSent(WorkflowImmediateNotificationMail::class, 1);
        $this->assertSame('failed', $staleDelivery->fresh()->status);
        $this->assertSame(1, WorkflowEmailDelivery::query()
            ->where('notification_id', $notification->id)
            ->where('user_id', $recipient->id)
            ->where('status', 'sent')
            ->count());
    }

    public function test_immediate_job_failed_hook_releases_an_unsent_reservation(): void
    {
        config([
            'mail.workflow_notifications.enabled' => true,
            'mail.workflow_notifications.modules.report' => true,
        ]);

        [$notification, $recipient, $state] = $this->createImmediateNotification();
        $reservedAt = now();
        $state->forceFill(['emailed_immediate_at' => $reservedAt])->save();
        $delivery = WorkflowEmailDelivery::create([
            'notification_id' => $notification->id,
            'user_id' => $recipient->id,
            'recipient_email' => $recipient->email,
            'delivery_kind' => 'immediate',
            'status' => 'queued',
            'attempts' => 0,
        ]);

        $job = new SendWorkflowImmediateEmailJob($notification->id, $recipient->id);
        $job->failed(new RuntimeException('Worker stopped permanently.'));

        $this->assertSame('failed', $delivery->fresh()->status);
        $this->assertSame('Worker stopped permanently.', $delivery->fresh()->last_error);
        $this->assertNull($state->fresh()->emailed_immediate_at);
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

        $resolver = app(WorkflowNotificationLinkResolver::class);
        $job = app()->make(SendWorkflowDigestEmailJob::class, [
            'userId' => $recipient->id,
            'windowStartIso' => $windowStart->toIso8601String(),
            'windowEndIso' => $windowEnd->toIso8601String(),
        ]);

        $job->handle($resolver);
        $job->handle($resolver);

        Mail::assertSent(WorkflowDigestNotificationMail::class, 1);
        $this->assertSame(2, WorkflowEmailDelivery::query()
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

    public function test_inspection_reassignment_with_report_record_type_links_to_inspection_record(): void
    {
        [$notification, $recipient] = $this->createImmediateNotification([
            'module' => 'inspection',
            'record_type' => 'report',
            'metadata' => [
                'reportUid' => 'inspection-reassigned-99',
                'detailRouteKey' => 'inspection-reassigned-99',
                'nextActionRole' => 'Incident Commander',
            ],
        ]);

        $this->assertSame(
            '/inspection/inspection-reassigned-99',
            app(WorkflowNotificationLinkResolver::class)->resolveRelative(
                $notification,
                $recipient,
            ),
        );
    }

    private function createImmediateNotification(array $notificationOverrides = []): array
    {
        $owner = User::factory()->create();
        $recipient = User::factory()->create([
            'status' => 'active',
            'email' => fake()->unique()->safeEmail(),
        ]);

        $notification = WorkflowNotification::create(array_merge([
            'module' => 'report',
            'event_type' => 'submitted',
            'record_type' => 'report',
            'record_id' => fake()->unique()->numberBetween(1000, 9999),
            'record_display_id' => 'RPT-'.fake()->unique()->numberBetween(1000, 9999),
            'owner_user_id' => $owner->id,
            'actor_data' => ['name' => $owner->name],
            'recipient_user_ids' => [$recipient->id],
            'action_required' => true,
            'category' => 'action_required_review',
            'severity' => 'attention',
            'channel_policy' => WorkflowNotificationChannelPolicy::IN_APP_PLUS_IMMEDIATE_PLUS_DIGEST_REMINDER,
            'dedupe_key' => fake()->unique()->uuid(),
            'title' => 'Workflow update',
            'message' => 'A workflow item needs attention.',
            'metadata' => ['detailRouteKey' => 'report-test'],
        ], $notificationOverrides));

        $state = WorkflowNotificationRecipientState::create([
            'notification_id' => $notification->id,
            'user_id' => $recipient->id,
            'channel_policy' => WorkflowNotificationChannelPolicy::IN_APP_PLUS_IMMEDIATE_PLUS_DIGEST_REMINDER,
        ]);

        return [$notification, $recipient, $state];
    }

    private function runImmediateJob(WorkflowNotification $notification, User $recipient): void
    {
        $job = new SendWorkflowImmediateEmailJob($notification->id, $recipient->id);
        $job->handle(app(WorkflowNotificationLinkResolver::class));
    }
}
