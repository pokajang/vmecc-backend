<?php

namespace Tests\Feature;

use App\Models\OvertimeRecord;
use App\Models\User;
use App\Models\UserRoleAssignment;
use App\Models\WorkflowNotification;
use App\Services\RoleCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OvertimeWorkflowRbacMatrixTest extends TestCase
{
    use RefreshDatabase;

    public function test_assigned_roles_complete_review_recommend_approve_with_versioned_attribution(): void
    {
        $owner = User::factory()->create(['status' => 'Active', 'name' => 'Overtime Owner']);
        $reviewer = $this->workflowActor('Contract Manager', 'Overtime Reviewer');
        $recommender = $this->workflowActor('Human Resource', 'Overtime Recommender');
        $approver = $this->workflowActor('Client Contract Manager', 'Overtime Approver');
        $record = $this->record($owner);

        $this->actingAs($reviewer)
            ->postJson($this->actionUrl($owner, $record, 'review'), [
                'remarks' => 'Hours reviewed.',
                'expected_version' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('data.workflow_stage', 'recommend')
            ->assertJsonPath('data.next_action_role', 'Human Resource')
            ->assertJsonPath('data.version', 2);

        $this->actingAs($recommender)
            ->postJson($this->actionUrl($owner, $record, 'recommend'), [
                'remarks' => 'Recommended.',
                'expected_version' => 2,
            ])
            ->assertOk()
            ->assertJsonPath('data.workflow_stage', 'approve')
            ->assertJsonPath('data.next_action_role', 'Client Contract Manager')
            ->assertJsonPath('data.version', 3);

        $this->actingAs($approver)
            ->postJson($this->actionUrl($owner, $record, 'approve'), [
                'remarks' => 'Approved.',
                'expected_version' => 3,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'Approved')
            ->assertJsonPath('data.workflow_stage', 'done')
            ->assertJsonPath('data.next_action_role', null)
            ->assertJsonPath('data.version', 4);

        $record->refresh();
        $this->assertSame(['Reviewed', 'Recommended', 'Approved'], array_column($record->approval_history, 'action'));
        $this->assertSame(
            [(string) $reviewer->id, (string) $recommender->id, (string) $approver->id],
            array_column($record->approval_history, 'byUserId'),
        );
        $this->assertDatabaseHas('workflow_notifications', [
            'module' => 'overtime',
            'record_id' => (string) $record->id,
            'event_type' => 'approved',
        ]);
    }

    public function test_management_permission_without_stage_role_is_denied_without_side_effects(): void
    {
        $owner = User::factory()->create(['status' => 'Active']);
        $manager = $this->workflowActor('Human Resource', 'Wrong Overtime Manager');
        $record = $this->record($owner);

        $this->actingAs($manager)
            ->postJson($this->actionUrl($owner, $record, 'review'), ['expected_version' => 1])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role');

        $record->refresh();
        $this->assertSame('review', $record->workflow_stage);
        $this->assertSame([], $record->approval_history);
        $this->assertSame(0, WorkflowNotification::query()->count());
    }

    public function test_expired_stage_role_is_ignored_when_an_active_role_grants_route_permission(): void
    {
        $owner = User::factory()->create(['status' => 'Active']);
        $actor = User::factory()->create(['status' => 'Active']);
        $this->assignRole($actor, 'Human Resource', now()->subMonth(), now()->addMonth());
        $this->assignRole($actor, 'Contract Manager', now()->subMonth(), now()->subDay());
        $record = $this->record($owner);

        $this->actingAs($actor)
            ->postJson($this->actionUrl($owner, $record, 'review'), ['expected_version' => 1])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role');

        $this->assertSame('review', $record->fresh()->workflow_stage);
    }

    public function test_wrong_stage_stale_version_and_missing_owner_fail_closed(): void
    {
        $owner = User::factory()->create(['status' => 'Active']);
        $reviewer = $this->workflowActor('Contract Manager', 'Reviewer');
        $record = $this->record($owner);

        $this->actingAs($reviewer)
            ->postJson($this->actionUrl($owner, $record, 'approve'), ['expected_version' => 1])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('stage');

        $this->postJson($this->actionUrl($owner, $record, 'review'), ['expected_version' => 2])
            ->assertConflict()
            ->assertJsonPath('code', 'OT_VERSION_CONFLICT');

        $record->update(['next_action_role' => null]);
        $this->postJson($this->actionUrl($owner, $record, 'review'), ['expected_version' => 1])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role');

        $record->refresh();
        $this->assertSame(1, $record->version);
        $this->assertSame([], $record->approval_history);
    }

    public function test_correction_requires_remarks_and_returns_record_to_applicant_ownership(): void
    {
        $owner = User::factory()->create(['status' => 'Active']);
        $reviewer = $this->workflowActor('Contract Manager', 'Reviewer');
        $record = $this->record($owner);

        $this->actingAs($reviewer)
            ->postJson($this->actionUrl($owner, $record, 'request-correction'), [
                'expected_version' => 1,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('remarks');

        $this->postJson($this->actionUrl($owner, $record, 'request-correction'), [
            'expected_version' => 1,
            'remarks' => 'Clarify the overtime reason.',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'Needs Correction')
            ->assertJsonPath('data.workflow_stage', 'correction')
            ->assertJsonPath('data.next_action_role', null);

        $this->assertSame('Correction Requested', $record->fresh()->approval_history[0]['action']);
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
            'name' => 'staff.overtime.manage',
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

    private function record(User $owner, array $overrides = []): OvertimeRecord
    {
        return OvertimeRecord::query()->create(array_merge([
            'user_id' => $owner->id,
            'display_id' => 'OT-RBAC-'.random_int(1000, 9999),
            'overtime_type' => 'weekday',
            'claim_date' => now()->subDay()->toDateString(),
            'start_time' => '09:00',
            'end_time' => '10:00',
            'is_overnight' => false,
            'duration_minutes' => 60,
            'reason' => 'Workflow RBAC validation and operational handover.',
            'status' => 'Pending',
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

    private function actionUrl(User $owner, OvertimeRecord $record, string $action): string
    {
        return "/api/staff/overtime/records/{$owner->id}/{$record->id}/{$action}";
    }
}
