<?php

namespace Tests\Feature;

use App\Jobs\ProcessWorkflowNotificationOutboxJob;
use App\Models\User;
use App\Models\WorkflowNotificationOutbox;
use App\Services\WorkflowNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WorkflowNotificationOutboxTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_enabled_emit_creates_versioned_durable_outbox_event(): void
    {
        Queue::fake();
        config([
            'mail.workflow_notifications.enabled' => true,
            'mail.workflow_notifications.modules.report' => true,
        ]);
        $owner = User::factory()->create(['status' => 'active']);
        $service = app(WorkflowNotificationService::class);
        $emit = fn () => $service->emit(
            module: 'report',
            eventType: 'submitted',
            recordType: 'report',
            recordId: 810,
            recordDisplayId: 'RPT-810',
            ownerUserId: (int) $owner->id,
            actor: ['userId' => $owner->id, 'name' => $owner->name, 'email' => $owner->email],
            targetUserIds: [$owner->id],
            actionRequired: true,
            metadata: [
                'workflowStage' => 'review',
                'nextActionRole' => 'Contract Manager',
                'reportType' => 'drill',
            ],
        );

        $notification = $emit();
        $first = WorkflowNotificationOutbox::query()
            ->where('notification_id', $notification->id)
            ->firstOrFail();
        $emit();
        $second = $first->fresh();

        $this->assertSame(2, $second->event_version);
        $this->assertSame('pending', $second->status);
        Queue::assertPushed(ProcessWorkflowNotificationOutboxJob::class, 2);
    }

    public function test_duplicate_job_does_not_reprocess_an_active_outbox_lease(): void
    {
        Mail::fake();
        $owner = User::factory()->create(['status' => 'active']);
        $notification = app(WorkflowNotificationService::class)->emit(
            module: 'report',
            eventType: 'submitted',
            recordType: 'report',
            recordId: 811,
            recordDisplayId: 'RPT-811',
            ownerUserId: (int) $owner->id,
            actor: ['userId' => $owner->id, 'name' => $owner->name, 'email' => $owner->email],
            targetUserIds: [$owner->id],
            actionRequired: true,
            metadata: ['workflowStage' => 'review', 'nextActionRole' => 'Contract Manager'],
        );
        $outbox = WorkflowNotificationOutbox::query()->create([
            'notification_id' => $notification->id,
            'event_version' => 1,
            'status' => 'processing',
            'attempts' => 1,
            'processing_at' => now(),
        ]);

        (new ProcessWorkflowNotificationOutboxJob($outbox->id, 1))->handle();

        $this->assertSame('processing', $outbox->fresh()->status);
        Mail::assertNothingSent();
    }

    public function test_sweeper_recovers_stale_processing_and_explicitly_retries_failed_events(): void
    {
        Queue::fake();
        $owner = User::factory()->create(['status' => 'active']);
        $service = app(WorkflowNotificationService::class);
        $notificationOne = $service->emit(
            module: 'report',
            eventType: 'submitted',
            recordType: 'report',
            recordId: 813,
            recordDisplayId: 'RPT-813',
            ownerUserId: (int) $owner->id,
            actor: ['userId' => $owner->id, 'name' => $owner->name, 'email' => $owner->email],
            targetUserIds: [$owner->id],
            actionRequired: true,
            metadata: ['workflowStage' => 'review', 'nextActionRole' => 'Contract Manager'],
        );
        $notificationTwo = $service->emit(
            module: 'report',
            eventType: 'submitted',
            recordType: 'report',
            recordId: 814,
            recordDisplayId: 'RPT-814',
            ownerUserId: (int) $owner->id,
            actor: ['userId' => $owner->id, 'name' => $owner->name, 'email' => $owner->email],
            targetUserIds: [$owner->id],
            actionRequired: true,
            metadata: ['workflowStage' => 'review', 'nextActionRole' => 'Contract Manager'],
        );
        $processing = WorkflowNotificationOutbox::query()->create([
            'notification_id' => $notificationOne->id,
            'event_version' => 1,
            'status' => 'processing',
            'processing_at' => now()->subMinutes(20),
        ]);
        $failed = WorkflowNotificationOutbox::query()->create([
            'notification_id' => $notificationTwo->id,
            'event_version' => 1,
            'status' => 'failed',
            'available_at' => now()->addHour(),
            'failed_at' => now(),
        ]);

        $this->artisan('workflow:dispatch-notification-outbox --retry-failed')
            ->expectsOutput('Queued 2 workflow notification outbox event(s).')
            ->assertSuccessful();

        $this->assertSame('pending', $processing->fresh()->status);
        $this->assertNull($processing->fresh()->processing_at);
        $this->assertSame('pending', $failed->fresh()->status);
        $this->assertNull($failed->fresh()->failed_at);
        Queue::assertPushed(ProcessWorkflowNotificationOutboxJob::class, 2);
    }
}
