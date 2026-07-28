<?php

namespace Tests\Feature;

use App\Models\DutyCoverageAssignment;
use App\Models\Team;
use App\Models\User;
use App\Models\UserRoleAssignment;
use App\Services\OvertimeEligibilityService;
use App\Services\RoleCatalog;
use App\Services\WorkflowRecipientResolver;
use App\Services\WorkflowSubmissionContextResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class WorkflowRoleTeamContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_submission_context_uses_active_cross_team_duty_for_trt_and_aic(): void
    {
        $homeTeam = Team::factory()->create(['name' => 'Home Team']);
        $actingTeam = Team::factory()->create(['name' => 'Acting Team']);

        foreach (['Tactical Response Team', 'Assistant Incident Commander'] as $roleName) {
            $user = User::factory()->create(['status' => 'Active']);
            $role = $this->assign($user, $roleName, $homeTeam, RoleCatalog::SITE);
            $coverage = $this->cover($user, $role, $homeTeam, $actingTeam);

            $context = app(WorkflowSubmissionContextResolver::class)->resolve($user, now());

            $this->assertSame($actingTeam->id, $context['teamId'], $roleName);
            $this->assertSame($roleName, $context['applicantRole'], $roleName);
            $this->assertSame('temporary_coverage', $context['routingSource'], $roleName);
            $this->assertSame($coverage->id, $context['dutyCoverageAssignmentId'], $roleName);
        }
    }

    public function test_team_recipient_resolution_honors_substitution_and_excludes_other_teams(): void
    {
        $homeTeam = Team::factory()->create(['name' => 'Bravo']);
        $actingTeam = Team::factory()->create(['name' => 'Alpha']);
        $otherTeam = Team::factory()->create(['name' => 'Charlie']);
        $incumbent = User::factory()->create(['status' => 'Active']);
        $substitute = User::factory()->create(['status' => 'Active']);
        $wrongTeamReviewer = User::factory()->create(['status' => 'Active']);

        $aicRole = $this->assign(
            $incumbent,
            'Assistant Incident Commander',
            $actingTeam,
            RoleCatalog::SITE,
        );
        $this->assign($substitute, 'Assistant Incident Commander', $homeTeam, RoleCatalog::SITE, $aicRole);
        $this->assign(
            $wrongTeamReviewer,
            'Assistant Incident Commander',
            $otherTeam,
            RoleCatalog::SITE,
            $aicRole,
        );
        $this->cover($substitute, $aicRole, $homeTeam, $actingTeam, $incumbent);

        $recipientIds = app(WorkflowRecipientResolver::class)
            ->resolveForWorkflowRole('Assistant Incident Commander', $actingTeam->id)
            ->pluck('userId')
            ->all();

        $this->assertSame([$substitute->id], $recipientIds);
    }

    public function test_overtime_eligibility_includes_the_effective_acting_role(): void
    {
        $homeTeam = Team::factory()->create();
        $actingTeam = Team::factory()->create();
        $user = User::factory()->create(['status' => 'Active']);
        $aicRole = $this->assign(
            $user,
            'Assistant Incident Commander',
            $homeTeam,
            RoleCatalog::SITE,
        );
        $trtRole = Role::query()->firstOrCreate([
            'name' => 'Tactical Response Team',
            'guard_name' => 'web',
        ]);
        $this->cover($user, $trtRole, $homeTeam, $actingTeam);

        $eligibility = app(OvertimeEligibilityService::class)->resolveForUser($user, now());

        $this->assertTrue($eligibility['eligible']);
        $this->assertContains('Tactical Response Team', $eligibility['userRoles']);
        $this->assertContains($aicRole->name, $eligibility['userRoles']);
    }

    private function assign(
        User $user,
        string $roleName,
        Team $team,
        string $scope,
        ?Role $role = null,
    ): Role {
        $role ??= Role::query()->firstOrCreate([
            'name' => $roleName,
            'guard_name' => 'web',
        ]);
        UserRoleAssignment::query()->create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'scope_type' => $scope,
            'team_id' => $team->id,
            'is_primary' => true,
        ]);

        return $role;
    }

    private function cover(
        User $user,
        Role $role,
        Team $homeTeam,
        Team $actingTeam,
        ?User $incumbent = null,
    ): DutyCoverageAssignment {
        return DutyCoverageAssignment::query()->create([
            'user_id' => $user->id,
            'acting_team_id' => $actingTeam->id,
            'home_team_id' => $homeTeam->id,
            'acting_role_id' => $role->id,
            'replaces_user_id' => $incumbent?->id,
            'effective_from' => now()->subHour(),
            'effective_until' => now()->addHour(),
            'approved_by_user_id' => $user->id,
            'created_by_user_id' => $user->id,
        ]);
    }

    public function test_scoped_recipient_resolution_never_falls_back_across_teams_or_without_a_team(): void
    {
        $alpha = Team::factory()->create(['name' => 'Resolver Alpha']);
        $bravo = Team::factory()->create(['name' => 'Resolver Bravo']);
        $permanentAlpha = User::factory()->create(['status' => 'Active']);
        $actingAlpha = User::factory()->create(['status' => 'Active']);
        $malformedGlobal = User::factory()->create(['status' => 'Active']);
        $incidentCommander = Role::query()->firstOrCreate([
            'name' => 'Incident Commander',
            'guard_name' => 'web',
        ]);

        UserRoleAssignment::query()->create([
            'user_id' => $permanentAlpha->id,
            'role_id' => $incidentCommander->id,
            'scope_type' => RoleCatalog::SITE,
            'team_id' => $alpha->id,
            'is_primary' => true,
        ]);
        UserRoleAssignment::query()->create([
            'user_id' => $malformedGlobal->id,
            'role_id' => $incidentCommander->id,
            'scope_type' => RoleCatalog::GLOBAL,
            'team_id' => null,
            'is_primary' => true,
        ]);
        DutyCoverageAssignment::query()->create([
            'user_id' => $actingAlpha->id,
            'acting_team_id' => $alpha->id,
            'acting_role_id' => $incidentCommander->id,
            'effective_from' => now()->subHour(),
            'effective_until' => now()->addHour(),
            'reason' => 'Resolver boundary test',
            'approved_by_user_id' => $malformedGlobal->id,
            'created_by_user_id' => $malformedGlobal->id,
        ]);

        $resolver = app(WorkflowRecipientResolver::class);

        $this->assertSame([], $resolver->resolveRole('Incident Commander')->all());
        $this->assertSame([], $resolver->resolveRole('Incident Commander', $bravo->id)->all());
        $this->assertEqualsCanonicalizing(
            [$permanentAlpha->id, $actingAlpha->id],
            $resolver->resolveRole('Incident Commander', $alpha->id)->pluck('userId')->all(),
        );
    }

    public function test_organization_role_resolution_ignores_malformed_temporary_coverage(): void
    {
        $team = Team::factory()->create();
        $permanent = User::factory()->create(['status' => 'Active']);
        $malformedSubstitute = User::factory()->create(['status' => 'Active']);
        $role = Role::query()->firstOrCreate([
            'name' => 'Finance',
            'guard_name' => 'web',
        ]);
        UserRoleAssignment::query()->create([
            'user_id' => $permanent->id,
            'role_id' => $role->id,
            'scope_type' => RoleCatalog::OFFICE,
            'is_primary' => true,
        ]);
        DutyCoverageAssignment::query()->create([
            'user_id' => $malformedSubstitute->id,
            'acting_team_id' => $team->id,
            'acting_role_id' => $role->id,
            'effective_from' => now()->subHour(),
            'effective_until' => now()->addHour(),
            'approved_by_user_id' => $permanent->id,
            'created_by_user_id' => $permanent->id,
        ]);

        $recipientIds = app(WorkflowRecipientResolver::class)
            ->resolveRole('Finance')
            ->pluck('userId')
            ->all();

        $this->assertSame([$permanent->id], $recipientIds);
    }
}
