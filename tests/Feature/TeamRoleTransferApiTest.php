<?php

namespace Tests\Feature;

use App\Models\Report;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\UserRoleAssignment;
use App\Services\ReportingWorkflowService;
use App\Services\RoleCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TeamRoleTransferApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_aic_transfer_moves_assignment_and_hands_over_pending_reports(): void
    {
        $actor = $this->authorizedActor();
        $source = Team::factory()->create(['name' => 'Transfer Alpha']);
        $target = Team::factory()->create(['name' => 'Transfer Bravo']);
        $aicRole = $this->role('Assistant Incident Commander');
        $outgoing = User::factory()->create(['status' => 'Active']);
        $replacement = User::factory()->create(['status' => 'Active']);
        $owner = User::factory()->create(['status' => 'Active']);
        $assignment = $this->assign($outgoing, $aicRole, $source, true);
        $this->assign($replacement, $aicRole, $source);
        $this->member($outgoing, $aicRole, $source);
        $this->member($replacement, $aicRole, $source);
        $report = $this->pendingReport($owner, $outgoing, $source);

        $response = $this->actingAs($actor)->postJson(
            "/api/users/{$outgoing->id}/team-role-transfer",
            [
                'assignment_id' => $assignment->id,
                'target_team_id' => $target->id,
                'effective_date' => now()->toDateString(),
                'reason' => 'Permanent operational move',
            ],
        );

        $response->assertCreated()
            ->assertJsonPath('data.fromTeam.id', $source->id)
            ->assertJsonPath('data.toTeam.id', $target->id)
            ->assertJsonPath('data.handoverCount', 1);
        $this->assertDatabaseHas('user_role_assignments', [
            'id' => $assignment->id,
            'end_date' => now()->subDay()->toDateString(),
        ]);
        $this->assertDatabaseHas('user_role_assignments', [
            'user_id' => $outgoing->id,
            'role_id' => $aicRole->id,
            'team_id' => $target->id,
            'start_date' => now()->toDateString(),
            'end_date' => null,
        ]);
        $this->assertDatabaseHas('team_members', [
            'user_id' => $outgoing->id,
            'team_id' => $target->id,
            'ended_at' => null,
        ]);
        $this->assertDatabaseHas('reports', [
            'id' => $report->id,
            'scope_team_id' => $source->id,
            'owner_user_id' => $owner->id,
            'next_action_user_id' => $replacement->id,
        ]);
        $this->assertDatabaseHas('report_routing_events', [
            'report_id' => $report->id,
            'from_user_id' => $outgoing->id,
            'to_user_id' => $replacement->id,
            'event_type' => 'team_transfer_handover',
        ]);
    }

    public function test_aic_transfer_rolls_back_when_pending_work_has_no_replacement(): void
    {
        $actor = $this->authorizedActor();
        $source = Team::factory()->create(['name' => 'Rollback Alpha']);
        $target = Team::factory()->create(['name' => 'Rollback Bravo']);
        $aicRole = $this->role('Assistant Incident Commander');
        $outgoing = User::factory()->create(['status' => 'Active']);
        $owner = User::factory()->create(['status' => 'Active']);
        $assignment = $this->assign($outgoing, $aicRole, $source, true);
        $this->member($outgoing, $aicRole, $source);
        $this->pendingReport($owner, $outgoing, $source);

        $this->actingAs($actor)->postJson(
            "/api/users/{$outgoing->id}/team-role-transfer",
            [
                'assignment_id' => $assignment->id,
                'target_team_id' => $target->id,
                'effective_date' => now()->toDateString(),
                'reason' => 'Must roll back',
            ],
        )->assertUnprocessable()
            ->assertJsonValidationErrors('handover_user_id');

        $this->assertDatabaseHas('user_role_assignments', [
            'id' => $assignment->id,
            'end_date' => null,
            'team_id' => $source->id,
        ]);
        $this->assertDatabaseMissing('user_role_assignments', [
            'user_id' => $outgoing->id,
            'team_id' => $target->id,
        ]);
        $this->assertDatabaseCount('team_role_transfers', 0);
        $this->assertDatabaseCount('report_routing_events', 0);
    }

    public function test_aic_transfer_hands_over_team_scoped_approval_stage(): void
    {
        $actor = $this->authorizedActor();
        $source = Team::factory()->create(['name' => 'Approval Alpha']);
        $target = Team::factory()->create(['name' => 'Approval Bravo']);
        $aicRole = $this->role('Assistant Incident Commander');
        $outgoing = User::factory()->create(['status' => 'Active']);
        $replacement = User::factory()->create(['status' => 'Active']);
        $owner = User::factory()->create(['status' => 'Active']);
        $assignment = $this->assign($outgoing, $aicRole, $source, true);
        $this->assign($replacement, $aicRole, $source);
        $this->member($outgoing, $aicRole, $source);
        $this->member($replacement, $aicRole, $source);
        $report = $this->pendingReport($owner, $outgoing, $source);
        $report->update([
            'status' => 'Reviewed',
            'workflow_stage' => 'approve',
        ]);

        $this->actingAs($actor)->postJson(
            "/api/users/{$outgoing->id}/team-role-transfer",
            [
                'assignment_id' => $assignment->id,
                'target_team_id' => $target->id,
                'effective_date' => now()->toDateString(),
                'reason' => 'Move with an approval-stage handover',
            ],
        )->assertCreated()
            ->assertJsonPath('data.handoverCount', 1);

        $this->assertDatabaseHas('reports', [
            'id' => $report->id,
            'scope_team_id' => $source->id,
            'workflow_stage' => 'approve',
            'next_action_user_id' => $replacement->id,
        ]);
    }

    public function test_trt_transfer_preserves_existing_report_team_and_new_work_uses_target_team(): void
    {
        $actor = $this->authorizedActor();
        $source = Team::factory()->create(['name' => 'TRT Alpha']);
        $target = Team::factory()->create(['name' => 'TRT Bravo']);
        $trtRole = $this->role('Tactical Response Team');
        $trt = User::factory()->create(['status' => 'Active']);
        $assignment = $this->assign($trt, $trtRole, $source, true);
        $this->member($trt, $trtRole, $source);
        $existing = Report::query()->create([
            'report_uid' => 'trt-existing-'.uniqid(),
            'display_id' => 'INS-TRT-EXISTING',
            'owner_user_id' => $trt->id,
            'report_type' => 'inspection',
            'status' => 'Approved',
            'workflow_stage' => 'done',
            'scope_team_id' => $source->id,
            'approval_history' => [],
            'payload' => [],
            'submitted_at' => now()->subDay(),
        ]);

        $this->actingAs($actor)->postJson(
            "/api/users/{$trt->id}/team-role-transfer",
            [
                'assignment_id' => $assignment->id,
                'target_team_id' => $target->id,
                'effective_date' => now()->toDateString(),
                'reason' => 'TRT permanent move',
            ],
        )->assertCreated()
            ->assertJsonPath('data.handoverCount', 0);

        $this->assertSame($source->id, $existing->fresh()->scope_team_id);
        $workflow = app(ReportingWorkflowService::class)
            ->buildWorkflowForSubmission($trt->fresh(), 'inspection');
        $this->assertSame($target->id, $workflow['scope_team_id']);
    }

    public function test_transfer_options_return_only_safe_active_aic_and_trt_assignments(): void
    {
        $actor = $this->authorizedActor();
        $team = Team::factory()->create(['name' => 'Options Alpha']);
        $user = User::factory()->create(['status' => 'Active']);
        $assignment = $this->assign(
            $user,
            $this->role('Assistant Incident Commander'),
            $team,
            true,
        );

        $row = $this->actingAs($actor)
            ->getJson('/api/team-role-transfers/options')
            ->assertOk()
            ->assertJsonPath('meta.effectiveDate', now()->toDateString())
            ->json('data.0');

        $this->assertSame($assignment->id, $row['assignmentId']);
        $this->assertSame($user->name, $row['userName']);
        $this->assertSame($team->name, $row['teamName']);
        $this->assertSame(
            ['assignmentId', 'role', 'teamId', 'teamName', 'userId', 'userName'],
            collect($row)->keys()->sort()->values()->all(),
        );
    }

    public function test_transfer_rejects_inactive_users_and_malformed_global_assignments(): void
    {
        $actor = $this->authorizedActor();
        $source = Team::factory()->create(['name' => 'Validation Alpha']);
        $target = Team::factory()->create(['name' => 'Validation Bravo']);
        $role = $this->role('Assistant Incident Commander');
        $inactive = User::factory()->create(['status' => 'Inactive']);
        $inactiveAssignment = $this->assign($inactive, $role, $source, true);

        $payload = [
            'target_team_id' => $target->id,
            'effective_date' => now()->toDateString(),
            'reason' => 'Validation attempt',
        ];

        $this->actingAs($actor)->postJson(
            "/api/users/{$inactive->id}/team-role-transfer",
            [...$payload, 'assignment_id' => $inactiveAssignment->id],
        )->assertUnprocessable()
            ->assertJsonValidationErrors(['assignment_id']);

        $legacy = User::factory()->create(['status' => 'Active']);
        $malformed = UserRoleAssignment::query()->create([
            'user_id' => $legacy->id,
            'role_id' => $role->id,
            'scope_type' => RoleCatalog::GLOBAL,
            'team_id' => $source->id,
            'start_date' => now()->subDay()->toDateString(),
            'is_primary' => true,
        ]);

        $this->postJson(
            "/api/users/{$legacy->id}/team-role-transfer",
            [...$payload, 'assignment_id' => $malformed->id],
        )->assertUnprocessable()
            ->assertJsonValidationErrors(['assignment_id']);

        $optionIds = collect($this->getJson('/api/team-role-transfers/options')->json('data'))
            ->pluck('assignmentId');
        $this->assertFalse($optionIds->contains($inactiveAssignment->id));
        $this->assertFalse($optionIds->contains($malformed->id));
    }

    public function test_team_scoped_role_manager_cannot_transfer_into_an_unmanaged_team(): void
    {
        $source = Team::factory()->create(['name' => 'Managed Transfer Team']);
        $target = Team::factory()->create(['name' => 'Protected Transfer Team']);
        $permission = Permission::query()->firstOrCreate([
            'name' => 'roles.assign',
            'guard_name' => 'web',
        ]);
        $managerRole = $this->role('Scoped Role Manager');
        $managerRole->givePermissionTo($permission);
        $actor = User::factory()->create(['status' => 'Active']);
        $this->assign($actor, $managerRole, $source, true);
        $outgoing = User::factory()->create(['status' => 'Active']);
        $assignment = $this->assign(
            $outgoing,
            $this->role('Assistant Incident Commander'),
            $source,
            true,
        );

        $this->actingAs($actor)->postJson(
            "/api/users/{$outgoing->id}/team-role-transfer",
            [
                'assignment_id' => $assignment->id,
                'target_team_id' => $target->id,
                'effective_date' => now()->toDateString(),
                'reason' => 'Unauthorized cross-team move',
            ],
        )->assertForbidden();
    }

    private function authorizedActor(): User
    {
        $permission = Permission::query()->firstOrCreate([
            'name' => 'roles.assign',
            'guard_name' => 'web',
        ]);
        $role = $this->role('System Administrator');
        $role->givePermissionTo($permission);
        $actor = User::factory()->create(['status' => 'Active']);
        $actor->assignRole($role);

        return $actor;
    }

    private function role(string $name): Role
    {
        return Role::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }

    private function assign(User $user, Role $role, Team $team, bool $primary = false): UserRoleAssignment
    {
        return UserRoleAssignment::query()->create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'scope_type' => RoleCatalog::SITE,
            'team_id' => $team->id,
            'start_date' => now()->subDay()->toDateString(),
            'is_primary' => $primary,
        ]);
    }

    private function member(User $user, Role $role, Team $team): TeamMember
    {
        return TeamMember::query()->create([
            'team_id' => $team->id,
            'user_id' => $user->id,
            'name' => $user->name,
            'role' => $role->name,
            'started_at' => now()->subDay()->toDateString(),
        ]);
    }

    private function pendingReport(User $owner, User $aic, Team $team): Report
    {
        return Report::query()->create([
            'report_uid' => 'transfer-report-'.uniqid(),
            'display_id' => 'INS-TRANSFER-'.uniqid(),
            'owner_user_id' => $owner->id,
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'workflow_stage' => 'review',
            'next_action_role' => 'Assistant Incident Commander',
            'next_action_user_id' => $aic->id,
            'routing_reason_code' => 'team_role_assignment',
            'scope_team_id' => $team->id,
            'workflow_snapshot' => [
                'fallbackReviewRole' => 'Incident Commander',
                'scopeTeamId' => $team->id,
                'usedFallbackReview' => false,
                'options' => ['useTeamScopedAic' => true],
            ],
            'approval_history' => [],
            'payload' => [],
            'submitted_at' => now(),
        ]);
    }
}
