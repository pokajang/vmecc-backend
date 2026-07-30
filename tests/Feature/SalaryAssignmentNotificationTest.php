<?php

namespace Tests\Feature;

use App\Models\PayrollClaim;
use App\Models\SalaryAssignment;
use App\Models\User;
use App\Models\UserRoleAssignment;
use App\Models\WorkflowNotification;
use App\Services\RoleCatalog;
use App\Services\WorkflowNotifications\WorkflowNotificationLinkResolver;
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
        $assignmentPublicId = (string) data_get($response->json(), 'data.public_id');

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
        $resolver = app(WorkflowNotificationLinkResolver::class);
        $this->assertSame(
            '/payroll/claims/new/salary',
            $resolver->resolveRelative($notification, $employee),
        );
        $this->assertSame(
            "/staff/set-salary/assignment/{$assignmentPublicId}/view",
            $resolver->resolveRelative($notification, $roleReviewer),
        );
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
        $assignmentVersion = (int) data_get($created->json(), 'data.version');

        $this->putJson("/api/staff/salary-assignments/{$assignmentId}", [
            'employee_user_id' => $employee->id,
            'effective_from' => '2026-04-01',
            'basic_salary' => 3500,
            'allowances' => [],
            'employee_contributions' => ['epf' => 0],
            'employer_contributions' => ['epf' => 0],
            'notes_history' => [],
            'expected_version' => $assignmentVersion,
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
        $assignmentVersion = (int) data_get($created->json(), 'data.version');
        $this->deleteJson("/api/staff/salary-assignments/{$assignmentId}", [
            'expected_version' => $assignmentVersion,
        ])->assertOk();

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

    public function test_duplicate_effective_date_is_rejected_for_the_same_employee(): void
    {
        $this->actingAsSalaryManager();
        $employee = User::factory()->create(['status' => 'active']);
        $payload = [
            'employee_user_id' => $employee->id,
            'effective_from' => '2026-04-01',
            'basic_salary' => 2800,
            'allowances' => [],
            'employee_contributions' => ['epf' => 0],
            'employer_contributions' => ['epf' => 0],
            'notes_history' => [],
        ];

        $this->postJson('/api/staff/salary-assignments', $payload)->assertCreated();
        $this->postJson('/api/staff/salary-assignments', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('effective_from');
    }

    public function test_assignment_referenced_by_a_claim_cannot_be_rewritten(): void
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
        $assignmentVersion = (int) data_get($created->json(), 'data.version');
        PayrollClaim::query()->create([
            'user_id' => $employee->id,
            'display_id' => 'CLM-2026-001',
            'claim_type' => 'salary',
            'period_value' => '2026-04',
            'salary_period_key' => '2026-04',
            'salary_assignment_id' => $assignmentId,
            'salary_assignment_version' => $assignmentVersion,
            'amount' => 2800,
            'status' => 'Pending',
            'version' => 1,
        ]);

        $this->putJson("/api/staff/salary-assignments/{$assignmentId}", [
            'employee_user_id' => $employee->id,
            'effective_from' => '2026-04-01',
            'basic_salary' => 3000,
            'allowances' => [],
            'employee_contributions' => ['epf' => 0],
            'employer_contributions' => ['epf' => 0],
            'notes_history' => [],
            'expected_version' => $assignmentVersion,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('assignment');

        $this->assertDatabaseHas('salary_assignments', [
            'id' => $assignmentId,
            'basic_salary' => 2800,
            'version' => $assignmentVersion,
        ]);
    }

    public function test_assignment_cannot_be_moved_to_another_employee(): void
    {
        $this->actingAsSalaryManager();
        $employee = User::factory()->create(['status' => 'active']);
        $otherEmployee = User::factory()->create(['status' => 'active']);
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
        $assignmentVersion = (int) data_get($created->json(), 'data.version');

        $this->putJson("/api/staff/salary-assignments/{$assignmentId}", [
            'employee_user_id' => $otherEmployee->id,
            'effective_from' => '2026-04-01',
            'basic_salary' => 2800,
            'allowances' => [],
            'employee_contributions' => ['epf' => 0],
            'employer_contributions' => ['epf' => 0],
            'notes_history' => [],
            'expected_version' => $assignmentVersion,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('employee_user_id');

        $this->assertDatabaseHas('salary_assignments', [
            'id' => $assignmentId,
            'employee_user_id' => $employee->id,
            'version' => $assignmentVersion,
        ]);
    }

    public function test_deleted_assignment_releases_its_effective_date_for_a_replacement(): void
    {
        $this->actingAsSalaryManager();
        $employee = User::factory()->create(['status' => 'active']);
        $payload = [
            'employee_user_id' => $employee->id,
            'effective_from' => '2026-04-01',
            'basic_salary' => 2800,
            'allowances' => [],
            'employee_contributions' => ['epf' => 0],
            'employer_contributions' => ['epf' => 0],
            'notes_history' => [],
        ];
        $created = $this->postJson('/api/staff/salary-assignments', $payload)->assertCreated();

        $this->deleteJson(
            '/api/staff/salary-assignments/'.data_get($created->json(), 'data.id'),
            ['expected_version' => data_get($created->json(), 'data.version')],
        )->assertOk();

        $this->postJson('/api/staff/salary-assignments', $payload)->assertCreated();
        $this->assertSame(
            1,
            SalaryAssignment::query()
                ->where('employee_user_id', $employee->id)
                ->whereDate('effective_from', '2026-04-01')
                ->count(),
        );
    }
}
