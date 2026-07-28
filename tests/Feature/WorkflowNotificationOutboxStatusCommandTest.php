<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WorkflowNotificationOutbox;
use App\Services\WorkflowNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkflowNotificationOutboxStatusCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_status_fails_for_stale_available_events(): void
    {
        $user = User::factory()->create();
        $notification = app(WorkflowNotificationService::class)->emit(
            module: 'report',
            eventType: 'submitted',
            recordType: 'report',
            recordId: 812,
            recordDisplayId: 'RPT-812',
            ownerUserId: (int) $user->id,
            actor: ['userId' => $user->id, 'name' => $user->name, 'email' => $user->email],
            targetUserIds: [$user->id],
            actionRequired: true,
            metadata: ['workflowStage' => 'review', 'nextActionRole' => 'Contract Manager'],
        );
        $outbox = WorkflowNotificationOutbox::query()->create([
            'notification_id' => $notification->id,
            'event_version' => 1,
            'status' => 'pending',
        ]);
        $outbox->forceFill([
            'created_at' => now()->subMinutes(20),
            'updated_at' => now()->subMinutes(20),
        ])->saveQuietly();

        $this->artisan('workflow:notification-outbox-status --max-age=10')
            ->expectsOutput('Workflow notification outbox requires attention.')
            ->assertFailed();
    }
}
