<?php

namespace Tests\Feature;

use App\Models\Leave;
use App\Models\LeaveAssignment;
use App\Models\User;
use App\Models\UserRoleAssignment;
use App\Models\WorkflowNotification;
use App\Services\RoleCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class LeaveWorkflowRbacTest extends TestCase
{
    use RefreshDatabase;

    public function test_assigned_roles_complete_review_recommend_approve_with_versioned_attribution(): void
    {
        $owner = User::factory()->create(['status' => 'Active', 'name' => 'Leave Owner']);
        $reviewer = $this->workflowActor('Contract Manager', 'Leave Reviewer');
        $recommender = $this->workflowActor('Human Resource', 'Leave Recommender');
        $approver = $this->workflowActor('Client Contract Manager', 'Leave Approver');
        $leave = $this->leave($owner);
        $this->leaveBalance($owner);

        $this->actingAs($reviewer)
            ->postJson($this->actionUrl($owner, $leave, 'review'), [
                'declaration_checked' => true,
                'remarks' => 'Coverage reviewed.',
                'expected_version' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('data.workflow_stage', 'recommend')
            ->assertJsonPath('data.next_action_role', 'Human Resource')
            ->assertJsonPath('data.version', 2);

        $this->actingAs($recommender)
            ->postJson($this->actionUrl($owner, $leave, 'recommend'), [
                'declaration_checked' => true,
                'remarks' => 'Recommended.',
                'expected_version' => 2,
            ])
            ->assertOk()
            ->assertJsonPath('data.workflow_stage', 'approve')
            ->assertJsonPath('data.next_action_role', 'Client Contract Manager')
            ->assertJsonPath('data.version', 3);

        $this->actingAs($approver)
            ->postJson($this->actionUrl($owner, $leave, 'approve'), [
                'declaration_checked' => true,
                'remarks' => 'Approved.',
                'expected_version' => 3,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'Approved')
            ->assertJsonPath('data.workflow_stage', 'done')
            ->assertJsonPath('data.next_action_role', null)
            ->assertJsonPath('data.version', 4);

        $leave->refresh();
        $this->assertSame(['Reviewed', 'Recommended', 'Approved'], array_column($leave->approval_history, 'action'));
        $this->assertSame(
            [(string) $reviewer->id, (string) $recommender->id, (string) $approver->id],
            array_column($leave->approval_history, 'byUserId'),
        );
        $this->assertDatabaseHas('leave_assignments', [
            'user_id' => $owner->id,
            'leave_type' => 'Annual Leave',
            'pending' => 0,
            'used' => 1,
        ]);
        $this->assertDatabaseHas('workflow_notifications', [
            'module' => 'leave',
            'record_id' => (string) $leave->id,
            'event_type' => 'approved',
        ]);
    }

    public function test_management_permission_without_current_stage_role_is_denied_without_side_effects(): void
    {
        $owner = User::factory()->create(['status' => 'Active']);
        $manager = $this->workflowActor('Human Resource', 'Wrong Leave Manager');
        $leave = $this->leave($owner);

        $this->actingAs($manager)
            ->postJson($this->actionUrl($owner, $leave, 'review'), [
                'declaration_checked' => true,
                'expected_version' => 1,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role');

        $leave->refresh();
        $this->assertSame('review', $leave->workflow_stage);
        $this->assertSame([], $leave->approval_history);
        $this->assertSame(0, WorkflowNotification::query()->count());
    }

    public function test_expired_stage_role_is_ignored_when_an_active_role_grants_route_permission(): void
    {
        $owner = User::factory()->create(['status' => 'Active']);
        $actor = User::factory()->create(['status' => 'Active']);
        $this->assignRole($actor, 'Human Resource', now()->subMonth(), now()->addMonth());
        $this->assignRole($actor, 'Contract Manager', now()->subMonth(), now()->subDay());
        $leave = $this->leave($owner);

        $this->actingAs($actor)
            ->postJson($this->actionUrl($owner, $leave, 'review'), [
                'declaration_checked' => true,
                'expected_version' => 1,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role');

        $this->assertSame('review', $leave->fresh()->workflow_stage);
    }

    public function test_stage_skip_and_stale_version_are_rejected_without_mutation(): void
    {
        $owner = User::factory()->create(['status' => 'Active']);
        $reviewer = $this->workflowActor('Contract Manager', 'Reviewer');
        $leave = $this->leave($owner);

        $this->actingAs($reviewer)
            ->postJson($this->actionUrl($owner, $leave, 'approve'), [
                'declaration_checked' => true,
                'expected_version' => 1,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('stage');

        $this->postJson($this->actionUrl($owner, $leave, 'review'), [
            'declaration_checked' => true,
            'expected_version' => 2,
        ])
            ->assertConflict()
            ->assertJsonPath('code', 'LEAVE_VERSION_CONFLICT');

        $leave->refresh();
        $this->assertSame(1, $leave->version);
        $this->assertSame([], $leave->approval_history);
    }

    public function test_missing_stage_owner_fails_closed_for_a_non_administrator(): void
    {
        $owner = User::factory()->create(['status' => 'Active']);
        $manager = $this->workflowActor('Human Resource', 'Leave Manager');
        $leave = $this->leave($owner, ['next_action_role' => null]);

        $this->actingAs($manager)
            ->postJson($this->actionUrl($owner, $leave, 'review'), [
                'declaration_checked' => true,
                'expected_version' => 1,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role');

        $this->assertSame([], $leave->fresh()->approval_history);
    }

    public function test_distinct_approver_policy_blocks_a_repeat_actor(): void
    {
        $owner = User::factory()->create(['status' => 'Active']);
        $actor = $this->workflowActor('Contract Manager', 'Multi-role Actor');
        $this->assignRole($actor, 'Human Resource', now()->subDay(), now()->addDay());
        $leave = $this->leave($owner, [
            'workflow_snapshot' => [
                'reviewRole' => 'Contract Manager',
                'recommendRole' => 'Human Resource',
                'approveRole' => 'Client Contract Manager',
                'requireRecommendation' => true,
                'enforceDistinctApprovers' => true,
            ],
        ]);

        $this->actingAs($actor)
            ->postJson($this->actionUrl($owner, $leave, 'review'), [
                'declaration_checked' => true,
                'expected_version' => 1,
            ])
            ->assertOk();

        $this->postJson($this->actionUrl($owner, $leave, 'recommend'), [
            'declaration_checked' => true,
            'expected_version' => 2,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role');

        $this->assertSame('recommend', $leave->fresh()->workflow_stage);
        $this->assertCount(1, $leave->fresh()->approval_history);
    }

    private function workflowActor(string $roleName, string $name): User
    {
        $user = User::factory()->create(['status' => 'Active', 'name' => $name]);
        $this->assignRole($user, $roleName, now()->subDay(), now()->addDay());

        return $user;
    }

    private function assignRole(User $user, string $roleName, $startDate, $endDate): void
    {
        $permission = Permission::query()->firstOrCreate([
            'name' => 'staff.leave.manage',
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

    private function leave(User $owner, array $overrides = []): Leave
    {
        return Leave::query()->create(array_merge([
            'user_id' => $owner->id,
            'display_id' => 'LV-RBAC-'.random_int(1000, 9999),
            'leave_type' => 'Annual Leave',
            'status' => 'Pending',
            'start_date' => '2026-07-20',
            'end_date' => '2026-07-20',
            'days' => 1,
            'work_shift' => 'normal',
            'reason' => 'Workflow RBAC validation.',
            'applied_at' => now(),
            'workflow_stage' => 'review',
            'workflow_snapshot' => [
                'reviewRole' => 'Contract Manager',
                'recommendRole' => 'Human Resource',
                'approveRole' => 'Client Contract Manager',
                'requireRecommendation' => true,
                'enforceDistinctApprovers' => false,
            ],
            'next_action_role' => 'Contract Manager',
            'applicant_roles' => ['Tactical Response Team'],
            'approval_history' => [],
            'submitted_by' => $owner->name,
            'version' => 1,
        ], $overrides));
    }

    private function leaveBalance(User $owner): void
    {
        LeaveAssignment::query()->create([
            'user_id' => $owner->id,
            'year' => 2026,
            'leave_type' => 'Annual Leave',
            'entitlement' => 14,
            'used' => 0,
            'pending' => 1,
        ]);
    }

    private function actionUrl(User $owner, Leave $leave, string $action): string
    {
        return "/api/staff/leave/records/{$owner->id}/{$leave->id}/{$action}";
    }
}
