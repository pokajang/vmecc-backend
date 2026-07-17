<?php

namespace Tests\Feature;

use App\Models\Report;
use App\Models\Setting;
use App\Models\User;
use App\Models\UserRoleAssignment;
use App\Models\WorkflowNotification;
use App\Services\RoleCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ReportingWorkflowRoleAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_permission_without_current_stage_role_is_denied_without_side_effects(): void
    {
        $owner = User::factory()->create(['status' => 'Active']);
        $actor = $this->workflowActor('Tactical Response Team', 'Wrong Report Actor');
        $report = $this->submittedReport($owner);

        $this->actingAs($actor)
            ->postJson($this->actionUrl($report, 'review'), [
                'version' => 1,
                'remarks' => 'Unauthorized attempt.',
            ])
            ->assertForbidden();

        $report->refresh();
        $this->assertSame('Submitted', $report->status);
        $this->assertSame('review', $report->workflow_stage);
        $this->assertSame([], $report->approval_history);
        $this->assertSame(0, WorkflowNotification::query()->count());
    }

    public function test_expired_stage_role_is_ignored_when_an_active_role_grants_report_access(): void
    {
        $owner = User::factory()->create(['status' => 'Active']);
        $actor = User::factory()->create(['status' => 'Active']);
        $this->assignRole($actor, 'Tactical Response Team', now()->subMonth(), now()->addMonth());
        $this->assignRole($actor, 'Incident Commander', now()->subMonth(), now()->subDay());
        $report = $this->submittedReport($owner);

        $this->actingAs($actor)
            ->postJson($this->actionUrl($report, 'review'), ['version' => 1])
            ->assertForbidden();

        $this->assertSame('review', $report->fresh()->workflow_stage);
    }

    public function test_persisted_snapshot_owns_in_flight_roles_after_settings_change(): void
    {
        $owner = User::factory()->create(['status' => 'Active']);
        $incidentCommander = $this->workflowActor('Incident Commander', 'Snapshot IC');
        $report = $this->submittedReport($owner);

        Setting::query()->create([
            'key' => 'reporting_workflow_rules',
            'value' => [
                'modules' => [
                    'erco' => [
                        'fallback' => [
                            'reviewRole' => 'Contract Manager',
                            'fallbackReviewRole' => 'Contract Manager',
                            'approveRole' => 'Contract Manager',
                        ],
                        'options' => [
                            'preventSelfReview' => true,
                            'preventSelfApprove' => true,
                        ],
                    ],
                ],
            ],
        ]);

        $this->actingAs($incidentCommander)
            ->postJson($this->actionUrl($report, 'review'), [
                'version' => 1,
                'remarks' => 'Reviewed under submission snapshot.',
            ])
            ->assertOk()
            ->assertJsonPath('data.workflowStage', 'approve')
            ->assertJsonPath('data.nextActionRole', 'Incident Commander');

        $this->postJson($this->actionUrl($report, 'approve'), [
            'version' => 2,
            'remarks' => 'Approved under submission snapshot.',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'Approved')
            ->assertJsonPath('data.workflowStage', 'done');

        $report->refresh();
        $this->assertSame('Incident Commander', $report->workflow_snapshot['approveRole']);
        $this->assertSame(['Reviewed', 'Approved'], array_column($report->approval_history, 'action'));
        $this->assertSame(
            [(string) $incidentCommander->id, (string) $incidentCommander->id],
            array_column($report->approval_history, 'byUserId'),
        );
    }

    public function test_wrong_stage_and_stale_version_are_rejected_without_mutation(): void
    {
        $owner = User::factory()->create(['status' => 'Active']);
        $incidentCommander = $this->workflowActor('Incident Commander', 'Workflow IC');
        $report = $this->submittedReport($owner);

        $this->actingAs($incidentCommander)
            ->postJson($this->actionUrl($report, 'approve'), ['version' => 1])
            ->assertConflict()
            ->assertJsonPath('code', 'REPORT_INVALID_TRANSITION');

        $this->postJson($this->actionUrl($report, 'review'), ['version' => 2])
            ->assertConflict()
            ->assertJsonPath('code', 'REPORT_VERSION_CONFLICT');

        $report->refresh();
        $this->assertSame(1, $report->version);
        $this->assertSame('Submitted', $report->status);
        $this->assertSame([], $report->approval_history);
    }

    public function test_system_administrator_overrides_stage_role_but_not_workflow_order(): void
    {
        $owner = User::factory()->create(['status' => 'Active']);
        $administrator = $this->workflowActor('System Administrator', 'Workflow Administrator');
        $report = $this->submittedReport($owner);

        $this->actingAs($administrator)
            ->postJson($this->actionUrl($report, 'approve'), ['version' => 1])
            ->assertConflict()
            ->assertJsonPath('code', 'REPORT_INVALID_TRANSITION');

        $this->postJson($this->actionUrl($report, 'review'), ['version' => 1])
            ->assertOk()
            ->assertJsonPath('data.workflowStage', 'approve');

        $this->postJson($this->actionUrl($report, 'approve'), ['version' => 2])
            ->assertOk()
            ->assertJsonPath('data.status', 'Approved');
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
            'name' => 'reports.erco.view',
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
            'scope_type' => RoleCatalog::GLOBAL,
            'team_id' => null,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'is_primary' => ! $user->roleAssignments()->exists(),
        ]);
    }

    private function submittedReport(User $owner): Report
    {
        $uid = 'erco-rbac-'.strtolower((string) str()->ulid());

        return Report::query()->create([
            'report_uid' => $uid,
            'display_id' => 'ERCO-RBAC-'.random_int(1000, 9999),
            'owner_user_id' => $owner->id,
            'report_type' => 'erco',
            'status' => 'Submitted',
            'workflow_stage' => 'review',
            'workflow_snapshot' => [
                'moduleKey' => 'erco',
                'submitterRole' => 'Tactical Response Team',
                'reviewRole' => 'Incident Commander',
                'fallbackReviewRole' => 'Incident Commander',
                'approveRole' => 'Incident Commander',
                'resolvedReviewRole' => 'Incident Commander',
                'usedFallbackReview' => true,
                'scopeTeamId' => null,
                'options' => [
                    'useTeamScopedAic' => true,
                    'allowSubmitWithoutTeam' => true,
                    'allowIcFallbackReview' => true,
                    'preventSelfReview' => true,
                    'preventSelfApprove' => true,
                ],
            ],
            'next_action_role' => 'Incident Commander',
            'approval_history' => [],
            'scope_team_id' => null,
            'version' => 1,
            'revision' => 1,
            'payload' => [
                'incidentDate' => '2026-07-17',
                'location' => 'Workflow RBAC location',
                'details' => 'Workflow role assignment audit.',
            ],
            'submitted_at' => now(),
        ]);
    }

    private function actionUrl(Report $report, string $action): string
    {
        return "/api/reports/{$report->report_uid}/{$action}";
    }
}
