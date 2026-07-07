<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportDraftApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_draft_crud_flow(): void
    {
        $user = User::factory()->create(['status' => 'active']);
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
}
