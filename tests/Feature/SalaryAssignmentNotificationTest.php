<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserRoleAssignment;
use App\Models\WorkflowNotification;
use App\Services\RoleCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SalaryAssignmentNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsSalaryManager(): User
    {
        $manager = User::factory()->create(['status' => 'active']);
        $role = Role::firstOrCreate(['name' => 'Salary Manager', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'staff.salary.manage', 'guard_name' => 'web']);
        $role->givePermissionTo('staff.salary.manage');
        UserRoleAssignment::create([
            'user_id' => $manager->id,
            'role_id' => $role->id,
            'scope_type' => RoleCatalog::GLOBAL,
            'is_primary' => true,
            'start_date' => now()->subDay(),
            'end_date' => now()->addDay(),
        ]);

        $this->actingAs($manager);

        return $manager;
    }

    public function test_store_emits_salary_assignment_notification_to_employee(): void
    {
        $manager = $this->actingAsSalaryManager();
        $employee = User::factory()->create(['status' => 'active']);
        $roleReviewer = User::factory()->create(['status' => 'active']);
        $salaryRole = Role::query()->where('name', 'Salary Manager')->firstOrFail();
        UserRoleAssignment::create([
            'user_id' => $roleReviewer->id,
            'role_id' => $salaryRole->id,
            'scope_type' => RoleCatalog::GLOBAL,
            'is_primary' => true,
            'start_date' => now()->subDay(),
            'end_date' => now()->addDay(),
        ]);

        $response = $this->postJson('/api/staff/salary-assignments', [
            'employee_user_id' => $employee->id,
            'effective_from' => '2026-04-01',
            'basic_salary' => 3200,
            'allowances' => [],
            'employee_contributions' => ['epf' => 0],
            'employer_contributions' => ['epf' => 0],
            'notes_history' => [],
        ]);

        $response->assertCreated();
        $assignmentId = (int) data_get($response->json(), 'data.id');

        $notification = WorkflowNotification::query()
            ->where('module', 'salary')
            ->where('event_type', 'set_salary')
            ->where('record_type', 'salary_assignment')
            ->where('record_id', $assignmentId)
            ->first();

        $this->assertNotNull($notification);
        $this->assertSame((int) $employee->id, (int) $notification->owner_user_id);
        $this->assertContains((int) $employee->id, $notification->recipient_user_ids ?? []);
        $this->assertContains((int) $roleReviewer->id, $notification->recipient_user_ids ?? []);
        $this->assertSame((int) $manager->id, (int) data_get($notification->actor_data, 'userId'));
    }

    public function test_update_emits_salary_assignment_updated_notification(): void
    {
        $this->actingAsSalaryManager();
        $employee = User::factory()->create(['status' => 'active']);

        $created = $this->postJson('/api/staff/salary-assignments', [
            'employee_user_id' => $employee->id,
            'effective_from' => '2026-04-01',
            'basic_salary' => 2800,
            'allowances' => [],
            'employee_contributions' => ['epf' => 0],
            'employer_contributions' => ['epf' => 0],
            'notes_history' => [],
        ])->assertCreated();

        $assignmentId = (int) data_get($created->json(), 'data.id');

        $this->putJson("/api/staff/salary-assignments/{$assignmentId}", [
            'employee_user_id' => $employee->id,
            'effective_from' => '2026-04-01',
            'basic_salary' => 3500,
            'allowances' => [],
            'employee_contributions' => ['epf' => 0],
            'employer_contributions' => ['epf' => 0],
            'notes_history' => [],
        ])->assertOk();

        $notification = WorkflowNotification::query()
            ->where('module', 'salary')
            ->where('event_type', 'updated_salary')
            ->where('record_type', 'salary_assignment')
            ->where('record_id', $assignmentId)
            ->latest('id')
            ->first();

        $this->assertNotNull($notification);
        $this->assertSame((int) $employee->id, (int) $notification->owner_user_id);
        $this->assertContains((int) $employee->id, $notification->recipient_user_ids ?? []);
    }

    public function test_delete_emits_salary_assignment_deleted_notification(): void
    {
        $this->actingAsSalaryManager();
        $employee = User::factory()->create(['status' => 'active']);

        $created = $this->postJson('/api/staff/salary-assignments', [
            'employee_user_id' => $employee->id,
            'effective_from' => '2026-04-01',
            'basic_salary' => 2800,
            'allowances' => [],
            'employee_contributions' => ['epf' => 0],
            'employer_contributions' => ['epf' => 0],
            'notes_history' => [],
        ])->assertCreated();

        $assignmentId = (int) data_get($created->json(), 'data.id');
        $this->deleteJson("/api/staff/salary-assignments/{$assignmentId}")->assertOk();

        $notification = WorkflowNotification::query()
            ->where('module', 'salary')
            ->where('event_type', 'deleted_salary')
            ->where('record_type', 'salary_assignment')
            ->where('record_id', $assignmentId)
            ->latest('id')
            ->first();

        $this->assertNotNull($notification);
        $this->assertSame((int) $employee->id, (int) $notification->owner_user_id);
    }
}
