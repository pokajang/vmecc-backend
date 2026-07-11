<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ReportDraftApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_draft_crud_flow(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->grantPermission($user, 'reports.erco.view');
        $this->actingAs($user);

        $this->getJson('/api/reports/draft?report_type=erco')
            ->assertOk()
            ->assertJsonPath('data', null);

        $save = $this->postJson('/api/reports/draft', [
            'report_type' => 'erco',
            'payload' => [
                'incidentType' => 'Special Assistance',
                'location' => ['Zone 1', 'Zone 2'],
                'savedAt' => now()->toIso8601String(),
            ],
        ]);
        $save->assertCreated();
        $save->assertJsonPath('data.report_type', 'erco');
        $save->assertJsonPath('data.payload.incidentType', 'Special Assistance');
        $save->assertJsonPath('data.version', 1);

        $this->getJson('/api/reports/draft?report_type=erco')
            ->assertOk()
            ->assertJsonPath('data.report_type', 'erco')
            ->assertJsonPath('data.payload.location.0', 'Zone 1');

        $this->deleteJson('/api/reports/draft?report_type=erco')
            ->assertOk();

        $this->getJson('/api/reports/draft?report_type=erco')
            ->assertOk()
            ->assertJsonPath('data', null);
    }

    public function test_report_draft_is_user_scoped(): void
    {
        $owner = User::factory()->create(['status' => 'active']);
        $other = User::factory()->create(['status' => 'active']);
        $this->grantPermission($owner, 'reports.drill.view');

        $this->actingAs($owner)->postJson('/api/reports/draft', [
            'report_type' => 'drill',
            'payload' => [
                'incidentType' => 'Drill Response',
            ],
        ])->assertCreated();

        $this->actingAs($other)->getJson('/api/reports/draft?report_type=drill')
            ->assertOk()
            ->assertJsonPath('data', null);
    }

    public function test_inspection_fire_extinguisher_draft_allows_incomplete_check_rows(): void
    {
        $user = User::factory()->create([
            'name' => 'Draft Inspector',
            'status' => 'active',
        ]);
        $this->grantPermission($user, 'reports.inspection.view');
        $this->actingAs($user);

        $response = $this->postJson('/api/reports/draft', [
            'report_type' => 'inspection',
            'payload' => [
                'incidentType' => 'Fire Extinguisher Inspection',
                'location' => 'Zone 2 > Main Sub Station',
                'mainLocation' => 'Main Sub Station',
                'fireExtinguisherInspectionDate' => '2026-07-07',
                'fireExtinguisherChecks' => [
                    [
                        'id' => 'fe:msl-005',
                        'catalogId' => 5,
                        'mainLocation' => 'Main Sub Station',
                        'idLocNo' => 'MSL1-005',
                        'barcodeNo' => 'EE072021Z168999',
                        'feType' => 'CO2 5KG',
                        'certificationValidity' => '2025-12-31',
                        'physicalCondition' => 'Good',
                        'signageCondition' => '',
                        'boxKeyAvailability' => '',
                        'boxGlassAvailability' => '',
                        'operationalCondition' => '',
                    ],
                ],
            ],
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.report_type', 'inspection');
        $response->assertJsonPath('data.payload.fireExtinguisherChecks.0.idLocNo', 'MSL1-005');
        $response->assertJsonPath('data.payload.fireExtinguisherChecks.0.signageCondition', '');
    }

    public function test_exact_draft_updates_use_optimistic_versioning(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->grantPermission($user, 'reports.inspection.view');
        $this->actingAs($user);
        $created = $this->postJson('/api/reports/drafts', [
            'report_type' => 'inspection',
            'payload' => ['incidentType' => 'General Inspection', 'description' => 'Initial'],
        ])->assertCreated();
        $draftId = (string) $created->json('data.draft_id');

        $updated = $this->putJson('/api/reports/drafts/'.$draftId, [
            'base_version' => 1,
            'payload' => ['incidentType' => 'General Inspection', 'description' => 'Newer tab'],
        ]);
        $updated->assertOk();
        $updated->assertJsonPath('data.version', 2);
        $updated->assertJsonPath('data.payload.description', 'Newer tab');

        $stale = $this->putJson('/api/reports/drafts/'.$draftId, [
            'base_version' => 1,
            'payload' => ['incidentType' => 'General Inspection', 'description' => 'Stale tab'],
        ]);
        $stale->assertConflict();
        $stale->assertJsonPath('code', 'report_draft_version_conflict');
        $stale->assertJsonPath('currentDraft.version', 2);
        $stale->assertJsonPath('currentDraft.payload.description', 'Newer tab');

        $this->getJson('/api/reports/drafts/'.$draftId)
            ->assertOk()
            ->assertJsonPath('data.payload.description', 'Newer tab')
            ->assertJsonPath('data.version', 2);
    }

    public function test_draft_conflict_data_is_not_exposed_to_another_user(): void
    {
        $owner = User::factory()->create(['status' => 'active']);
        $other = User::factory()->create(['status' => 'active']);
        $this->grantPermission($owner, 'reports.inspection.view');
        $created = $this->actingAs($owner)->postJson('/api/reports/drafts', [
            'report_type' => 'inspection',
            'payload' => ['incidentType' => 'General Inspection', 'description' => 'Owner only'],
        ])->assertCreated();
        $draftId = (string) $created->json('data.draft_id');

        $this->actingAs($other)->putJson('/api/reports/drafts/'.$draftId, [
            'base_version' => 1,
            'payload' => ['incidentType' => 'General Inspection', 'description' => 'Intruder'],
        ])->assertNotFound()->assertJsonMissingPath('currentDraft');
    }

    private function grantPermission(User $user, string $permissionName): void
    {
        $permission = Permission::query()->firstOrCreate([
            'name' => $permissionName,
            'guard_name' => 'web',
        ]);
        $role = Role::query()->firstOrCreate([
            'name' => 'Report draft test '.$permissionName,
            'guard_name' => 'web',
        ]);
        if (! $role->hasPermissionTo($permission)) {
            $role->givePermissionTo($permission);
        }
        $user->assignRole($role);
    }
}
