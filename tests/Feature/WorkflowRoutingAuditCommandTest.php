<?php

namespace Tests\Feature;

use App\Models\Report;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\UserRoleAssignment;
use App\Services\RoleCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class WorkflowRoutingAuditCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_reports_unlinked_operational_members_and_stranded_reports(): void
    {
        $team = Team::factory()->create(['name' => 'Audit Team']);
        TeamMember::query()->create([
            'team_id' => $team->id,
            'name' => 'Unlinked AIC',
            'role' => 'assistant incident commander',
        ]);
        $owner = User::factory()->create(['status' => 'Active']);
        Report::query()->create([
            'report_uid' => 'routing-audit-report',
            'display_id' => 'INS-ROUTING-AUDIT',
            'owner_user_id' => $owner->id,
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'workflow_stage' => 'review',
            'next_action_role' => 'Assistant Incident Commander',
            'scope_team_id' => $team->id,
            'workflow_snapshot' => [
                'usedFallbackReview' => false,
                'options' => ['useTeamScopedAic' => true],
            ],
            'approval_history' => [],
            'version' => 1,
            'revision' => 1,
            'payload' => [],
            'submitted_at' => now(),
        ]);

        $this->artisan('workflow:audit-routing')
            ->expectsOutputToContain('unlinked_operational_member')
            ->expectsOutputToContain('no_eligible_recipient')
            ->assertFailed();
    }

    public function test_audit_does_not_require_a_team_assignment_for_a_global_role(): void
    {
        $team = Team::factory()->create(['name' => 'Administrative Team']);
        $administrator = User::factory()->create(['status' => 'Active']);
        $role = Role::query()->firstOrCreate([
            'name' => 'System Administrator',
            'guard_name' => 'web',
        ]);
        UserRoleAssignment::query()->create([
            'user_id' => $administrator->id,
            'role_id' => $role->id,
            'scope_type' => RoleCatalog::GLOBAL,
            'is_primary' => true,
        ]);
        TeamMember::query()->create([
            'team_id' => $team->id,
            'user_id' => $administrator->id,
            'name' => $administrator->name,
            'role' => $role->name,
        ]);

        $this->artisan('workflow:audit-routing')
            ->expectsOutput('No workflow routing integrity issues found.')
            ->assertSuccessful();
    }
}
