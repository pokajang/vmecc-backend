<?php

namespace Tests\Feature;

use App\Models\DutyCoverageAssignment;
use App\Models\Report;
use App\Models\Team;
use App\Models\User;
use App\Models\UserRoleAssignment;
use App\Services\ReportingWorkflowService;
use App\Services\ReportRoutingReconciliationService;
use App\Services\RoleCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DutyCoverageWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_qualified_cross_team_substitute_becomes_explicit_report_reviewer(): void
    {
        $homeTeam = Team::factory()->create(['name' => 'Bravo Team']);
        $actingTeam = Team::factory()->create(['name' => 'Alpha Team']);
        $submitter = User::factory()->create(['status' => 'Active']);
        $incumbent = User::factory()->create(['status' => 'Active']);
        $substitute = User::factory()->create(['status' => 'Active']);

        $this->assign($submitter, 'Tactical Response Team', $actingTeam->id);
        $aicRole = $this->assign($incumbent, 'Assistant Incident Commander', $actingTeam->id);
        $this->assign($substitute, 'Assistant Incident Commander', $homeTeam->id, $aicRole);

        $coverage = DutyCoverageAssignment::query()->create([
            'user_id' => $substitute->id,
            'acting_team_id' => $actingTeam->id,
            'home_team_id' => $homeTeam->id,
            'acting_role_id' => $aicRole->id,
            'replaces_user_id' => $incumbent->id,
            'effective_from' => now()->subHour(),
            'effective_until' => now()->addHour(),
            'approved_by_user_id' => $submitter->id,
            'created_by_user_id' => $submitter->id,
        ]);

        $service = app(ReportingWorkflowService::class);
        $workflow = $service->buildWorkflowForSubmission(
            $submitter,
            'inspection',
            $actingTeam->id,
        );

        $this->assertSame($actingTeam->id, $workflow['scope_team_id']);
        $this->assertSame($substitute->id, $workflow['next_action_user_id']);
        $this->assertSame($coverage->id, $workflow['next_action_duty_coverage_assignment_id']);
        $this->assertSame('team_temporary_coverage', $workflow['routing_reason_code']);

        $report = Report::query()->create([
            'report_uid' => 'inspection-duty-coverage',
            'display_id' => 'INS-DUTY-COVERAGE',
            'owner_user_id' => $submitter->id,
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'version' => 1,
            'revision' => 1,
            'payload' => [],
            'submitted_at' => now(),
            ...$workflow,
        ]);

        $this->assertTrue($service->canReview($report, $substitute));
        $this->assertFalse($service->canReview($report, $incumbent));
        $this->assertSame(
            [$substitute->id],
            $service->recipientUserIdsForNextAction($report),
        );
    }

    public function test_expired_coverage_revokes_the_explicit_substitute_assignment(): void
    {
        $homeTeam = Team::factory()->create();
        $actingTeam = Team::factory()->create();
        $submitter = User::factory()->create(['status' => 'Active']);
        $substitute = User::factory()->create(['status' => 'Active']);
        $aicRole = $this->assign($substitute, 'Assistant Incident Commander', $homeTeam->id);

        $coverage = DutyCoverageAssignment::query()->create([
            'user_id' => $substitute->id,
            'acting_team_id' => $actingTeam->id,
            'home_team_id' => $homeTeam->id,
            'acting_role_id' => $aicRole->id,
            'effective_from' => now()->subHours(2),
            'effective_until' => now()->subHour(),
            'approved_by_user_id' => $submitter->id,
            'created_by_user_id' => $submitter->id,
        ]);
        $report = Report::query()->create([
            'report_uid' => 'inspection-expired-coverage',
            'display_id' => 'INS-EXPIRED-COVERAGE',
            'owner_user_id' => $submitter->id,
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'workflow_stage' => 'review',
            'next_action_role' => 'Assistant Incident Commander',
            'next_action_user_id' => $substitute->id,
            'next_action_duty_coverage_assignment_id' => $coverage->id,
            'routing_reason_code' => 'team_temporary_coverage',
            'scope_team_id' => $actingTeam->id,
            'workflow_snapshot' => [
                'reviewRole' => 'Assistant Incident Commander',
                'resolvedReviewRole' => 'Assistant Incident Commander',
                'usedFallbackReview' => false,
                'scopeTeamId' => $actingTeam->id,
                'options' => ['useTeamScopedAic' => true],
            ],
            'approval_history' => [],
            'version' => 1,
            'revision' => 1,
            'payload' => [],
            'submitted_at' => now(),
        ]);

        $this->assertFalse(app(ReportingWorkflowService::class)->canReview($report, $substitute));
    }

    public function test_expired_coverage_reassigns_pending_report_to_current_team_reviewer(): void
    {
        $homeTeam = Team::factory()->create();
        $actingTeam = Team::factory()->create();
        $submitter = User::factory()->create(['status' => 'Active']);
        $incumbent = User::factory()->create(['status' => 'Active']);
        $substitute = User::factory()->create(['status' => 'Active']);
        $aicRole = $this->assign($incumbent, 'Assistant Incident Commander', $actingTeam->id);
        $this->assign($substitute, 'Assistant Incident Commander', $homeTeam->id, $aicRole);
        $coverage = DutyCoverageAssignment::query()->create([
            'user_id' => $substitute->id,
            'acting_team_id' => $actingTeam->id,
            'home_team_id' => $homeTeam->id,
            'acting_role_id' => $aicRole->id,
            'replaces_user_id' => $incumbent->id,
            'effective_from' => now()->subHours(2),
            'effective_until' => now()->subHour(),
            'approved_by_user_id' => $submitter->id,
            'created_by_user_id' => $submitter->id,
        ]);
        $report = Report::query()->create([
            'report_uid' => 'inspection-expired-handover',
            'display_id' => 'INS-EXPIRED-HANDOVER',
            'owner_user_id' => $submitter->id,
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'workflow_stage' => 'review',
            'next_action_role' => 'Assistant Incident Commander',
            'next_action_user_id' => $substitute->id,
            'next_action_duty_coverage_assignment_id' => $coverage->id,
            'routing_reason_code' => 'team_temporary_coverage',
            'scope_team_id' => $actingTeam->id,
            'workflow_snapshot' => [
                'options' => ['useTeamScopedAic' => true],
            ],
            'approval_history' => [],
            'version' => 1,
            'revision' => 1,
            'payload' => [],
            'submitted_at' => now(),
        ]);

        $result = app(ReportRoutingReconciliationService::class)->reconcile();

        $this->assertSame(1, $result['reassigned']);
        $this->assertSame($incumbent->id, $report->fresh()->next_action_user_id);
        $this->assertDatabaseHas('report_routing_events', [
            'report_id' => $report->id,
            'event_type' => 'routing_reconciled',
            'from_user_id' => $substitute->id,
            'to_user_id' => $incumbent->id,
        ]);
        $this->assertDatabaseHas('workflow_notifications', [
            'record_type' => 'report',
            'record_id' => $report->id,
            'event_type' => 'workflow_reassigned',
        ]);
    }

    public function test_active_coverage_sets_the_submission_team_for_every_managed_report_family(): void
    {
        $homeTeam = Team::factory()->create();
        $actingTeam = Team::factory()->create();
        $submitter = User::factory()->create(['status' => 'Active']);
        $aicReviewer = User::factory()->create(['status' => 'Active']);
        $icReviewer = User::factory()->create(['status' => 'Active']);
        $trtRole = $this->assign($submitter, 'Tactical Response Team', $homeTeam->id);
        $this->assign($aicReviewer, 'Assistant Incident Commander', $actingTeam->id);
        $this->assign($icReviewer, 'Incident Commander', $actingTeam->id);
        DutyCoverageAssignment::query()->create([
            'user_id' => $submitter->id,
            'acting_team_id' => $actingTeam->id,
            'home_team_id' => $homeTeam->id,
            'acting_role_id' => $trtRole->id,
            'effective_from' => now()->subHour(),
            'effective_until' => now()->addHour(),
            'approved_by_user_id' => $icReviewer->id,
            'created_by_user_id' => $icReviewer->id,
        ]);

        $service = app(ReportingWorkflowService::class);
        foreach (['inspection', 'erco', 'drill', 'fitness-test'] as $module) {
            $workflow = $service->buildWorkflowForSubmission($submitter, $module);
            $this->assertSame($actingTeam->id, $workflow['scope_team_id'], $module);
            $this->assertSame(
                $module === 'inspection' ? $aicReviewer->id : $icReviewer->id,
                $workflow['next_action_user_id'],
                $module,
            );
        }
    }

    private function assign(
        User $user,
        string $roleName,
        ?int $teamId,
        ?Role $role = null,
    ): Role {
        $permission = Permission::query()->firstOrCreate([
            'name' => 'reports.inspection.view',
            'guard_name' => 'web',
        ]);
        $role ??= Role::query()->firstOrCreate([
            'name' => $roleName,
            'guard_name' => 'web',
        ]);
        if (! $role->hasPermissionTo($permission)) {
            $role->givePermissionTo($permission);
        }
        UserRoleAssignment::query()->create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'scope_type' => $teamId ? RoleCatalog::SITE : RoleCatalog::GLOBAL,
            'team_id' => $teamId,
            'is_primary' => true,
        ]);

        return $role;
    }
}
