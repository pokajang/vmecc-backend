<?php

namespace Tests\Audit;

use App\Models\Leave;
use App\Models\LeaveAssignment;
use App\Models\OvertimeRecord;
use App\Models\PayrollClaim;
use App\Models\User;
use App\Models\UserRoleAssignment;
use App\Services\RoleCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Executable probes for confirmed security gaps.
 *
 * This directory is intentionally outside phpunit.xml's normal Unit/Feature suites.
 * Each test describes the secure invariant and is expected to fail until its finding
 * is remediated. Run explicitly with:
 * php artisan test tests/Audit/WorkflowSecurityInvariantAuditTest.php
 */
class WorkflowSecurityInvariantAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_system_administrator_cannot_skip_leave_review_and_recommendation(): void
    {
        $owner = User::factory()->create(['status' => 'Active']);
        $admin = $this->systemAdministrator();
        $leave = $this->leave($owner);
        LeaveAssignment::query()->create([
            'user_id' => $owner->id,
            'year' => 2026,
            'leave_type' => 'Annual Leave',
            'entitlement' => 14,
            'used' => 0,
            'pending' => 1,
        ]);

        $this->actingAs($admin)
            ->postJson("/api/staff/leave/records/{$owner->id}/{$leave->id}/approve", [
                'declaration_checked' => true,
                'expected_version' => 1,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('stage');
    }

    public function test_system_administrator_cannot_skip_overtime_review_and_recommendation(): void
    {
        $owner = User::factory()->create(['status' => 'Active']);
        $admin = $this->systemAdministrator();
        $record = $this->overtime($owner);

        $this->actingAs($admin)
            ->postJson("/api/staff/overtime/records/{$owner->id}/{$record->id}/approve", [
                'expected_version' => 1,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('stage');
    }

    public function test_system_administrator_cannot_skip_payroll_check_and_review(): void
    {
        $owner = User::factory()->create(['status' => 'Active']);
        $admin = $this->systemAdministrator();
        $claim = $this->payrollClaim($owner);

        $this->actingAs($admin)
            ->postJson("/api/staff/salary-claims/records/{$owner->id}/{$claim->id}/approve")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('stage');
    }

    public function test_payroll_rejection_requires_ownership_of_the_current_stage(): void
    {
        $owner = User::factory()->create(['status' => 'Active']);
        $manager = User::factory()->create(['status' => 'Active']);
        $this->assignRole($manager, 'Human Resource', ['staff.salary.manage']);
        $claim = $this->payrollClaim($owner);

        $this->actingAs($manager)
            ->postJson("/api/staff/salary-claims/records/{$owner->id}/{$claim->id}/reject", [
                'remarks' => 'Attempted by a non-owner of the check stage.',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role');
    }

    public function test_payroll_claims_support_optimistic_workflow_locking(): void
    {
        $this->assertTrue(
            Schema::hasColumn('payroll_claims', 'version'),
            'Payroll workflow transitions have no version column and cannot reject stale concurrent actions.',
        );
    }

    private function systemAdministrator(): User
    {
        $user = User::factory()->create(['status' => 'Active']);
        $this->assignRole($user, 'System Administrator', []);

        return $user;
    }

    private function assignRole(User $user, string $roleName, array $permissions): void
    {
        $role = Role::query()->firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate([
                'name' => $permissionName,
                'guard_name' => 'web',
            ]);
            if (! $role->hasPermissionTo($permission)) {
                $role->givePermissionTo($permission);
            }
        }
        UserRoleAssignment::query()->create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'scope_type' => RoleCatalog::GLOBAL,
            'is_primary' => true,
        ]);
    }

    private function leave(User $owner): Leave
    {
        return Leave::query()->create([
            'user_id' => $owner->id,
            'display_id' => 'LV-AUDIT-001',
            'leave_type' => 'Annual Leave',
            'status' => 'Pending',
            'start_date' => '2026-07-20',
            'end_date' => '2026-07-20',
            'days' => 1,
            'applied_at' => now(),
            'workflow_stage' => 'review',
            'workflow_snapshot' => [
                'reviewRole' => 'Contract Manager',
                'recommendRole' => 'Human Resource',
                'approveRole' => 'Client Contract Manager',
                'requireRecommendation' => true,
            ],
            'next_action_role' => 'Contract Manager',
            'approval_history' => [],
            'version' => 1,
        ]);
    }

    private function overtime(User $owner): OvertimeRecord
    {
        return OvertimeRecord::query()->create([
            'user_id' => $owner->id,
            'display_id' => 'OT-AUDIT-001',
            'overtime_type' => 'weekday',
            'claim_date' => now()->subDay()->toDateString(),
            'start_time' => '09:00',
            'end_time' => '10:00',
            'duration_minutes' => 60,
            'reason' => 'Workflow administrator stage-bypass audit.',
            'status' => 'Pending',
            'applied_at' => now(),
            'workflow_stage' => 'review',
            'workflow_snapshot' => [
                'reviewRole' => 'Contract Manager',
                'recommendRole' => 'Human Resource',
                'approveRole' => 'Client Contract Manager',
                'requireRecommendation' => true,
            ],
            'next_action_role' => 'Contract Manager',
            'approval_history' => [],
            'version' => 1,
        ]);
    }

    private function payrollClaim(User $owner): PayrollClaim
    {
        return PayrollClaim::query()->create([
            'user_id' => $owner->id,
            'display_id' => 'CLM-AUDIT-001',
            'claim_type' => 'expense',
            'category' => 'Audit',
            'period' => 'July 2026',
            'period_value' => '2026-07',
            'amount' => 50,
            'status' => 'Pending',
            'submitted_at' => now(),
            'workflow_stage' => 'check',
            'workflow_snapshot' => [
                'checkRole' => 'Admin',
                'reviewRole' => 'Finance',
                'approveRole' => 'Contract Manager',
            ],
            'next_action_role' => 'Admin',
            'approval_history' => [],
        ]);
    }
}
