<?php

namespace Tests\Feature;

use App\Models\OvertimeRecord;
use App\Models\PayrollClaim;
use App\Models\User;
use App\Models\UserRoleAssignment;
use App\Models\WorkflowNotification;
use App\Services\RoleCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PayrollClaimWorkflowRbacTest extends TestCase
{
    use RefreshDatabase;

    public function test_assigned_roles_complete_check_review_approve_with_server_attribution(): void
    {
        $owner = User::factory()->create(['status' => 'Active', 'name' => 'Claim Owner']);
        $checker = $this->workflowActor('Admin', 'Claim Checker');
        $reviewer = $this->workflowActor('Finance', 'Claim Reviewer');
        $approver = $this->workflowActor('Contract Manager', 'Claim Approver');
        $claim = $this->claim($owner);

        $this->actingAs($checker)
            ->postJson($this->actionUrl($owner, $claim, 'check'), [
                'remarks' => 'Documents checked.',
                'expected_version' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('data.workflow_stage', 'review')
            ->assertJsonPath('data.next_action_role', 'Finance')
            ->assertJsonPath('data.version', 2);

        $this->actingAs($reviewer)
            ->postJson($this->actionUrl($owner, $claim, 'review'), [
                'remarks' => 'Amounts reviewed.',
                'expected_version' => 2,
            ])
            ->assertOk()
            ->assertJsonPath('data.workflow_stage', 'approve')
            ->assertJsonPath('data.next_action_role', 'Contract Manager')
            ->assertJsonPath('data.version', 3);

        $this->actingAs($approver)
            ->postJson($this->actionUrl($owner, $claim, 'approve'), [
                'remarks' => 'Approved.',
                'expected_version' => 3,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'Approved')
            ->assertJsonPath('data.workflow_stage', 'done')
            ->assertJsonPath('data.next_action_role', null)
            ->assertJsonPath('data.version', 4);

        $claim->refresh();
        $this->assertSame(['Checked', 'Reviewed', 'Approved'], array_column($claim->approval_history, 'action'));
        $this->assertSame(
            [(string) $checker->id, (string) $reviewer->id, (string) $approver->id],
            array_column($claim->approval_history, 'byUserId'),
        );
        $this->assertSame(
            ['Claim Checker', 'Claim Reviewer', 'Claim Approver'],
            array_column($claim->approval_history, 'by'),
        );
        $this->assertDatabaseHas('workflow_notifications', [
            'record_type' => 'payroll_claim',
            'record_id' => (string) $claim->id,
            'event_type' => 'approved',
        ]);
    }

    public function test_management_permission_without_current_stage_role_is_denied_without_side_effects(): void
    {
        $owner = User::factory()->create(['status' => 'Active']);
        $manager = $this->workflowActor('Human Resource', 'Wrong Stage Manager');
        $claim = $this->claim($owner);

        $this->actingAs($manager)
            ->postJson($this->actionUrl($owner, $claim, 'check'), ['expected_version' => 1])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role');

        $claim->refresh();
        $this->assertSame('check', $claim->workflow_stage);
        $this->assertSame('Admin', $claim->next_action_role);
        $this->assertSame([], $claim->approval_history);
        $this->assertSame(0, WorkflowNotification::query()->count());
    }

    public function test_expired_stage_role_is_ignored_even_when_another_active_role_grants_route_permission(): void
    {
        $owner = User::factory()->create(['status' => 'Active']);
        $actor = User::factory()->create(['status' => 'Active']);
        $this->assignRole($actor, 'Human Resource', startDate: now()->subMonth(), endDate: now()->addMonth());
        $this->assignRole($actor, 'Admin', startDate: now()->subMonth(), endDate: now()->subDay());
        $claim = $this->claim($owner);

        $this->actingAs($actor)
            ->postJson($this->actionUrl($owner, $claim, 'check'), ['expected_version' => 1])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role');

        $this->assertSame('check', $claim->fresh()->workflow_stage);
    }

    public function test_correct_role_cannot_skip_the_current_workflow_stage(): void
    {
        $owner = User::factory()->create(['status' => 'Active']);
        $finance = $this->workflowActor('Finance', 'Finance Actor');
        $claim = $this->claim($owner, [
            'workflow_stage' => 'review',
            'next_action_role' => 'Finance',
        ]);

        $this->actingAs($finance)
            ->postJson($this->actionUrl($owner, $claim, 'approve'), ['expected_version' => 1])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('stage');

        $claim->refresh();
        $this->assertSame('Pending', $claim->status);
        $this->assertSame('review', $claim->workflow_stage);
        $this->assertSame([], $claim->approval_history);
    }

    public function test_missing_stage_owner_fails_closed_for_a_non_administrator(): void
    {
        $owner = User::factory()->create(['status' => 'Active']);
        $manager = $this->workflowActor('Human Resource', 'Salary Manager');
        $claim = $this->claim($owner, ['next_action_role' => null]);

        $this->actingAs($manager)
            ->postJson($this->actionUrl($owner, $claim, 'check'), ['expected_version' => 1])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role');

        $this->assertSame([], $claim->fresh()->approval_history);
    }

    public function test_terminal_claim_cannot_be_processed_again(): void
    {
        $owner = User::factory()->create(['status' => 'Active']);
        $approver = $this->workflowActor('Contract Manager', 'Approver');
        $claim = $this->claim($owner, [
            'status' => 'Approved',
            'workflow_stage' => 'done',
            'next_action_role' => null,
        ]);

        $this->actingAs($approver)
            ->postJson($this->actionUrl($owner, $claim, 'approve'), ['expected_version' => 1])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->assertSame([], $claim->fresh()->approval_history);
    }

    public function test_stale_workflow_version_is_rejected_without_side_effects(): void
    {
        $owner = User::factory()->create(['status' => 'Active']);
        $checker = $this->workflowActor('Admin', 'Stale Checker');
        $claim = $this->claim($owner, ['version' => 2]);

        $this->actingAs($checker)
            ->postJson($this->actionUrl($owner, $claim, 'check'), [
                'expected_version' => 1,
            ])
            ->assertConflict()
            ->assertJsonPath('code', 'PAYROLL_CLAIM_VERSION_CONFLICT')
            ->assertJsonPath('currentVersion', 2)
            ->assertJsonPath('currentRecord.version', 2);

        $claim->refresh();
        $this->assertSame(2, $claim->version);
        $this->assertSame('check', $claim->workflow_stage);
        $this->assertSame([], $claim->approval_history);
        $this->assertSame(0, WorkflowNotification::query()->count());
    }

    public function test_salary_approval_rejects_changed_approved_overtime_snapshot(): void
    {
        $owner = User::factory()->create(['status' => 'Active']);
        $approver = $this->workflowActor('Contract Manager', 'Snapshot Approver');
        $overtime = OvertimeRecord::query()->create([
            'user_id' => $owner->id,
            'display_id' => 'OT-SNAPSHOT-APPROVAL',
            'overtime_type' => 'weekday',
            'claim_date' => '2026-07-14',
            'start_time' => '18:00',
            'end_time' => '19:00',
            'is_overnight' => false,
            'duration_minutes' => 60,
            'reason' => 'Approved overtime included in salary claim.',
            'status' => 'Approved',
            'workflow_stage' => 'done',
            'approval_history' => [],
            'version' => 1,
        ]);
        $claim = $this->claim($owner, [
            'claim_type' => 'salary',
            'workflow_stage' => 'approve',
            'next_action_role' => 'Contract Manager',
            'overtime_rows' => [[
                'overtimeRecordId' => $overtime->id,
                'overtimePublicId' => $overtime->public_id,
                'overtimeRecordVersion' => 1,
            ]],
        ]);
        $overtime->update(['status' => 'Cancelled', 'version' => 2]);

        $this->actingAs($approver)
            ->postJson($this->actionUrl($owner, $claim, 'approve'), [
                'expected_version' => 1,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('overtime_snapshot');

        $claim->refresh();
        $this->assertSame('Pending', $claim->status);
        $this->assertSame('approve', $claim->workflow_stage);
        $this->assertSame(1, $claim->version);
        $this->assertSame([], $claim->approval_history);
    }

    private function workflowActor(string $roleName, string $name): User
    {
        $user = User::factory()->create(['status' => 'Active', 'name' => $name]);
        $this->assignRole($user, $roleName, startDate: now()->subDay(), endDate: now()->addDay());

        return $user;
    }

    private function assignRole(User $user, string $roleName, $startDate, $endDate): void
    {
        $permission = Permission::query()->firstOrCreate([
            'name' => 'staff.salary.manage',
            'guard_name' => 'web',
        ]);
        $role = Role::query()->firstOrCreate([
            'name' => $roleName,
            'guard_name' => 'web',
        ]);
        if (! $role->hasPermissionTo($permission)) {
            $role->givePermissionTo($permission);
        }

        UserRoleAssignment::query()->create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'scope_type' => RoleCatalog::OFFICE,
            'team_id' => null,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'is_primary' => ! $user->roleAssignments()->exists(),
        ]);
    }

    private function claim(User $owner, array $overrides = []): PayrollClaim
    {
        return PayrollClaim::query()->create(array_merge([
            'user_id' => $owner->id,
            'display_id' => 'CLM-RBAC-'.random_int(1000, 9999),
            'claim_type' => 'expense',
            'category' => 'Operational expense',
            'period' => 'July 2026',
            'period_value' => '2026-07',
            'amount' => 150,
            'approved_overtime_payout' => 0,
            'adjustments_total' => 0,
            'projected_net_payout' => 150,
            'status' => 'Pending',
            'submitted_at' => now(),
            'submitted_by' => $owner->name,
            'submitted_by_name' => $owner->name,
            'updated_by' => $owner->name,
            'updated_by_name' => $owner->name,
            'workflow_stage' => 'check',
            'workflow_snapshot' => [
                'checkRole' => 'Admin',
                'reviewRole' => 'Finance',
                'approveRole' => 'Contract Manager',
            ],
            'next_action_role' => 'Admin',
            'approval_history' => [],
            'payroll_snapshot' => [],
            'overtime_rows' => [],
            'notes' => 'Workflow RBAC test claim.',
        ], $overrides));
    }

    private function actionUrl(User $owner, PayrollClaim $claim, string $action): string
    {
        return "/api/staff/salary-claims/records/{$owner->id}/{$claim->id}/{$action}";
    }
}
