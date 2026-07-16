<?php

namespace Tests\Feature;

use App\Models\InspectionCheckRow;
use App\Models\InspectionFireExtinguisher;
use App\Models\InspectionFireExtinguisherIssue;
use App\Models\Report;
use App\Models\User;
use App\Services\InspectionSiteLocationCatalogService;
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
        $this->assertCount(18, $response->json('data'));
        $this->assertSame(true, $response->json('data.0.canEdit'));
        $this->assertSame('Manjung Hub', $response->json('data.0.mainLocation'));
        $this->assertSame('catalog:'.(string) $response->json('data.0.catalogId'), $response->json('data.0.canonicalAssetKey'));
        $this->assertArrayHasKey('activeIdentityKey', $response->json('data.0'));

        $search = $this->getJson('/api/inspection/fire-extinguishers?mainLocation=Manjung%20Hub&search=ADO-003');
        $search->assertOk();
        $this->assertSame('ADO-003', $search->json('data.0.idLocNo'));
        $this->assertSame('CO2 5KG', $search->json('data.0.feType'));
        $this->assertSame($user->id, auth()->id());

        $this->assertSame(529, InspectionFireExtinguisher::query()
            ->where('source', 'seed')
            ->where('is_active', true)
            ->count());
        $this->assertSame(5, InspectionFireExtinguisher::query()
            ->where('id_loc_no', 'MSL1-010')
            ->count());
        $this->assertSame(0, InspectionFireExtinguisher::query()->whereNull('id_loc_no')->count());
        $this->assertGreaterThan(0, InspectionFireExtinguisher::query()->where('fe_type', 'like', 'CO2%')->count());
        $this->assertSame(0, InspectionFireExtinguisher::query()->where('fe_type', 'like', "%CO\u{00B2}%")->count());

        $sourceRows = InspectionFireExtinguisher::query()
            ->where('source', 'seed')
            ->where('is_active', true)
            ->orderBy('source_row_number')
            ->pluck('source_row_number')
            ->all();
        $this->assertSame(range(7, 535), $sourceRows);

        $row258 = InspectionFireExtinguisher::query()->where('source_row_number', 258)->firstOrFail();
        $this->assertSame('UF012023Z202896/UF062025Y910984', $row258->barcode_no);

        $row426 = InspectionFireExtinguisher::query()->where('source_row_number', 426)->firstOrFail();
        $this->assertSame("SR042024Z025545\n(SR012019Z005858)", $row426->barcode_no);

        $row517 = InspectionFireExtinguisher::query()->where('source_row_number', 517)->firstOrFail();
        $this->assertSame('2026-07-16', $row517->certification_validity?->format('Y-m-d'));
        $this->assertArrayNotHasKey('certificationValidityRaw', $response->json('data.0'));
    }

    public function test_fire_extinguisher_catalog_reseed_is_idempotent_and_archives_stale_rows(): void
    {
        $this->seed(InspectionFireExtinguisherCatalogSeeder::class);
        $idsBySourceRow = InspectionFireExtinguisher::query()
            ->where('source', 'seed')
            ->where('is_active', true)
            ->orderBy('source_row_number')
            ->pluck('id', 'source_row_number')
            ->all();

        $stale = InspectionFireExtinguisher::query()->create([
            'source_row_number' => 9999,
            'zone' => 'Legacy',
            'main_location_name' => 'Legacy Location',
            'sub_location_name' => 'Legacy Sub-location',
            'id_loc_no' => 'LEGACY-9999',
            'barcode_no' => 'LEGACY-BARCODE-9999',
            'fe_type' => 'DP 9KG',
            'certification_validity' => '2026-12-31',
            'source' => 'seed',
            'is_active' => true,
            'sort_order' => 9999,
        ]);
        $issue = InspectionFireExtinguisherIssue::query()->create([
            'public_id' => '00000000-0000-4000-8000-000000009999',
            'fire_extinguisher_id' => $stale->id,
            'check_key' => 'operational-condition',
            'check_name' => 'Operational condition',
            'status' => 'open',
            'severity' => 'medium',
            'title' => 'Legacy extinguisher defect',
            'first_detected_at' => now(),
            'last_detected_at' => now(),
            'active_key' => "fire-extinguisher:{$stale->id}:operational-condition",
        ]);

        $this->seed(InspectionFireExtinguisherCatalogSeeder::class);

        $this->assertSame($idsBySourceRow, InspectionFireExtinguisher::query()
            ->where('source', 'seed')
            ->where('is_active', true)
            ->orderBy('source_row_number')
            ->pluck('id', 'source_row_number')
            ->all());
        $this->assertSame(529, InspectionFireExtinguisher::query()
            ->where('source', 'seed')
            ->where('is_active', true)
            ->count());
        $this->assertFalse($stale->fresh()->is_active);
        $this->assertNull($stale->fresh()->source_row_number);
        $this->assertSame('retired', $stale->fresh()->lifecycle_status);
        $this->assertSame('cancelled', $issue->fresh()->status);
        $this->assertNull($issue->fresh()->active_key);
        $this->assertDatabaseHas('inspection_fire_extinguisher_issue_events', [
            'issue_id' => $issue->id,
            'event_type' => 'cancelled',
            'actor_user_id' => null,
        ]);
    }

    public function test_catalog_reseed_does_not_reactivate_a_manually_retired_seeded_asset(): void
    {
        $this->seed(InspectionFireExtinguisherCatalogSeeder::class);
        $retired = InspectionFireExtinguisher::query()->where('source_row_number', 7)->firstOrFail();
        $retired->update([
            'is_active' => false,
            'lifecycle_status' => 'retired',
            'active_identity_key' => null,
            'retired_at' => now(),
            'retirement_reason' => 'Removed by operations',
            'lock_version' => $retired->lock_version + 1,
        ]);

        $this->seed(InspectionFireExtinguisherCatalogSeeder::class);

        $retired->refresh();
        $this->assertFalse($retired->is_active);
        $this->assertSame('retired', $retired->lifecycle_status);
        $this->assertSame('Removed by operations', $retired->retirement_reason);
    }

    public function test_fire_extinguisher_reseed_does_not_repurpose_a_changed_catalog_id(): void
    {
        $this->seed(InspectionFireExtinguisherCatalogSeeder::class);
        $legacy = InspectionFireExtinguisher::query()->where('source_row_number', 7)->firstOrFail();
        $legacy->forceFill(['barcode_no' => 'LEGACY-BARCODE-ROW-7'])->save();

        $this->seed(InspectionFireExtinguisherCatalogSeeder::class);

        $current = InspectionFireExtinguisher::query()->where('source_row_number', 7)->firstOrFail();
        $this->assertNotSame($legacy->id, $current->id);
        $this->assertSame('UF062025Y910860', $current->barcode_no);
        $this->assertTrue($current->is_active);

        $legacy->refresh();
        $this->assertSame('LEGACY-BARCODE-ROW-7', $legacy->barcode_no);
        $this->assertFalse($legacy->is_active);
        $this->assertNull($legacy->source_row_number);
    }

    public function test_custom_fire_extinguisher_can_be_created_updated_and_archived(): void
    {
        $user = $this->actingAsInspectionUser();

        $created = $this->postJson('/api/inspection/fire-extinguishers', $this->customFireExtinguisherPayload());

        $created->assertCreated();
        $created->assertJsonPath('data.equipmentSource', 'custom');
        $id = (int) $created->json('data.id');
        $this->assertSame($user->id, InspectionFireExtinguisher::query()->findOrFail($id)->created_by);

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

    public function test_fire_extinguisher_lookup_matches_id_location_number(): void
    {
        $this->actingAsInspectionUser();

        $created = $this->postJson('/api/inspection/fire-extinguishers', $this->customFireExtinguisherPayload([
            'idLocNo' => 'FE-LOC-LOOKUP-001',
            'barcodeNo' => 'SR102014Z060200',
        ]))->assertCreated();

        $response = $this->getJson('/api/inspection/fire-extinguishers/lookup?locator=fe-loc-lookup-001');

        $response->assertOk();
        $response->assertJsonPath('data.id', $created->json('data.id'));
        $response->assertJsonPath('data.idLocNo', 'FE-LOC-LOOKUP-001');
        $response->assertJsonPath('meta.normalizedLocator', 'fe-loc-lookup-001');
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

    public function test_fire_extinguisher_creation_requires_a_location_and_locator(): void
    {
        $this->actingAsInspectionUser();

        $this->postJson('/api/inspection/fire-extinguishers', $this->customFireExtinguisherPayload([
            'mainLocation' => '   ',
        ]))->assertUnprocessable()->assertJsonValidationErrors(['mainLocation']);

        $this->postJson('/api/inspection/fire-extinguishers', $this->customFireExtinguisherPayload([
            'idLocNo' => '',
            'barcodeNo' => '',
        ]))->assertUnprocessable()->assertJsonValidationErrors(['idLocNo', 'barcodeNo']);
    }

    public function test_fire_extinguisher_creation_rejects_unregistered_or_incomplete_site_paths_without_writes(): void
    {
        $this->actingAsInspectionUser();

        $this->postJson('/api/inspection/fire-extinguishers', [
            'zone' => 'Unregistered Zone',
            'mainLocation' => 'Unregistered Area',
            'subLocation' => 'Unregistered Location',
            'idLocNo' => 'UNREGISTERED-001',
        ])->assertUnprocessable()->assertJsonValidationErrors(['location']);

        $this->postJson('/api/inspection/fire-extinguishers/batch', [
            'zone' => '1',
            'mainLocation' => 'Manjung Hub',
            'subLocation' => '',
            'items' => [['idLocNo' => 'INCOMPLETE-BATCH-001']],
        ])->assertUnprocessable()->assertJsonValidationErrors(['subLocation']);

        $this->assertDatabaseMissing('inspection_fire_extinguishers', [
            'id_loc_no' => 'UNREGISTERED-001',
        ]);
        $this->assertDatabaseMissing('inspection_fire_extinguishers', [
            'id_loc_no' => 'INCOMPLETE-BATCH-001',
        ]);
    }

    public function test_fire_extinguisher_creation_requires_inspection_access(): void
    {
        $this->postJson('/api/inspection/fire-extinguishers', $this->customFireExtinguisherPayload())
            ->assertUnauthorized();

        $this->actingAs(User::factory()->create(['status' => 'active']));
        $this->postJson('/api/inspection/fire-extinguishers', $this->customFireExtinguisherPayload())
            ->assertForbidden();
    }

    public function test_fire_extinguisher_batch_creation_requires_inspection_access(): void
    {
        $payload = [
            'mainLocation' => 'Batch Authorization Yard',
            'items' => [['idLocNo' => 'BATCH-AUTH-001']],
        ];

        $this->postJson('/api/inspection/fire-extinguishers/batch', $payload)
            ->assertUnauthorized();

        $this->actingAs(User::factory()->create(['status' => 'active']));
        $this->postJson('/api/inspection/fire-extinguishers/batch', $payload)
            ->assertForbidden();
    }

    public function test_inspection_user_can_create_an_atomic_fire_extinguisher_batch(): void
    {
        $user = $this->actingAsInspectionUser();

        $response = $this->postJson('/api/inspection/fire-extinguishers/batch', [
            'zone' => '1',
            'mainLocation' => 'Batch QA Yard',
            'subLocation' => 'Pump Bay',
            ...$this->registeredSitePathPayload('1', 'Batch QA Yard', 'Pump Bay'),
            'items' => [
                [
                    'idLocNo' => 'BATCH-001',
                    'barcodeNo' => 'BAR-BATCH-001',
                    'feType' => 'DP 6KG',
                    'certificationValidity' => '2027-12-31',
                ],
                [
                    'idLocNo' => 'BATCH-002',
                    'barcodeNo' => 'BAR-BATCH-002',
                    'feType' => 'CO2 5KG',
                    'certificationValidity' => '',
                ],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('meta.count', 2)
            ->assertJsonPath('data.0.mainLocation', 'Batch QA Yard')
            ->assertJsonPath('data.0.idLocNo', 'BATCH-001')
            ->assertJsonPath('data.1.idLocNo', 'BATCH-002');
        $this->assertSame(2, InspectionFireExtinguisher::query()
            ->where('main_location_name', 'Batch QA Yard')
            ->where('created_by', $user->id)
            ->count());
    }

    public function test_fire_extinguisher_batch_validation_rejects_invalid_rows_without_writes(): void
    {
        $this->actingAsInspectionUser();

        $this->postJson('/api/inspection/fire-extinguishers/batch', [
            'mainLocation' => '   ',
            'items' => [['idLocNo' => '', 'barcodeNo' => '']],
        ])->assertUnprocessable()->assertJsonValidationErrors(['mainLocation']);

        $this->postJson('/api/inspection/fire-extinguishers/batch', [
            'zone' => '1',
            'mainLocation' => 'Batch Validation Yard',
            'subLocation' => 'Pump Bay',
            ...$this->registeredSitePathPayload('1', 'Batch Validation Yard', 'Pump Bay'),
            'items' => [
                ['idLocNo' => 'BATCH-VALID-001'],
                ['idLocNo' => '', 'barcodeNo' => ''],
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors([
            'items.1.idLocNo',
            'items.1.barcodeNo',
        ]);

        $this->assertDatabaseMissing('inspection_fire_extinguishers', [
            'id_loc_no' => 'BATCH-VALID-001',
        ]);
    }

    public function test_unconfirmed_batch_duplicate_reports_database_and_batch_matches_atomically(): void
    {
        $this->actingAsInspectionUser();
        $this->postJson('/api/inspection/fire-extinguishers', $this->customFireExtinguisherPayload([
            'idLocNo' => 'EXISTING-BATCH-001',
            'barcodeNo' => 'BAR-EXISTING-BATCH-001',
        ]))->assertCreated();

        $response = $this->postJson('/api/inspection/fire-extinguishers/batch', [
            'zone' => '1',
            'mainLocation' => 'Batch Conflict Yard',
            'subLocation' => 'Pump Bay',
            ...$this->registeredSitePathPayload('1', 'Batch Conflict Yard', 'Pump Bay'),
            'items' => [
                ['idLocNo' => 'NEW-BATCH-001', 'barcodeNo' => 'bar-existing-batch-001'],
                ['idLocNo' => 'NEW-BATCH-002', 'barcodeNo' => 'BAR-INTERNAL-BATCH-001'],
                ['idLocNo' => 'bar-internal-batch-001', 'barcodeNo' => 'BAR-INTERNAL-BATCH-002'],
            ],
        ]);

        $response->assertConflict()
            ->assertJsonPath('code', 'FIRE_EXTINGUISHER_DUPLICATE_LOCATOR')
            ->assertJsonPath('meta.count', 3)
            ->assertJsonPath('data.conflicts.0.index', 0)
            ->assertJsonPath('data.conflicts.0.matches.0.barcodeNo', 'BAR-EXISTING-BATCH-001')
            ->assertJsonPath('data.conflicts.1.batchMatches.0.index', 2)
            ->assertJsonPath('data.conflicts.2.batchMatches.0.index', 1);
        $this->assertDatabaseMissing('inspection_fire_extinguishers', [
            'main_location_name' => 'Batch Conflict Yard',
        ]);
    }

    public function test_confirmed_batch_duplicates_create_distinct_catalog_rows(): void
    {
        $this->actingAsInspectionUser();
        $payload = $this->customFireExtinguisherPayload([
            'mainLocation' => 'Batch Confirm Yard',
            'idLocNo' => 'BATCH-CONFIRM-001',
            'barcodeNo' => 'BAR-BATCH-CONFIRM-001',
        ]);
        $this->postJson('/api/inspection/fire-extinguishers', $payload)->assertCreated();

        $response = $this->postJson('/api/inspection/fire-extinguishers/batch', [
            'zone' => $payload['zone'],
            'zoneId' => $payload['zoneId'],
            'mainLocation' => $payload['mainLocation'],
            'mainLocationId' => $payload['mainLocationId'],
            'subLocation' => $payload['subLocation'],
            'subLocationId' => $payload['subLocationId'],
            'items' => [
                [
                    'idLocNo' => $payload['idLocNo'],
                    'barcodeNo' => $payload['barcodeNo'],
                    'feType' => $payload['feType'],
                    'confirmDuplicate' => true,
                ],
                [
                    'idLocNo' => $payload['idLocNo'],
                    'barcodeNo' => $payload['barcodeNo'],
                    'feType' => $payload['feType'],
                    'confirmDuplicate' => true,
                ],
            ],
        ])->assertCreated()->assertJsonPath('meta.count', 2);

        $createdIds = collect($response->json('data'))->pluck('id');
        $this->assertCount(2, $createdIds->unique());
        $this->assertTrue(InspectionFireExtinguisher::query()
            ->whereIn('id', $createdIds)
            ->get()
            ->every(fn (InspectionFireExtinguisher $row): bool => $row->active_identity_key === null));
        $this->assertSame(3, InspectionFireExtinguisher::query()
            ->where('barcode_no', $payload['barcodeNo'])
            ->where('is_active', true)
            ->count());
    }

    public function test_scan_registration_warns_about_duplicate_active_locator(): void
    {
        $this->actingAsInspectionUser();

        $this->postJson('/api/inspection/fire-extinguishers', $this->customFireExtinguisherPayload([
            'barcodeNo' => 'SR-SCAN-DUP-001',
        ]))->assertCreated();

        $response = $this->postJson('/api/inspection/fire-extinguishers', $this->customFireExtinguisherPayload([
            'mainLocation' => 'Other QA Yard',
            'idLocNo' => '',
            'barcodeNo' => 'sr-scan-dup-001',
        ]));

        $response->assertConflict()
            ->assertJsonPath('code', 'FIRE_EXTINGUISHER_DUPLICATE_LOCATOR')
            ->assertJsonPath('meta.count', 1)
            ->assertJsonPath('data.matches.0.barcodeNo', 'SR-SCAN-DUP-001');
        $this->assertSame(1, InspectionFireExtinguisher::query()
            ->whereRaw('LOWER(barcode_no) = ?', ['sr-scan-dup-001'])
            ->count());
    }

    public function test_scan_registration_warns_about_duplicate_active_locator_across_barcode_and_id_location(): void
    {
        $this->actingAsInspectionUser();

        $this->postJson('/api/inspection/fire-extinguishers', $this->customFireExtinguisherPayload([
            'idLocNo' => 'SR-CROSS-DUP-001',
            'barcodeNo' => 'BAR-CROSS-DUP-001',
        ]))->assertCreated();

        $this->postJson('/api/inspection/fire-extinguishers', $this->customFireExtinguisherPayload([
            'mainLocation' => 'Other QA Yard',
            'idLocNo' => '',
            'barcodeNo' => 'sr-cross-dup-001',
        ]))->assertConflict()
            ->assertJsonPath('code', 'FIRE_EXTINGUISHER_DUPLICATE_LOCATOR')
            ->assertJsonPath('data.matches.0.idLocNo', 'SR-CROSS-DUP-001');
    }

    public function test_custom_fire_extinguisher_warns_about_duplicate_active_identity_on_create(): void
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
        ]))->assertConflict()
            ->assertJsonPath('code', 'FIRE_EXTINGUISHER_DUPLICATE_LOCATOR')
            ->assertJsonPath('meta.count', 1);
    }

    public function test_confirmed_duplicate_identity_creates_a_distinct_active_catalog_row(): void
    {
        $this->actingAsInspectionUser();

        $payload = $this->customFireExtinguisherPayload([
            'mainLocation' => 'QA Confirmed Duplicate Yard',
            'subLocation' => 'Pump Bay',
            'idLocNo' => 'QA-CONFIRM-001',
            'barcodeNo' => 'BAR-QA-CONFIRM-001',
        ]);
        $first = $this->postJson('/api/inspection/fire-extinguishers', $payload)->assertCreated();

        $second = $this->postJson('/api/inspection/fire-extinguishers', array_merge($payload, [
            'confirmDuplicate' => true,
        ]))->assertCreated();

        $this->assertNotSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame('catalog:'.$first->json('data.id'), $first->json('data.canonicalAssetKey'));
        $this->assertSame('catalog:'.$second->json('data.id'), $second->json('data.canonicalAssetKey'));
        $this->assertNotNull(InspectionFireExtinguisher::query()
            ->findOrFail((int) $first->json('data.id'))
            ->active_identity_key);
        $this->assertNull(InspectionFireExtinguisher::query()
            ->findOrFail((int) $second->json('data.id'))
            ->active_identity_key);
        $this->assertSame(2, InspectionFireExtinguisher::query()
            ->where('is_active', true)
            ->where('barcode_no', 'BAR-QA-CONFIRM-001')
            ->count());
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
        $response = $this->postJson('/api/reports', [
            'display_id' => 'INS-FE-002',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => $payload,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.fireExtinguisherChecks.0.operationalConditionPhotos', []);
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
     * @param  array<int, array<string, mixed>>  $operationalPhotos
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
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function customFireExtinguisherPayload(array $overrides = []): array
    {
        $payload = array_merge([
            'zone' => 'QA',
            'mainLocation' => 'QA Yard',
            'subLocation' => 'Pump Bay',
            'idLocNo' => 'QA-001',
            'barcodeNo' => 'BAR-QA-001',
            'feType' => 'DP 6KG',
            'certificationValidity' => '2026-12-31',
        ], $overrides);

        return array_merge($payload, $this->registeredSitePathPayload(
            (string) ($payload['zone'] ?? ''),
            (string) ($payload['mainLocation'] ?? $payload['main_location'] ?? ''),
            (string) ($payload['subLocation'] ?? $payload['sub_location'] ?? ''),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function registeredSitePathPayload(string $zone, string $area, string $location): array
    {
        $zone = trim($zone);
        $area = trim($area);
        $location = trim($location);
        if ($zone === '' || $area === '' || $location === '') {
            return [];
        }

        $catalog = app(InspectionSiteLocationCatalogService::class);
        $zoneResult = $catalog->create(['level' => 'zone', 'name' => $zone], auth()->id());
        $areaResult = $catalog->create([
            'level' => 'area',
            'parentId' => $zoneResult['row']->id,
            'name' => $area,
        ], auth()->id());
        $locationResult = $catalog->create([
            'level' => 'location',
            'parentId' => $areaResult['row']->id,
            'name' => $location,
        ], auth()->id());

        return [
            'zoneId' => $zoneResult['row']->id,
            'mainLocationId' => $areaResult['row']->id,
            'subLocationId' => $locationResult['row']->id,
        ];
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
        $this->grantPermission($user, 'reports.inspection.conduct');
        $this->grantPermission($user, 'reports.inspection.extinguishers.manage');
        $this->grantPermission($user, 'reports.inspection.issues.manage');
        $this->grantPermission($user, 'reports.inspection.issues.verify');
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
