<?php

namespace Tests\Feature;

use App\Models\PayrollClaim;
use App\Models\SalaryAssignment;
use App\Models\Team;
use App\Models\User;
use App\Models\UserRoleAssignment;
use App\Models\WorkflowAttachment;
use App\Services\RoleCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PayrollPrivacyBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_salary_baseline_is_derived_only_for_authenticated_employee_and_is_not_cacheable(): void
    {
        $employeeA = $this->payrollUser();
        $employeeB = $this->payrollUser();
        $this->assignment($employeeA, 3100, 100);
        $this->assignment($employeeB, 9900, 0);
        $this->actingAs($employeeA);

        $response = $this->getJson(
            "/api/payroll/salary-baseline?period=2026-07&employee_user_id={$employeeB->id}"
        );

        $response->assertOk()
            ->assertJsonPath('data.basic', 3100)
            ->assertJsonPath('data.net', 3000)
            ->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertNoStoreCacheHeader($response);
        $this->assertNotSame(9900.0, (float) $response->json('data.basic'));
    }

    public function test_forged_client_payroll_snapshot_is_ignored(): void
    {
        $employee = $this->payrollUser();
        $this->assignment($employee, 3200, 200);
        $this->actingAs($employee);

        $response = $this->postJson('/api/payroll/claims', [
            'claim_type' => 'salary',
            'period' => 'July 2026',
            'period_value' => '2026-07',
            'submission_key' => 'privacy-forged-snapshot-1',
            'items' => [],
            'payroll_snapshot' => [
                'basic' => 99999999,
                'net' => 99999999,
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.payroll_snapshot.basic', 3200)
            ->assertJsonPath('data.payroll_snapshot.net', 3000)
            ->assertJsonPath('data.projected_net_payout', 3000);
        $this->assertDatabaseHas('payroll_claims', [
            'user_id' => $employee->id,
            'period_value' => '2026-07',
            'projected_net_payout' => 3000,
        ]);
    }

    public function test_employee_cannot_read_another_employees_claim_by_id(): void
    {
        $employeeA = $this->payrollUser();
        $employeeB = $this->payrollUser();
        $this->actingAs($employeeA);
        $foreignClaim = PayrollClaim::query()->create([
            'user_id' => $employeeB->id,
            'display_id' => 'CLM-2026-001',
            'claim_type' => 'salary',
            'period_value' => '2026-07',
            'amount' => 9000,
            'status' => 'Pending',
            'version' => 1,
        ]);

        $response = $this->getJson("/api/payroll/claims/{$foreignClaim->id}");

        $response->assertNotFound();
        $this->assertNoStoreCacheHeader($response);
    }

    public function test_future_assignment_is_never_applied_to_an_earlier_period(): void
    {
        $employee = $this->payrollUser();
        SalaryAssignment::query()->create([
            'employee_user_id' => $employee->id,
            'status' => 'Scheduled',
            'effective_from' => '2026-08-01',
            'basic_salary' => 5000,
            'allowance_total' => 0,
            'allowances' => [],
            'employee_contributions' => [],
            'employer_contributions' => [],
            'notes_history' => [],
            'updated_by' => 'Test',
        ]);
        $this->actingAs($employee);

        $this->getJson('/api/payroll/salary-baseline?period=2026-07')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['period_value']);
    }

    public function test_non_salary_manager_cannot_open_a_payroll_attachment_by_id(): void
    {
        $owner = User::factory()->create(['status' => 'Active']);
        $unrelatedManager = User::factory()->create(['status' => 'Active']);
        $unrelatedManager->givePermissionTo(Permission::query()->firstOrCreate([
            'name' => 'staff.manage',
            'guard_name' => 'web',
        ]));
        $attachment = WorkflowAttachment::query()->create([
            'owner_user_id' => $owner->id,
            'disk' => 'local',
            'path' => 'workflow-attachments/private-payroll-document.pdf',
            'original_name' => 'private-payroll-document.pdf',
            'mime_type' => 'application/pdf',
            'size' => 100,
            'uploaded_at' => now(),
        ]);
        PayrollClaim::query()->create([
            'user_id' => $owner->id,
            'display_id' => 'CLM-2026-PRIVATE',
            'claim_type' => 'salary',
            'period_value' => '2026-07',
            'amount' => 3000,
            'status' => 'Pending',
            'attachment_id' => $attachment->id,
            'version' => 1,
        ]);

        $response = $this->actingAs($unrelatedManager)
            ->getJson("/api/workflow/attachments/{$attachment->id}");

        $response->assertNotFound();
        $this->assertNoStoreCacheHeader($response);
    }

    public function test_workflow_notification_payloads_are_not_cacheable(): void
    {
        $user = User::factory()->create(['status' => 'Active']);

        $response = $this->actingAs($user)->getJson('/api/workflow/notifications');

        $response->assertOk();
        $this->assertNoStoreCacheHeader($response);
    }

    public function test_overtime_data_used_by_payroll_is_not_cacheable(): void
    {
        $user = $this->payrollUser();

        $response = $this->actingAs($user)->getJson('/api/overtime');

        $response->assertOk();
        $this->assertNoStoreCacheHeader($response);
    }

    public function test_deleting_legacy_salary_draft_releases_its_active_period_key(): void
    {
        $user = $this->payrollUser();
        $claim = PayrollClaim::query()->create([
            'user_id' => $user->id,
            'display_id' => 'CLM-2026-DRAFT',
            'claim_type' => 'salary',
            'period_value' => '2026-07',
            'salary_period_key' => '2026-07',
            'amount' => 3000,
            'status' => 'Draft',
            'version' => 1,
        ]);

        $this->actingAs($user)
            ->deleteJson("/api/payroll/claims/{$claim->id}", ['expected_version' => 1])
            ->assertNoContent();

        $this->assertNull(
            PayrollClaim::withTrashed()->findOrFail($claim->id)->salary_period_key,
        );
        PayrollClaim::query()->create([
            'user_id' => $user->id,
            'display_id' => 'CLM-2026-REPLACEMENT',
            'claim_type' => 'salary',
            'period_value' => '2026-07',
            'salary_period_key' => '2026-07',
            'amount' => 3000,
            'status' => 'Pending',
            'version' => 1,
        ]);
        $this->assertDatabaseHas('payroll_claims', [
            'user_id' => $user->id,
            'display_id' => 'CLM-2026-REPLACEMENT',
            'salary_period_key' => '2026-07',
        ]);
    }

    public function test_team_scoped_salary_permission_cannot_read_organization_wide_salary_data(): void
    {
        $permission = Permission::query()->firstOrCreate([
            'name' => 'staff.salary.manage',
            'guard_name' => 'web',
        ]);
        $role = Role::query()->firstOrCreate([
            'name' => 'Scoped Salary Manager',
            'guard_name' => 'web',
        ]);
        $dashboardPermissions = collect(['self.dashboard', 'dashboard.payroll.view'])
            ->map(fn (string $name) => Permission::query()->firstOrCreate([
                'name' => $name,
                'guard_name' => 'web',
            ]));
        $dashboardPermissions->push($permission)
            ->each(fn (Permission $grantedPermission) => $role->givePermissionTo($grantedPermission));
        $team = Team::factory()->create();
        $manager = User::factory()->create(['status' => 'Active']);
        UserRoleAssignment::query()->create([
            'user_id' => $manager->id,
            'role_id' => $role->id,
            'scope_type' => RoleCatalog::SITE,
            'team_id' => $team->id,
            'is_primary' => true,
            'start_date' => now()->subDay(),
            'end_date' => now()->addDay(),
        ]);
        $owner = User::factory()->create(['status' => 'Active']);
        $attachment = WorkflowAttachment::query()->create([
            'owner_user_id' => $owner->id,
            'disk' => 'local',
            'path' => 'workflow-attachments/scoped-private-payroll.pdf',
            'original_name' => 'scoped-private-payroll.pdf',
            'mime_type' => 'application/pdf',
            'size' => 100,
            'uploaded_at' => now(),
        ]);
        PayrollClaim::query()->create([
            'user_id' => $owner->id,
            'display_id' => 'CLM-2026-SCOPED',
            'claim_type' => 'salary',
            'period_value' => '2026-07',
            'amount' => 3000,
            'status' => 'Pending',
            'attachment_id' => $attachment->id,
            'version' => 1,
        ]);

        $this->actingAs($manager)
            ->getJson('/api/staff/salary-claims/records')
            ->assertForbidden();
        $this->getJson('/api/stats?modules=payroll')
            ->assertForbidden();
        $this->getJson("/api/workflow/attachments/{$attachment->id}")
            ->assertNotFound();
    }

    private function payrollUser(): User
    {
        $permission = Permission::query()->firstOrCreate([
            'name' => 'self.payroll',
            'guard_name' => 'web',
        ]);
        $role = Role::query()->firstOrCreate([
            'name' => 'Payroll Privacy Tester',
            'guard_name' => 'web',
        ]);
        if (! $role->hasPermissionTo($permission)) {
            $role->givePermissionTo($permission);
        }
        $user = User::factory()->create(['status' => 'Active']);
        $user->assignRole($role);

        return $user;
    }

    private function assignment(User $employee, float $basic, float $deductions): SalaryAssignment
    {
        return SalaryAssignment::query()->create([
            'employee_user_id' => $employee->id,
            'status' => 'Active',
            'effective_from' => '2026-01-01',
            'basic_salary' => $basic,
            'allowance_total' => 0,
            'allowances' => [],
            'employee_contributions' => ['epf' => $deductions, 'perkeso' => 0, 'sip' => 0],
            'employer_contributions' => [],
            'notes_history' => [],
            'updated_by' => 'Test',
        ]);
    }

    private function assertNoStoreCacheHeader(TestResponse $response): void
    {
        $header = (string) $response->headers->get('Cache-Control');

        foreach (['private', 'no-store', 'no-cache', 'max-age=0', 'must-revalidate'] as $directive) {
            $this->assertStringContainsString($directive, $header);
        }
    }
}
