<?php

namespace Tests\Feature;

use App\Models\Report;
use App\Models\Team;
use App\Models\User;
use App\Models\UserRoleAssignment;
use App\Services\RoleCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class WorkflowRoutingRepairCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_repair_is_dry_run_by_default_and_apply_is_explicit_and_audited(): void
    {
        $team = Team::factory()->create();
        $owner = User::factory()->create(['status' => 'Active']);
        $reviewer = User::factory()->create(['status' => 'Active']);
        $actor = User::factory()->create(['status' => 'Active']);
        $ic = Role::query()->firstOrCreate(['name' => 'Incident Commander', 'guard_name' => 'web']);
        $admin = Role::query()->firstOrCreate(['name' => 'System Administrator', 'guard_name' => 'web']);
        UserRoleAssignment::query()->create([
            'user_id' => $reviewer->id,
            'role_id' => $ic->id,
            'scope_type' => RoleCatalog::SITE,
            'team_id' => $team->id,
            'is_primary' => true,
        ]);
        UserRoleAssignment::query()->create([
            'user_id' => $actor->id,
            'role_id' => $admin->id,
            'scope_type' => RoleCatalog::GLOBAL,
            'is_primary' => true,
        ]);
        $report = Report::query()->create([
            'report_uid' => 'routing-repair-test',
            'display_id' => 'RPT-REPAIR',
            'owner_user_id' => $owner->id,
            'report_type' => 'erco',
            'status' => 'Submitted',
            'workflow_stage' => 'review',
            'next_action_role' => 'Incident Commander',
            'routing_reason_code' => 'no_eligible_recipient',
            'payload' => [],
            'approval_history' => [],
            'version' => 1,
            'submitted_at' => now(),
        ]);
        $arguments = [
            'report' => $report->id,
            '--team' => $team->id,
            '--user' => $reviewer->id,
            '--actor-user' => $actor->id,
            '--reason' => 'Validated legacy team and reviewer',
        ];

        $this->artisan('workflow:repair-report-routing', $arguments)->assertSuccessful();
        $this->assertNull($report->fresh()->next_action_user_id);

        $this->artisan('workflow:repair-report-routing', $arguments + ['--apply' => true])
            ->assertSuccessful();
        $this->assertDatabaseHas('reports', [
            'id' => $report->id,
            'scope_team_id' => $team->id,
            'next_action_user_id' => $reviewer->id,
        ]);
        $this->assertDatabaseHas('report_routing_events', [
            'report_id' => $report->id,
            'event_type' => 'manual_routing_repair',
            'to_user_id' => $reviewer->id,
            'created_by_user_id' => $actor->id,
        ]);
        $this->assertDatabaseHas('workflow_notifications', [
            'record_type' => 'report',
            'record_id' => $report->id,
            'event_type' => 'workflow_reassigned',
        ]);
    }
}
