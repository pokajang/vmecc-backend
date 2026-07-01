<?php

namespace Tests\Feature;

use App\Models\Team;
use App\Models\User;
use App\Models\UserRoleAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class InspectionWorkflowGovernanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_team_aic_reviews_and_global_ic_approves_inspection(): void
    {
        $team = Team::factory()->create(['name' => 'Alpha TRT']);
        $otherTeam = Team::factory()->create(['name' => 'Bravo TRT']);
        $submitter = User::factory()->create(['status' => 'active', 'name' => 'TRT Submitter']);
        $sameTeamAic = User::factory()->create(['status' => 'active', 'name' => 'Same Team AIC']);
        $otherTeamAic = User::factory()->create(['status' => 'active', 'name' => 'Other Team AIC']);
        $ic = User::factory()->create(['status' => 'active', 'name' => 'Incident Commander']);

        $this->assignWorkflowRole($submitter, 'Tactical Response Team', $team->id, true);
        $this->assignWorkflowRole($sameTeamAic, 'Assistant Incident Commander', $team->id);
        $this->assignWorkflowRole($otherTeamAic, 'Assistant Incident Commander', $otherTeam->id);
        $this->assignWorkflowRole($ic, 'Incident Commander');

        $this->actingAs($submitter);
        $create = $this->postJson('/api/reports', $this->reportPayload('INS-WF-AIC-001'));
        $create->assertCreated()
            ->assertJsonPath('data.status', 'Submitted')
            ->assertJsonPath('data.workflowStage', 'review')
            ->assertJsonPath('data.nextActionRole', 'Assistant Incident Commander')
            ->assertJsonPath('data.scopeTeamId', $team->id)
            ->assertJsonPath('data.canReview', false)
            ->assertJsonPath('data.canApprove', false);

        $reportUid = (string) $create->json('data.id');

        $this->actingAs($otherTeamAic);
        $this->postJson("/api/reports/{$reportUid}/review", ['version' => 1])
            ->assertForbidden();

        $this->actingAs($sameTeamAic);
        $review = $this->postJson("/api/reports/{$reportUid}/review", [
            'version' => 1,
            'remarks' => 'AIC checked the report.',
        ]);
        $review->assertOk()
            ->assertJsonPath('data.status', 'Reviewed')
            ->assertJsonPath('data.workflowStage', 'approve')
            ->assertJsonPath('data.nextActionRole', 'Incident Commander');

        $this->actingAs($ic);
        $this->postJson("/api/reports/{$reportUid}/approve", [
            'version' => 2,
            'remarks' => 'IC final approval.',
        ])->assertOk()
            ->assertJsonPath('data.status', 'Approved')
            ->assertJsonPath('data.workflowStage', 'done')
            ->assertJsonPath('data.nextActionRole', null);
    }

    public function test_ic_reviews_and_approves_when_no_same_team_aic_exists(): void
    {
        $team = Team::factory()->create(['name' => 'Fallback TRT']);
        $submitter = User::factory()->create(['status' => 'active', 'name' => 'Fallback Submitter']);
        $ic = User::factory()->create(['status' => 'active', 'name' => 'Fallback IC']);

        $this->assignWorkflowRole($submitter, 'Tactical Response Team', $team->id, true);
        $this->assignWorkflowRole($ic, 'Incident Commander');

        $this->actingAs($submitter);
        $create = $this->postJson('/api/reports', $this->reportPayload('INS-WF-IC-001'));
        $create->assertCreated()
            ->assertJsonPath('data.status', 'Submitted')
            ->assertJsonPath('data.workflowStage', 'review')
            ->assertJsonPath('data.nextActionRole', 'Incident Commander')
            ->assertJsonPath('data.canReview', false);

        $reportUid = (string) $create->json('data.id');

        $this->postJson("/api/reports/{$reportUid}/review", ['version' => 1])
            ->assertForbidden();

        $this->actingAs($ic);
        $this->postJson("/api/reports/{$reportUid}/review", ['version' => 1])
            ->assertOk()
            ->assertJsonPath('data.status', 'Reviewed')
            ->assertJsonPath('data.nextActionRole', 'Incident Commander')
            ->assertJsonPath('data.canApprove', true);

        $this->postJson("/api/reports/{$reportUid}/approve", ['version' => 2])
            ->assertOk()
            ->assertJsonPath('data.status', 'Approved')
            ->assertJsonPath('data.workflowStage', 'done');
    }

    private function reportPayload(string $displayId): array
    {
        return [
            'display_id' => $displayId,
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => [
                'incidentType' => 'General Inspection',
                'location' => 'Fire Rescue Tender (FRT)',
                'selectedLocation' => 'Fire Rescue Tender (FRT)',
                'mainLocation' => 'FRT',
                'description' => 'Workflow governance smoke report.',
                'photos' => [],
                'checklist' => [
                    [
                        'id' => 'general-inspection:workflow',
                        'label' => 'Workflow check',
                        'inspectionType' => 'General Inspection',
                        'selected' => true,
                    ],
                ],
            ],
        ];
    }

    private function assignWorkflowRole(
        User $user,
        string $roleName,
        ?int $teamId = null,
        bool $primary = false,
    ): void {
        $permission = Permission::query()->firstOrCreate([
            'name' => 'reports.inspection.view',
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
            'scope_type' => $teamId ? 'site' : 'global',
            'team_id' => $teamId,
            'is_primary' => $primary,
        ]);
    }
}
