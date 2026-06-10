<?php

namespace Tests\Feature;

use App\Jobs\SendWorkflowNotificationEmailJob;
use App\Models\User;
use App\Models\UserRoleAssignment;
use App\Models\WorkflowEmailDelivery;
use App\Models\WorkflowNotification;
use App\Services\AssignmentAuthorizationService;
use App\Services\RoleCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SendWorkflowNotificationEmailJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_sends_email_and_uses_staff_claim_link_for_action_role_recipient(): void
    {
        config([
            'mail.workflow_notifications.enabled' => true,
            'mail.workflow_notifications.modules.salary' => true,
        ]);

        $owner = User::factory()->create();
        $reviewer = User::factory()->create();
        $role = Role::firstOrCreate(['name' => 'Contract Manager', 'guard_name' => 'web']);
        UserRoleAssignment::create([
            'user_id' => $reviewer->id,
            'role_id' => $role->id,
            'scope_type' => RoleCatalog::GLOBAL,
            'is_primary' => true,
        ]);

        $notification = WorkflowNotification::create([
            'module' => 'salary',
            'event_type' => 'submitted',
            'record_type' => 'payroll_claim',
            'record_id' => 77,
            'record_display_id' => 'SC-2026-077',
            'owner_user_id' => $owner->id,
            'actor_data' => ['name' => 'System'],
            'recipient_user_ids' => [$reviewer->id],
            'action_required' => true,
            'title' => 'Request submitted',
            'message' => 'A payroll claim needs action.',
            'metadata' => [
                'module' => 'salary',
                'status' => 'Pending',
                'nextActionRole' => 'Contract Manager',
                'detailRouteKey' => 'SC-2026-077',
            ],
            'created_at' => now(),
        ]);

        $job = new SendWorkflowNotificationEmailJob($notification->id);
        $job->handle();

        $delivery = WorkflowEmailDelivery::query()
            ->where('notification_id', $notification->id)
            ->where('recipient_email', $reviewer->email)
            ->first();

        $this->assertNotNull($delivery);
        $this->assertSame('sent', $delivery->status);

        $authorizationService = app(AssignmentAuthorizationService::class);
        $reflection = new \ReflectionClass(SendWorkflowNotificationEmailJob::class);
        $method = $reflection->getMethod('buildDeepLink');
        $method->setAccessible(true);

        $deepLink = $method->invoke(
            $job,
            $notification,
            $reviewer,
            $authorizationService,
            'http://localhost:3000',
            'http://localhost:3000/notifications/workflow',
        );

        $this->assertSame('http://localhost:3000/staff/salary-claims/claim/SC-2026-077', $deepLink);
    }

    public function test_job_uses_salary_assignment_link_for_assignment_notifications(): void
    {
        config([
            'mail.workflow_notifications.enabled' => true,
            'mail.workflow_notifications.modules.salary_assignment' => true,
        ]);

        $owner = User::factory()->create();
        $recipient = User::factory()->create();

        $notification = WorkflowNotification::create([
            'module' => 'salary',
            'event_type' => 'updated_salary',
            'record_type' => 'salary_assignment',
            'record_id' => 45,
            'record_display_id' => '45',
            'owner_user_id' => $owner->id,
            'actor_data' => ['name' => 'System'],
            'recipient_user_ids' => [$recipient->id],
            'action_required' => false,
            'title' => 'Salary assignment updated',
            'message' => 'A salary assignment has been updated.',
            'metadata' => [
                'module' => 'salary',
                'recordType' => 'salary_assignment',
                'detailRouteKey' => '45',
            ],
            'created_at' => now(),
        ]);

        $job = new SendWorkflowNotificationEmailJob($notification->id);
        $authorizationService = app(AssignmentAuthorizationService::class);
        $reflection = new \ReflectionClass(SendWorkflowNotificationEmailJob::class);
        $method = $reflection->getMethod('buildDeepLink');
        $method->setAccessible(true);

        $deepLink = $method->invoke(
            $job,
            $notification,
            $recipient,
            $authorizationService,
            'http://localhost:3000',
            'http://localhost:3000/notifications/workflow',
        );

        $this->assertSame(
            'http://localhost:3000/staff/salary-claims/set-salary?assignmentId=45',
            $deepLink,
        );
    }
}
