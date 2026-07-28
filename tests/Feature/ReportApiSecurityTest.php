<?php

namespace Tests\Feature;

use App\Models\Report;
use App\Models\Team;
use App\Models\User;
use App\Models\UserRoleAssignment;
use App\Services\RoleCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ReportApiSecurityTest extends TestCase
{
    use RefreshDatabase;

    private ?Team $workflowTeam = null;

    public function test_guest_cannot_access_report_endpoints(): void
    {
        $this->getJson('/api/reports')->assertStatus(401);
        $this->postJson('/api/reports', [])->assertStatus(401);
    }

    public function test_inspection_all_scope_lists_records_from_other_owners(): void
    {
        $owner = User::factory()->create(['status' => 'active']);
        $otherOwner = User::factory()->create(['status' => 'active']);
        $this->grantInspectionPermission($owner);

        Report::query()->create([
            'report_uid' => 'inspection-owner-record',
            'display_id' => 'INS-OWNER-001',
            'owner_user_id' => $owner->id,
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'version' => 1,
            'revision' => 1,
            'payload' => ['incidentType' => 'Hydraulic Rescue Tools Inspection', 'location' => 'FRT'],
            'submitted_at' => now(),
        ]);
        Report::query()->create([
            'report_uid' => 'inspection-other-record',
            'display_id' => 'INS-OTHER-001',
            'owner_user_id' => $otherOwner->id,
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'version' => 1,
            'revision' => 1,
            'payload' => ['incidentType' => 'Hydraulic Rescue Tools Inspection', 'location' => 'Store'],
            'submitted_at' => now(),
        ]);

        $this->actingAs($owner);

        $mine = $this->getJson('/api/reports?reportType=inspection');
        $mine->assertOk();
        $this->assertSame(['INS-OWNER-001'], collect($mine->json('data'))->pluck('displayId')->all());

        $all = $this->getJson('/api/reports?reportType=inspection&scope=all');
        $all->assertOk();
        $this->assertNotContains(false, collect($all->json('data'))->pluck('canDownloadPdf')->all(), true);
        $this->assertEqualsCanonicalizing(
            ['INS-OWNER-001', 'INS-OTHER-001'],
            collect($all->json('data'))->pluck('displayId')->all(),
        );
    }

    public function test_owner_without_module_permission_receives_no_pdf_capability(): void
    {
        $owner = User::factory()->create(['status' => 'active']);
        $report = Report::query()->create([
            'report_uid' => 'erco-owner-without-module-permission',
            'display_id' => 'ERCO-NO-PERMISSION',
            'owner_user_id' => $owner->id,
            'report_type' => 'erco',
            'status' => 'Submitted',
            'version' => 1,
            'revision' => 1,
            'payload' => $this->validErcoPayload('Zone N'),
            'submitted_at' => now(),
        ]);

        $this->actingAs($owner)
            ->getJson("/api/reports/{$report->report_uid}")
            ->assertOk()
            ->assertJsonPath('data.canDownloadPdf', false)
            ->assertJsonPath('data.recordActions.edit.allowed', false)
            ->assertJsonPath('data.recordActions.delete.allowed', false);
    }

    public function test_user_cannot_transition_other_users_report(): void
    {
        $owner = User::factory()->create(['status' => 'active']);
        $intruder = User::factory()->create(['status' => 'active']);
        $this->assignWorkflowRole($owner, 'ERCO Reporter', 'reports.erco.view');

        $this->actingAs($owner);
        $created = $this->postJson('/api/reports', [
            'display_id' => 'ERCO-SEC-001',
            'report_type' => 'erco',
            'status' => 'Submitted',
            'payload' => $this->validErcoPayload('Zone S'),
        ]);
        $created->assertCreated();
        $reportUid = (string) $created->json('data.id');

        $this->actingAs($intruder);
        $this->postJson("/api/reports/{$reportUid}/review", [
            'version' => 1,
            'remarks' => 'Intruder review attempt',
        ])->assertForbidden();
    }

    public function test_invalid_transition_is_rejected(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->assignWorkflowRole($user, 'Incident Commander', 'reports.drill.view');
        $this->actingAs($user);

        $created = $this->postJson('/api/reports', [
            'display_id' => 'DRL-SEC-002',
            'report_type' => 'drill',
            'status' => 'Submitted',
            'payload' => $this->validDrillPayload('Zone D'),
        ]);
        $created->assertCreated();
        $reportUid = (string) $created->json('data.id');

        $approve = $this->postJson("/api/reports/{$reportUid}/approve", [
            'version' => 1,
            'remarks' => 'Invalid direct approve',
        ]);
        $approve->assertStatus(409);
        $approve->assertJsonPath('code', 'REPORT_INVALID_TRANSITION');
    }

    public function test_owner_can_delete_report_regardless_of_status(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $ic = User::factory()->create(['status' => 'active']);
        $this->assignWorkflowRole($user, 'Fitness Reporter', 'reports.fitness.view');
        $this->assignWorkflowRole($ic, 'Incident Commander', 'reports.fitness.view');
        $this->actingAs($user);

        $created = $this->postJson('/api/reports', [
            'display_id' => 'FIT-SEC-003',
            'report_type' => 'fitness-test',
            'status' => 'Submitted',
            'payload' => $this->validFitnessPayload('Zone F'),
        ]);
        $created->assertCreated();
        $reportUid = (string) $created->json('data.id');

        $this->actingAs($ic);
        $this->postJson("/api/reports/{$reportUid}/review", [
            'version' => 1,
            'remarks' => 'Reviewed',
        ])->assertOk();

        $this->postJson("/api/reports/{$reportUid}/approve", [
            'version' => 2,
            'remarks' => 'Approved',
        ])->assertOk();

        $this->actingAs($user);
        $delete = $this->deleteJson("/api/reports/{$reportUid}");
        $delete->assertNoContent();
    }

    private function validErcoPayload(string $location): array
    {
        return [
            'schemaVersion' => 1,
            'incidentDate' => '2026-07-13',
            'incidentTime' => '09:00',
            'weather' => 'Clear',
            'incidentType' => 'Fire',
            'location' => $location,
            'details' => 'Security workflow test details.',
            'summary' => 'Security workflow test summary.',
            'respondingTeam' => [
                'attendance' => [['memberId' => 'member-1', 'name' => 'Responder One']],
            ],
            'chronology' => [['time' => '09:00', 'action' => 'Response started.']],
            'postIncidentAnalysis' => [
                'strengths' => ['Prompt mobilisation'],
                'photos' => [[
                    'id' => 'required-photo-1',
                    'url' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
                ]],
            ],
        ];
    }

    private function validDrillPayload(string $location): array
    {
        return [
            'schemaVersion' => 2,
            'reportDate' => '2026-07-13',
            'reportTime' => '09:00',
            'weather' => 'Clear',
            'incidentType' => 'Fire Drill',
            'location' => $location,
            'details' => 'Security drill scenario.',
            'summary' => 'Security drill summary.',
            'chronology' => [['time' => '09:00', 'action' => 'Exercise started.']],
            'postIncidentAnalysis' => ['photos' => []],
        ];
    }

    private function validFitnessPayload(string $location): array
    {
        return [
            'schemaVersion' => 1,
            'reportDate' => '2026-07-13',
            'reportTime' => '09:00',
            'weather' => 'Routine',
            'incidentType' => 'Endurance Test',
            'location' => $location,
            'details' => 'Security fitness test details.',
            'summary' => 'Security fitness test summary.',
            'chronology' => [['time' => '09:00', 'action' => 'Fitness test started.']],
        ];
    }

    private function grantInspectionPermission(User $user): void
    {
        $role = Role::query()->firstOrCreate([
            'name' => 'Inspection Scope Tester',
            'guard_name' => 'web',
        ]);
        foreach (['reports.inspection.view', 'reports.inspection.conduct'] as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName, 'guard_name' => 'web']);
            if (! $role->hasPermissionTo($permission)) {
                $role->givePermissionTo($permission);
            }
        }
        $user->assignRole($role);
    }

    private function assignWorkflowRole(User $user, string $roleName, string $permissionName): void
    {
        $permission = Permission::query()->firstOrCreate([
            'name' => $permissionName,
            'guard_name' => 'web',
        ]);
        $role = Role::query()->firstOrCreate([
            'name' => $roleName,
            'guard_name' => 'web',
        ]);
        if (! $role->hasPermissionTo($permission)) {
            $role->givePermissionTo($permission);
        }
        $this->workflowTeam ??= Team::factory()->create([
            'name' => 'Report Security Workflow Team',
        ]);

        UserRoleAssignment::query()->create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'scope_type' => RoleCatalog::SITE,
            'team_id' => $this->workflowTeam->id,
            'is_primary' => true,
        ]);
    }
}
