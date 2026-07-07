<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WorkflowEmailDelivery;
use App\Models\WorkflowNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkflowEmailDeliveriesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_lists_filtered_workflow_email_deliveries(): void
    {
        $user = User::factory()->create(['name' => 'Ops User', 'email' => 'ops@example.com']);
        $notification = WorkflowNotification::create([
            'module' => 'report',
            'event_type' => 'submitted',
            'record_type' => 'report',
            'record_id' => 123,
            'record_display_id' => 'RPT-123',
            'owner_user_id' => $user->id,
            'actor_data' => ['name' => 'System'],
            'recipient_user_ids' => [$user->id],
            'action_required' => true,
            'category' => 'action_required_review',
            'severity' => 'attention',
            'channel_policy' => 'in_app_plus_immediate_plus_digest_reminder',
            'dedupe_key' => 'report|report|123|action_required_review|review',
            'title' => 'Request submitted',
            'message' => 'A report needs review.',
            'metadata' => [],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        WorkflowEmailDelivery::create([
            'notification_id' => $notification->id,
            'user_id' => $user->id,
            'recipient_email' => $user->email,
            'delivery_kind' => 'immediate',
            'status' => 'failed',
            'attempts' => 1,
            'last_error' => 'SMTP unavailable',
        ]);

        $this->artisan('workflow:email-deliveries', [
            '--status' => 'failed',
            '--module' => 'report',
            '--kind' => 'immediate',
        ])
            ->expectsOutputToContain('ops@example.com')
            ->expectsOutputToContain('SMTP unavailable')
            ->assertExitCode(0);
    }

    public function test_command_reports_empty_result(): void
    {
        $this->artisan('workflow:email-deliveries', ['--status' => 'failed'])
            ->expectsOutput('No workflow email deliveries matched the filters.')
            ->assertExitCode(0);
    }
}
