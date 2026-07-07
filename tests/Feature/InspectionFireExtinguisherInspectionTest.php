<?php

namespace Tests\Feature;

use App\Models\InspectionCheckRow;
use App\Models\InspectionFireExtinguisher;
use App\Models\Report;
use App\Models\User;
use Database\Seeders\InspectionFireExtinguisherCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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
        $this->assertSame(true, $response->json('data.0.canEdit'));
        $this->assertSame('Manjung Hub', $response->json('data.0.mainLocation'));
        $this->assertSame('catalog:'.(string) $response->json('data.0.catalogId'), $response->json('data.0.canonicalAssetKey'));
        $this->assertArrayHasKey('activeIdentityKey', $response->json('data.0'));

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
        $this->assertArrayNotHasKey('certificationValidityRaw', $response->json('data.0'));
    }

    public function test_custom_fire_extinguisher_can_be_created_updated_and_archived(): void
    {
        $this->actingAsInspectionUser();

        $created = $this->postJson('/api/inspection/fire-extinguishers', $this->customFireExtinguisherPayload());

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
        $this->assertNull(InspectionFireExtinguisher::query()->findOrFail($id)->active_identity_key);
    }

    public function test_fire_extinguisher_api_calculates_days_to_expire_from_validity_minus_today(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-05 08:00:00'));

        try {
            $this->actingAsInspectionUser();

            $expired = $this->postJson('/api/inspection/fire-extinguishers', $this->customFireExtinguisherPayload([
                'idLocNo' => 'QA-EXPIRED',
                'barcodeNo' => 'BAR-QA-EXPIRED',
                'certificationValidity' => '2025-07-01',
            ]));

            $future = $this->postJson('/api/inspection/fire-extinguishers', $this->customFireExtinguisherPayload([
                'idLocNo' => 'QA-FUTURE',
                'barcodeNo' => 'BAR-QA-FUTURE',
                'certificationValidity' => '2026-07-10',
            ]));

            $expired->assertCreated()->assertJsonPath('data.daysLeftToExpire', '-369');
            $future->assertCreated()->assertJsonPath('data.daysLeftToExpire', '5');
            $this->assertArrayNotHasKey('certificationValidityRaw', $expired->json('data'));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_fire_extinguisher_catalog_filters_by_zone_area_and_location(): void
    {
        $this->seed(InspectionFireExtinguisherCatalogSeeder::class);
        $this->actingAsInspectionUser();

        $response = $this->getJson('/api/inspection/fire-extinguishers?zone=1&mainLocation=Manjung%20Hub&subLocation=Reception');

        $response->assertOk();
        $response->assertJsonPath('meta.zone', '1');
        $response->assertJsonPath('meta.mainLocation', 'Manjung Hub');
        $response->assertJsonPath('meta.subLocation', 'Reception');
        $this->assertGreaterThan(0, count($response->json('data')));
        $this->assertTrue(collect($response->json('data'))->every(
            fn (array $row): bool => $row['zone'] === '1'
                && $row['mainLocation'] === 'Manjung Hub'
                && $row['subLocation'] === 'Reception'
        ));
    }

    public function test_fire_extinguisher_catalog_includes_latest_submitted_inspection_context(): void
    {
        $viewer = $this->actingAsInspectionUser();
        $inspector = User::factory()->create(['name' => 'Jang', 'status' => 'active']);
        $extinguisher = InspectionFireExtinguisher::query()->create([
            'zone' => '1',
            'main_location_name' => 'Manjung Hub',
            'sub_location_name' => 'Reception',
            'id_loc_no' => 'ADO-777',
            'barcode_no' => 'SR-LAST-777',
            'fe_type' => 'DP 6KG',
            'certification_validity' => '2026-12-31',
            'source' => 'custom',
            'is_active' => true,
            'sort_order' => 1,
        ]);
        $older = $this->createSubmittedFireExtinguisherCheckRow(
            owner: $viewer,
            inspector: $inspector,
            extinguisher: $extinguisher,
            displayId: 'INS-OLD',
            submittedAt: Carbon::parse('2026-07-01 08:00:00'),
        );
        $latest = $this->createSubmittedFireExtinguisherCheckRow(
            owner: $viewer,
            inspector: $inspector,
            extinguisher: $extinguisher,
            displayId: 'INS-LATEST',
            submittedAt: Carbon::parse('2026-07-06 11:31:00'),
        );

        $response = $this->getJson('/api/inspection/fire-extinguishers?mainLocation=Manjung%20Hub&subLocation=Reception');

        $response->assertOk();
        $row = collect($response->json('data'))->firstWhere('catalogId', $extinguisher->id);
        $this->assertSame('INS-LATEST', $row['lastInspection']['displayId']);
        $this->assertSame('Jang', $row['lastInspection']['inspectedBy']);
        $this->assertSame($latest->id, InspectionCheckRow::query()->where('display_id', 'INS-LATEST')->value('id'));
        $this->assertNotSame($older->display_id, $row['lastInspection']['displayId']);
    }

    public function test_fire_extinguisher_lookup_returns_exact_active_locator_case_insensitive(): void
    {
        $this->actingAsInspectionUser();

        $created = $this->postJson('/api/inspection/fire-extinguishers', $this->customFireExtinguisherPayload([
            'barcodeNo' => 'SR102014Z060198',
        ]))->assertCreated();

        $response = $this->getJson('/api/inspection/fire-extinguishers/lookup?locator=sr102014z060198');

        $response->assertOk();
        $response->assertJsonPath('data.id', $created->json('data.id'));
        $response->assertJsonPath('data.barcodeNo', 'SR102014Z060198');
        $response->assertJsonPath('meta.normalizedLocator', 'sr102014z060198');
    }

    public function test_fire_extinguisher_lookup_normalizes_labelled_locator_text(): void
    {
        $this->actingAsInspectionUser();

        $created = $this->postJson('/api/inspection/fire-extinguishers', $this->customFireExtinguisherPayload([
            'barcodeNo' => 'SR102014Z060199',
        ]))->assertCreated();

        $response = $this->getJson('/api/inspection/fire-extinguishers/lookup?locator='.urlencode('S/N: SR102014Z060199'));

        $response->assertOk();
        $response->assertJsonPath('data.id', $created->json('data.id'));
        $response->assertJsonPath('data.barcodeNo', 'SR102014Z060199');
        $response->assertJsonPath('meta.normalizedLocator', 'sr102014z060199');
    }

    public function test_fire_extinguisher_lookup_returns_not_found_for_unknown_locator(): void
    {
        $this->actingAsInspectionUser();

        $this->getJson('/api/inspection/fire-extinguishers/lookup?locator=UNKNOWN-FE')
            ->assertNotFound();
    }

    public function test_fire_extinguisher_lookup_conflicts_when_active_locator_is_duplicated(): void
    {
        $this->actingAsInspectionUser();

        foreach (['Duplicate A', 'Duplicate B'] as $location) {
            InspectionFireExtinguisher::query()->create([
                'zone' => 'QA',
                'main_location_name' => $location,
                'sub_location_name' => 'Pump Bay',
                'barcode_no' => 'SR-DUP-001',
                'fe_type' => 'DP 6KG',
                'certification_validity' => '2026-12-31',
                'source' => 'custom',
                'is_active' => true,
            ]);
        }

        $this->getJson('/api/inspection/fire-extinguishers/lookup?locator=sr-dup-001')
            ->assertConflict()
            ->assertJsonPath('meta.count', 2);
    }

    public function test_scan_registration_can_create_fire_extinguisher_without_id_loc_no(): void
    {
        $this->actingAsInspectionUser();

        $this->postJson('/api/inspection/fire-extinguishers', $this->customFireExtinguisherPayload([
            'idLocNo' => '',
            'barcodeNo' => 'SR-SCAN-NEW-001',
        ]))->assertCreated()
            ->assertJsonPath('data.idLocNo', '')
            ->assertJsonPath('data.barcodeNo', 'SR-SCAN-NEW-001');
    }

    public function test_scan_registration_rejects_duplicate_active_locator(): void
    {
        $this->actingAsInspectionUser();

        $this->postJson('/api/inspection/fire-extinguishers', $this->customFireExtinguisherPayload([
            'barcodeNo' => 'SR-SCAN-DUP-001',
        ]))->assertCreated();

        $this->postJson('/api/inspection/fire-extinguishers', $this->customFireExtinguisherPayload([
            'mainLocation' => 'Other QA Yard',
            'idLocNo' => '',
            'barcodeNo' => 'sr-scan-dup-001',
        ]))->assertStatus(422)
            ->assertJsonValidationErrors(['barcodeNo']);
    }

    public function test_custom_fire_extinguisher_rejects_duplicate_active_identity_on_create(): void
    {
        $this->actingAsInspectionUser();

        $this->postJson('/api/inspection/fire-extinguishers', $this->customFireExtinguisherPayload([
            'mainLocation' => 'QA Duplicate Yard',
            'subLocation' => 'Pump Bay',
            'idLocNo' => 'QA-DUP-001',
            'barcodeNo' => 'BAR-QA-DUP-001',
        ]))->assertCreated();

        $this->postJson('/api/inspection/fire-extinguishers', $this->customFireExtinguisherPayload([
            'mainLocation' => ' qa duplicate yard ',
            'subLocation' => 'Pump   Bay',
            'idLocNo' => 'qa-dup-001',
            'barcodeNo' => 'bar-qa-dup-001',
            'feType' => 'CO2 5KG',
        ]))->assertStatus(422)->assertJsonValidationErrors([
            'idLocNo',
            'barcodeNo',
        ]);
    }

    public function test_custom_fire_extinguisher_rejects_duplicate_active_identity_on_update(): void
    {
        $this->actingAsInspectionUser();

        $first = $this->postJson('/api/inspection/fire-extinguishers', $this->customFireExtinguisherPayload([
            'mainLocation' => 'QA Update Yard',
            'subLocation' => 'Pump Bay',
            'idLocNo' => 'QA-UPD-001',
            'barcodeNo' => 'BAR-QA-UPD-001',
        ]))->assertCreated();

        $second = $this->postJson('/api/inspection/fire-extinguishers', $this->customFireExtinguisherPayload([
            'mainLocation' => 'QA Update Yard',
            'subLocation' => 'Pump Bay',
            'idLocNo' => 'QA-UPD-002',
            'barcodeNo' => 'BAR-QA-UPD-002',
        ]))->assertCreated();

        $this->patchJson('/api/inspection/fire-extinguishers/'.$second->json('data.id'), [
            'zone' => 'QA',
            'mainLocation' => 'QA Update Yard',
            'subLocation' => 'Pump Bay',
            'idLocNo' => 'QA-UPD-001',
            'barcodeNo' => 'BAR-QA-UPD-001',
            'feType' => 'DP 6KG',
        ])->assertStatus(422)->assertJsonValidationErrors([
            'idLocNo',
            'barcodeNo',
        ]);

        $this->assertSame('QA-UPD-002', InspectionFireExtinguisher::query()
            ->findOrFail((int) $second->json('data.id'))
            ->id_loc_no);
        $this->assertNotSame(
            InspectionFireExtinguisher::query()->findOrFail((int) $first->json('data.id'))->active_identity_key,
            InspectionFireExtinguisher::query()->findOrFail((int) $second->json('data.id'))->active_identity_key,
        );
    }

    public function test_archived_fire_extinguisher_identity_can_be_recreated(): void
    {
        $this->actingAsInspectionUser();

        $payload = $this->customFireExtinguisherPayload([
            'mainLocation' => 'QA Archive Yard',
            'subLocation' => 'Pump Bay',
            'idLocNo' => 'QA-ARC-001',
            'barcodeNo' => 'BAR-QA-ARC-001',
        ]);
        $created = $this->postJson('/api/inspection/fire-extinguishers', $payload)->assertCreated();

        $this->deleteJson('/api/inspection/fire-extinguishers/'.$created->json('data.id'))
            ->assertNoContent();

        $recreated = $this->postJson('/api/inspection/fire-extinguishers', $payload)
            ->assertCreated();

        $this->assertNotSame($created->json('data.id'), $recreated->json('data.id'));
        $this->assertNull(InspectionFireExtinguisher::query()
            ->findOrFail((int) $created->json('data.id'))
            ->active_identity_key);
        $this->assertNotNull(InspectionFireExtinguisher::query()
            ->findOrFail((int) $recreated->json('data.id'))
            ->active_identity_key);
    }

    public function test_regular_inspection_user_can_update_seeded_fire_extinguisher_row(): void
    {
        $this->seed(InspectionFireExtinguisherCatalogSeeder::class);
        $this->actingAsInspectionUser();
        $seed = InspectionFireExtinguisher::query()->where('source', 'seed')->firstOrFail();

        $this->patchJson("/api/inspection/fire-extinguishers/{$seed->id}", [
            'zone' => $seed->zone,
            'mainLocation' => $seed->main_location_name,
            'subLocation' => $seed->sub_location_name,
            'idLocNo' => 'ADO-001-UPDATED',
            'barcodeNo' => $seed->barcode_no,
            'feType' => $seed->fe_type,
        ])->assertOk()->assertJsonPath('data.idLocNo', 'ADO-001-UPDATED');
    }

    public function test_regular_inspection_user_can_archive_seeded_fire_extinguisher_row(): void
    {
        $this->seed(InspectionFireExtinguisherCatalogSeeder::class);
        $this->actingAsInspectionUser();
        $seed = InspectionFireExtinguisher::query()->where('source', 'seed')->firstOrFail();

        $this->deleteJson("/api/inspection/fire-extinguishers/{$seed->id}")
            ->assertNoContent();

        $this->assertFalse($seed->fresh()->is_active);

        $index = $this->getJson('/api/inspection/fire-extinguishers?mainLocation='.urlencode((string) $seed->main_location_name));
        $index->assertOk();
        $this->assertNull(
            collect($index->json('data'))->firstWhere('catalogId', $seed->id)
        );
    }

    public function test_fire_extinguisher_submission_requires_defect_remarks_and_creates_analytics_rows(): void
    {
        $this->actingAsInspectionUser();
        $payload = $this->firePayload();
        $payload['fireExtinguisherChecks'][0]['operationalCondition'] = 'Not Good';

        $this->postJson('/api/reports', [
            'display_id' => 'INS-FE-001',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => $payload,
        ])->assertStatus(422)->assertJsonValidationErrors([
            'payload.fireExtinguisherChecks.0.operationalConditionRemarks',
        ]);

        $payload['fireExtinguisherChecks'][0]['operationalConditionRemarks'] = 'Pressure indicator failed.';
        $payload['fireExtinguisherChecks'][0]['operationalConditionPhotos'] = [[
            'id' => 'photo-1',
            'url' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR4nGNgYAAAAAMAASsJTYQAAAAASUVORK5CYII=',
        ]];
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
            'check_value' => 'Not Good',
            'remarks' => 'Pressure indicator failed.',
            'has_defect' => true,
            'source_payload_key' => 'fireExtinguisherChecks',
        ]);
    }

    public function test_fire_extinguisher_submission_requires_all_statuses(): void
    {
        $this->actingAsInspectionUser();
        $payload = $this->firePayload();
        $payload['fireExtinguisherChecks'][0]['physicalCondition'] = '';

        $this->postJson('/api/reports', [
            'display_id' => 'INS-FE-MISSING-STATUS',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => $payload,
        ])->assertStatus(422)->assertJsonValidationErrors([
            'payload.fireExtinguisherChecks.0.physicalCondition',
        ]);
    }

    public function test_fire_extinguisher_coverage_pivots_latest_submitted_rows_and_detail_photos(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-07 12:00:00'));

        try {
            $owner = $this->actingAsInspectionUser();
            $inspector = User::factory()->create(['name' => 'Jang', 'status' => 'active']);
            $extinguisher = InspectionFireExtinguisher::query()->create([
                'zone' => 'Zone 1',
                'main_location_name' => 'Manjung Hub',
                'sub_location_name' => 'Reception',
                'id_loc_no' => 'ADO-900',
                'barcode_no' => 'BAR-ADO-900',
                'fe_type' => 'DP 6KG',
                'certification_validity' => '2026-07-01',
                'source' => 'custom',
                'is_active' => true,
                'sort_order' => 1,
            ]);
            InspectionFireExtinguisher::query()->create([
                'zone' => 'Zone 9',
                'main_location_name' => 'Hidden',
                'sub_location_name' => 'Inactive',
                'id_loc_no' => 'INACTIVE-001',
                'barcode_no' => 'BAR-INACTIVE',
                'fe_type' => 'DP 6KG',
                'certification_validity' => '2026-12-31',
                'source' => 'custom',
                'is_active' => false,
            ]);

            $this->createCoverageInspectionRows(
                owner: $owner,
                inspector: $inspector,
                extinguisher: $extinguisher,
                displayId: 'INS-FE-OLD',
                submittedAt: Carbon::parse('2026-07-01 09:00:00'),
                operationalValue: 'Good',
                operationalRemarks: '',
                operationalPhotos: [],
            );
            $this->createCoverageInspectionRows(
                owner: $owner,
                inspector: $inspector,
                extinguisher: $extinguisher,
                displayId: 'INS-FE-LATEST',
                submittedAt: Carbon::parse('2026-07-07 11:30:00'),
                operationalValue: 'Not Good',
                operationalRemarks: 'Pressure indicator failed.',
                operationalPhotos: [[
                    'id' => 'photo-1',
                    'fileName' => 'pressure.jpg',
                    'url' => 'data:image/png;base64,abc123',
                    'description' => 'Pressure gauge evidence.',
                ]],
            );

            $response = $this->getJson('/api/inspection/fire-extinguishers/coverage?search=ADO-900');

            $response->assertOk();
            $row = collect($response->json('data'))->firstWhere('catalogId', $extinguisher->id);
            $this->assertNotNull($row);
            $this->assertSame('Not Good', $row['operational']);
            $this->assertSame('Jang', $row['inspectedBy']);
            $this->assertSame('INS-FE-LATEST', $row['latestReportId']);
            $this->assertSame(1, $row['issueCount']);
            $this->assertSame(1, $row['evidenceCount']);
            $this->assertSame(2, $row['reportCount']);
            $this->assertSame(1, $row['repeatCount']);
            $this->assertSame(2, $row['duplicateCount']);
            $this->assertSame('Pressure indicator failed.', $row['remarks']);
            $this->assertSame(-6, (int) $row['daysLeft']);
            $this->assertNull(collect($response->json('data'))->firstWhere('idLocNo', 'INACTIVE-001'));

            $detail = $this->getJson("/api/inspection/fire-extinguishers/coverage/{$extinguisher->id}");

            $detail->assertOk();
            $operational = collect($detail->json('data.checks'))->firstWhere('key', 'operational');
            $this->assertSame('Not Good', $operational['value']);
            $this->assertTrue($operational['hasDefect']);
            $this->assertSame('Pressure indicator failed.', $operational['remarks']);
            $this->assertSame(1, $operational['evidenceCount']);
            $this->assertSame('pressure.jpg', $operational['photos'][0]['fileName']);
            $this->assertCount(2, $detail->json('data.duplicateReports'));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_fire_extinguisher_coverage_period_keeps_uninspected_catalog_rows_visible(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-07 12:00:00'));

        try {
            $owner = $this->actingAsInspectionUser();
            $inspector = User::factory()->create(['name' => 'Jang', 'status' => 'active']);
            $oldOnly = InspectionFireExtinguisher::query()->create([
                'zone' => 'Zone 1',
                'main_location_name' => 'Coverage Period Yard',
                'sub_location_name' => 'Reception',
                'id_loc_no' => 'OLD-001',
                'barcode_no' => 'BAR-OLD-001',
                'fe_type' => 'DP 6KG',
                'certification_validity' => '2026-12-31',
                'source' => 'custom',
                'is_active' => true,
            ]);
            InspectionFireExtinguisher::query()->create([
                'zone' => 'Zone 1',
                'main_location_name' => 'Coverage Period Yard',
                'sub_location_name' => 'Admin',
                'id_loc_no' => 'NEVER-001',
                'barcode_no' => 'BAR-NEVER-001',
                'fe_type' => 'DP 6KG',
                'certification_validity' => '2026-12-31',
                'source' => 'custom',
                'is_active' => true,
            ]);
            $this->createCoverageInspectionRows(
                owner: $owner,
                inspector: $inspector,
                extinguisher: $oldOnly,
                displayId: 'INS-FE-OLD-PERIOD',
                submittedAt: Carbon::parse('2026-07-01 09:00:00'),
                operationalValue: 'Good',
                operationalRemarks: '',
                operationalPhotos: [],
            );

            $response = $this->getJson('/api/inspection/fire-extinguishers/coverage?location='.urlencode('Coverage Period Yard').'&period=today&perPage=all');

            $response->assertOk();
            $response->assertJsonPath('meta.total', 2);
            $response->assertJsonPath('meta.filtered', 2);
            $response->assertJsonPath('meta.summary.total', 2);
            $response->assertJsonPath('meta.summary.inspected', 0);
            $oldRow = collect($response->json('data'))->firstWhere('idLocNo', 'OLD-001');
            $neverRow = collect($response->json('data'))->firstWhere('idLocNo', 'NEVER-001');
            $this->assertSame('', $oldRow['physical']);
            $this->assertNull($oldRow['latestInspectionAt']);
            $this->assertSame(0, $oldRow['reportCount']);
            $this->assertSame('', $neverRow['physical']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_fire_extinguisher_coverage_supports_custom_period_range(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-07 12:00:00'));

        try {
            $owner = $this->actingAsInspectionUser();
            $inspector = User::factory()->create(['name' => 'Jang', 'status' => 'active']);
            $extinguisher = InspectionFireExtinguisher::query()->create([
                'zone' => 'Zone 1',
                'main_location_name' => 'Coverage Custom Yard',
                'sub_location_name' => 'Reception',
                'id_loc_no' => 'CUSTOM-001',
                'barcode_no' => 'BAR-CUSTOM-001',
                'fe_type' => 'DP 6KG',
                'certification_validity' => '2026-12-31',
                'source' => 'custom',
                'is_active' => true,
            ]);

            $this->createCoverageInspectionRows(
                owner: $owner,
                inspector: $inspector,
                extinguisher: $extinguisher,
                displayId: 'INS-FE-CUSTOM-OLD',
                submittedAt: Carbon::parse('2026-07-01 09:00:00'),
                operationalValue: 'Good',
                operationalRemarks: '',
                operationalPhotos: [],
            );
            $this->createCoverageInspectionRows(
                owner: $owner,
                inspector: $inspector,
                extinguisher: $extinguisher,
                displayId: 'INS-FE-CUSTOM-LATEST',
                submittedAt: Carbon::parse('2026-07-07 11:00:00'),
                operationalValue: 'Not Good',
                operationalRemarks: 'No pressure.',
                operationalPhotos: [],
            );

            $oldWindow = $this->getJson('/api/inspection/fire-extinguishers/coverage?search=CUSTOM-001&period=custom&periodFrom=2026-07-01&periodTo=2026-07-01&perPage=all');

            $oldWindow->assertOk();
            $oldWindow->assertJsonPath('meta.periodFrom', '2026-07-01');
            $oldWindow->assertJsonPath('meta.periodTo', '2026-07-01');
            $oldWindow->assertJsonPath('data.0.latestReportId', 'INS-FE-CUSTOM-OLD');
            $oldWindow->assertJsonPath('data.0.operational', 'Good');

            $latestWindow = $this->getJson('/api/inspection/fire-extinguishers/coverage?search=CUSTOM-001&period=custom&periodFrom=2026-07-02&periodTo=2026-07-07&perPage=all');

            $latestWindow->assertOk();
            $latestWindow->assertJsonPath('data.0.latestReportId', 'INS-FE-CUSTOM-LATEST');
            $latestWindow->assertJsonPath('data.0.operational', 'Not Good');
            $this->assertContains('Jang', $latestWindow->json('meta.options.inspectors'));

            $inspectorWindow = $this->getJson('/api/inspection/fire-extinguishers/coverage?search=CUSTOM-001&period=custom&periodFrom=2026-07-02&periodTo=2026-07-07&inspectedBy='.urlencode('Jang').'&perPage=all');

            $inspectorWindow->assertOk();
            $inspectorWindow->assertJsonPath('meta.inspectedBy', 'Jang');
            $inspectorWindow->assertJsonPath('meta.filtered', 1);
            $inspectorWindow->assertJsonPath('data.0.latestReportId', 'INS-FE-CUSTOM-LATEST');

            $otherInspectorWindow = $this->getJson('/api/inspection/fire-extinguishers/coverage?search=CUSTOM-001&period=custom&periodFrom=2026-07-02&periodTo=2026-07-07&inspectedBy='.urlencode('Nobody').'&perPage=all');

            $otherInspectorWindow->assertOk();
            $otherInspectorWindow->assertJsonPath('meta.filtered', 0);

            $emptyWindow = $this->getJson('/api/inspection/fire-extinguishers/coverage?search=CUSTOM-001&period=custom&periodFrom=2026-06-01&periodTo=2026-06-30&perPage=all');

            $emptyWindow->assertOk();
            $emptyWindow->assertJsonPath('meta.total', 1);
            $emptyWindow->assertJsonPath('meta.summary.inspected', 0);
            $emptyWindow->assertJsonPath('data.0.latestInspectionAt', null);
            $emptyWindow->assertJsonPath('data.0.reportCount', 0);

            $this->getJson('/api/inspection/fire-extinguishers/coverage?search=CUSTOM-001&period=custom&periodFrom=2026-07-07&periodTo=2026-07-01&perPage=all')
                ->assertStatus(422)
                ->assertJsonValidationErrors(['period']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_fire_extinguisher_coverage_supports_server_side_pagination_and_filters(): void
    {
        $this->actingAsInspectionUser();

        foreach (['PAGE-001', 'PAGE-002', 'PUMP-003'] as $index => $idLocNo) {
            InspectionFireExtinguisher::query()->create([
                'zone' => 'Zone '.($index + 1),
                'main_location_name' => $index === 2 ? 'Coverage Pump House' : 'Coverage Paging Yard',
                'sub_location_name' => 'Area '.$index,
                'id_loc_no' => $idLocNo,
                'barcode_no' => 'BAR-'.$idLocNo,
                'fe_type' => 'DP 6KG',
                'certification_validity' => '2026-12-31',
                'source' => 'custom',
                'is_active' => true,
            ]);
        }

        $page = $this->getJson('/api/inspection/fire-extinguishers/coverage?search=PAGE-&perPage=1&page=2');

        $page->assertOk();
        $page->assertJsonPath('meta.total', 2);
        $page->assertJsonPath('meta.filtered', 2);
        $page->assertJsonPath('meta.page', 2);
        $page->assertJsonPath('meta.perPage', 1);
        $page->assertJsonPath('meta.lastPage', 2);
        $this->assertCount(1, $page->json('data'));

        $filtered = $this->getJson('/api/inspection/fire-extinguishers/coverage?search=PUMP-003&perPage=10');

        $filtered->assertOk();
        $filtered->assertJsonPath('meta.total', 1);
        $filtered->assertJsonPath('meta.filtered', 1);
        $filtered->assertJsonPath('data.0.idLocNo', 'PUMP-003');
        $this->assertContains('Coverage Pump House', $filtered->json('meta.options.locations'));
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
                    'physicalCondition' => 'Good',
                    'signageCondition' => 'Good',
                    'boxKeyAvailability' => 'N/A',
                    'boxGlassAvailability' => 'N/A',
                    'operationalCondition' => 'Good',
                    'remarks' => '',
                    'photos' => [],
                ],
            ],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $operationalPhotos
     */
    private function createCoverageInspectionRows(
        User $owner,
        User $inspector,
        InspectionFireExtinguisher $extinguisher,
        string $displayId,
        Carbon $submittedAt,
        string $operationalValue,
        string $operationalRemarks,
        array $operationalPhotos,
    ): void {
        $sourceRowId = 'fe:'.$extinguisher->id;
        $payloadRow = [
            'id' => $sourceRowId,
            'catalogId' => $extinguisher->id,
            'zone' => $extinguisher->zone,
            'mainLocation' => $extinguisher->main_location_name,
            'subLocation' => $extinguisher->sub_location_name,
            'idLocNo' => $extinguisher->id_loc_no,
            'barcodeNo' => $extinguisher->barcode_no,
            'feType' => $extinguisher->fe_type,
            'certificationValidity' => $extinguisher->certification_validity?->format('Y-m-d'),
            'physicalCondition' => 'Good',
            'signageCondition' => 'Good',
            'boxKeyAvailability' => 'Yes',
            'boxGlassAvailability' => 'Yes',
            'operationalCondition' => $operationalValue,
            'operationalConditionRemarks' => $operationalRemarks,
            'operationalConditionPhotos' => $operationalPhotos,
            'remarks' => '',
            'photos' => [],
        ];
        $report = Report::query()->create([
            'report_uid' => strtolower($displayId),
            'display_id' => $displayId,
            'owner_user_id' => $owner->id,
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => [
                'incidentType' => 'Fire Extinguisher Inspection',
                'fireExtinguisherChecks' => [$payloadRow],
            ],
            'submitted_at' => $submittedAt,
        ]);

        $fields = [
            ['physical-condition', 'FE Physical Condition', 'Good', '', 0, false],
            ['signage-condition', 'FE Signage Condition', 'Good', '', 0, false],
            ['box-key-availability', 'FE Box Key Availability', 'Yes', '', 0, false],
            ['box-glass-availability', 'FE Box Glass Availability', 'Yes', '', 0, false],
            [
                'operational-condition',
                'Operational Condition',
                $operationalValue,
                $operationalRemarks,
                count($operationalPhotos),
                strtolower($operationalValue) === 'not good',
            ],
        ];

        foreach ($fields as $index => [$checkKey, $checkName, $checkValue, $remarks, $evidenceCount, $hasDefect]) {
            InspectionCheckRow::query()->create([
                'report_id' => $report->id,
                'report_uid' => $report->report_uid,
                'display_id' => $displayId,
                'owner_user_id' => $owner->id,
                'created_by_user_id' => $owner->id,
                'updated_by_user_id' => $inspector->id,
                'submitted_by_user_id' => $inspector->id,
                'inspection_type' => 'Fire Extinguisher Inspection',
                'inspection_type_key' => 'fire-extinguisher-inspection',
                'location' => $extinguisher->main_location_name.' > '.$extinguisher->sub_location_name,
                'main_location' => $extinguisher->main_location_name,
                'sub_location' => $extinguisher->sub_location_name,
                'equipment' => $extinguisher->id_loc_no.' '.$extinguisher->fe_type.' '.$extinguisher->barcode_no,
                'equipment_key' => strtolower((string) $extinguisher->id_loc_no),
                'equipment_catalog_id' => $extinguisher->id,
                'equipment_source' => 'custom',
                'check_group' => 'Fire Extinguisher Checks',
                'check_key' => $checkKey,
                'check_name' => $checkName,
                'check_value' => $checkValue,
                'remarks' => $remarks !== '' ? $remarks : null,
                'has_defect' => $hasDefect,
                'has_evidence' => $evidenceCount > 0,
                'evidence_count' => $evidenceCount,
                'report_status' => 'Submitted',
                'submitted_at' => $submittedAt,
                'source_payload_key' => 'fireExtinguisherChecks',
                'source_row_id' => $sourceRowId,
                'sort_order' => $index,
            ]);
        }
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function customFireExtinguisherPayload(array $overrides = []): array
    {
        return array_merge([
            'zone' => 'QA',
            'mainLocation' => 'QA Yard',
            'subLocation' => 'Pump Bay',
            'idLocNo' => 'QA-001',
            'barcodeNo' => 'BAR-QA-001',
            'feType' => 'DP 6KG',
            'certificationValidity' => '2026-12-31',
        ], $overrides);
    }

    private function createSubmittedFireExtinguisherCheckRow(
        User $owner,
        User $inspector,
        InspectionFireExtinguisher $extinguisher,
        string $displayId,
        Carbon $submittedAt,
    ): InspectionCheckRow {
        $report = Report::query()->create([
            'report_uid' => strtolower($displayId),
            'display_id' => $displayId,
            'owner_user_id' => $owner->id,
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => [],
            'submitted_at' => $submittedAt,
        ]);

        return InspectionCheckRow::query()->create([
            'report_id' => $report->id,
            'report_uid' => $report->report_uid,
            'display_id' => $displayId,
            'owner_user_id' => $owner->id,
            'created_by_user_id' => $owner->id,
            'updated_by_user_id' => $inspector->id,
            'submitted_by_user_id' => $inspector->id,
            'inspection_type' => 'Fire Extinguisher Inspection',
            'inspection_type_key' => 'fire-extinguisher-inspection',
            'location' => 'Manjung Hub > Reception',
            'main_location' => 'Manjung Hub',
            'sub_location' => 'Reception',
            'equipment' => 'ADO-777',
            'equipment_key' => 'ado-777',
            'equipment_catalog_id' => $extinguisher->id,
            'equipment_source' => 'custom',
            'check_group' => 'Fire Extinguisher Checks',
            'check_key' => 'physical-condition',
            'check_name' => 'FE Physical Condition',
            'check_value' => 'Good',
            'has_defect' => false,
            'has_evidence' => false,
            'evidence_count' => 0,
            'report_status' => 'Submitted',
            'submitted_at' => $submittedAt,
            'source_payload_key' => 'fireExtinguisherChecks',
            'source_row_id' => 'fe:'.$extinguisher->id,
            'sort_order' => 1,
        ]);
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
