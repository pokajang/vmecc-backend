<?php

namespace Tests\Feature;

use App\Models\InspectionCheckRow;
use App\Models\InspectionFireExtinguisher;
use App\Models\Report;
use App\Models\User;
use Database\Seeders\InspectionFireExtinguisherCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class InspectionFireExtinguisherInspectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_fire_extinguisher_catalog_returns_seeded_rows_by_selected_area(): void
    {
        $this->seed(InspectionFireExtinguisherCatalogSeeder::class);
        $user = $this->actingAsInspectionUser();

        $response = $this->getJson('/api/inspection/fire-extinguishers?mainLocation=Manjung%20Hub');

        $response->assertOk();
        $this->assertSame('database', $response->json('meta.source'));
        $this->assertCount(23, $response->json('data'));
        $this->assertSame(false, $response->json('data.0.canEdit'));
        $this->assertSame('Manjung Hub', $response->json('data.0.mainLocation'));

        $search = $this->getJson('/api/inspection/fire-extinguishers?mainLocation=Manjung%20Hub&search=ADO-003');
        $search->assertOk();
        $this->assertSame('ADO-003', $search->json('data.0.idLocNo'));
        $this->assertSame('CO2 5KG', $search->json('data.0.feType'));
        $this->assertSame($user->id, auth()->id());

        $this->assertSame(2, InspectionFireExtinguisher::query()
            ->where('id_loc_no', 'ADO-007')
            ->where('barcode_no', 'SR072015Y133879')
            ->count());
        $this->assertSame(2, InspectionFireExtinguisher::query()->whereNull('id_loc_no')->count());
        $this->assertGreaterThan(0, InspectionFireExtinguisher::query()->where('fe_type', 'like', 'CO2%')->count());
        $this->assertSame(0, InspectionFireExtinguisher::query()->where('fe_type', 'like', "%CO\u{00B2}%")->count());

        $removed = InspectionFireExtinguisher::query()->where('source_row_number', 517)->firstOrFail();
        $this->assertNull($removed->certification_validity);
        $this->assertSame('Removed', $removed->certification_validity_raw);
    }

    public function test_custom_fire_extinguisher_can_be_created_updated_and_archived(): void
    {
        $this->actingAsInspectionUser();

        $created = $this->postJson('/api/inspection/fire-extinguishers', [
            'zone' => 'QA',
            'mainLocation' => 'QA Yard',
            'subLocation' => 'Pump Bay',
            'idLocNo' => 'QA-001',
            'barcodeNo' => 'BAR-QA-001',
            'feType' => 'DP 6KG',
            'certificationValidity' => '2026-12-31',
            'certificationValidityRaw' => '2026-12-31',
            'daysLeftToExpire' => '185',
        ]);

        $created->assertCreated();
        $created->assertJsonPath('data.equipmentSource', 'custom');
        $id = (int) $created->json('data.id');

        $this->patchJson("/api/inspection/fire-extinguishers/{$id}", [
            'zone' => 'QA',
            'mainLocation' => 'QA Yard',
            'subLocation' => 'Pump Bay',
            'idLocNo' => 'QA-001A',
            'barcodeNo' => 'BAR-QA-001',
            'feType' => 'CO2 5KG',
        ])->assertOk()->assertJsonPath('data.idLocNo', 'QA-001A');

        $this->deleteJson("/api/inspection/fire-extinguishers/{$id}")->assertNoContent();
        $this->assertFalse(InspectionFireExtinguisher::query()->findOrFail($id)->is_active);
    }

    public function test_seeded_fire_extinguisher_rows_are_protected_for_regular_inspection_users(): void
    {
        $this->seed(InspectionFireExtinguisherCatalogSeeder::class);
        $this->actingAsInspectionUser();
        $seed = InspectionFireExtinguisher::query()->where('source', 'seed')->firstOrFail();

        $this->deleteJson("/api/inspection/fire-extinguishers/{$seed->id}")
            ->assertStatus(403)
            ->assertJsonPath('code', 'INSPECTION_FIRE_EXTINGUISHER_SEED_PROTECTED');
    }

    public function test_fire_extinguisher_submission_requires_defect_remarks_and_creates_analytics_rows(): void
    {
        $this->actingAsInspectionUser();
        $payload = $this->firePayload();
        $payload['fireExtinguisherChecks'][0]['operationalCondition'] = 'Not Operational';

        $this->postJson('/api/reports', [
            'display_id' => 'INS-FE-001',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => $payload,
        ])->assertStatus(422)->assertJsonValidationErrors([
            'payload.fireExtinguisherChecks.0.operationalConditionRemarks',
        ]);

        $payload['fireExtinguisherChecks'][0]['operationalConditionRemarks'] = 'Pressure indicator failed.';
        $response = $this->postJson('/api/reports', [
            'display_id' => 'INS-FE-002',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => $payload,
        ]);

        $response->assertCreated();
        $report = Report::query()->where('display_id', 'INS-FE-002')->firstOrFail();
        $this->assertSame(5, InspectionCheckRow::query()->where('report_id', $report->id)->count());
        $this->assertDatabaseHas('inspection_check_rows', [
            'report_id' => $report->id,
            'inspection_type_key' => 'fire-extinguisher-inspection',
            'equipment_catalog_id' => 99,
            'check_key' => 'operational-condition',
            'check_value' => 'Not Operational',
            'remarks' => 'Pressure indicator failed.',
            'has_defect' => true,
            'source_payload_key' => 'fireExtinguisherChecks',
        ]);
    }

    private function firePayload(): array
    {
        return [
            'incidentType' => 'Fire Extinguisher Inspection',
            'location' => 'Manjung Hub > Reception',
            'selectedLocation' => 'Manjung Hub > Reception',
            'mainLocation' => 'Manjung Hub',
            'subLocation' => 'Reception',
            'fireExtinguisherInspectedBy' => 'Inspector Fire',
            'fireExtinguisherInspectionDate' => '2026-06-29',
            'photos' => [],
            'fireExtinguisherChecks' => [
                [
                    'id' => 'fe:99',
                    'catalogId' => 99,
                    'sourceRowNumber' => '7',
                    'equipmentSource' => 'seed',
                    'zone' => '1',
                    'mainLocation' => 'Manjung Hub',
                    'subLocation' => 'Reception',
                    'idLocNo' => 'ADO-001',
                    'barcodeNo' => 'EE042021Y544896',
                    'feType' => 'DP 6KG',
                    'certificationValidity' => '2025-07-01',
                    'certificationValidityRaw' => '45839',
                    'daysLeftToExpire' => '71',
                    'physicalCondition' => 'Good',
                    'signageCondition' => 'Good',
                    'boxKeyAvailability' => 'N/A',
                    'boxGlassAvailability' => 'N/A',
                    'operationalCondition' => 'Operational',
                    'remarks' => '',
                    'photos' => [],
                ],
            ],
        ];
    }

    private function actingAsInspectionUser(): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->grantPermission($user, 'reports.inspection.view');
        $this->actingAs($user);

        return $user;
    }

    private function grantPermission(User $user, string $permissionName): void
    {
        $permission = Permission::query()->firstOrCreate([
            'name' => $permissionName,
            'guard_name' => 'web',
        ]);
        $role = Role::query()->firstOrCreate([
            'name' => 'Fire Extinguisher Inspection Tester',
            'guard_name' => 'web',
        ]);
        if (! $role->hasPermissionTo($permission)) {
            $role->givePermissionTo($permission);
        }
        $user->assignRole($role);
    }
}
