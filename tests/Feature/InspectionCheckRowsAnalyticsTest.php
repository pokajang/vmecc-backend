<?php

namespace Tests\Feature;

use App\Models\InspectionCheckRow;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class InspectionCheckRowsAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_hydraulic_report_creates_one_analytics_row_per_equipment_check(): void
    {
        $user = $this->actingAsInspectionUser();
        $payload = $this->hydraulicPayload('FRT', 'Slow response.', true);
        $payload['hydraulicChecks'][0]['noLeakage'] = 'N/A';
        $payload['hydraulicChecks'][0]['noLeakageRemarks'] = 'Leak test skipped because tool was isolated.';

        $response = $this->postJson('/api/reports', [
            'display_id' => 'INS-HYD-ANALYTICS-001',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => $payload,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.hydraulicChecks.0.functionTestRemarks', 'Slow response.');
        $response->assertJsonPath('data.hydraulicChecks.0.functionTestPhotos.0.description', 'Function test evidence');

        $report = Report::query()->where('display_id', 'INS-HYD-ANALYTICS-001')->firstOrFail();

        $this->assertSame(24, InspectionCheckRow::query()->where('report_id', $report->id)->count());
        $this->assertDatabaseHas('inspection_check_rows', [
            'report_id' => $report->id,
            'owner_user_id' => $user->id,
            'created_by_user_id' => $user->id,
            'updated_by_user_id' => $user->id,
            'submitted_by_user_id' => $user->id,
            'inspection_type_key' => 'hydraulic-rescue-tools-inspection',
            'main_location' => 'FRT',
            'equipment_key' => 'hydraulic-pump-motor-1',
            'equipment_source' => 'seed',
            'check_key' => 'function-test',
            'check_value' => 'Defect',
            'remarks' => 'Slow response.',
            'has_defect' => true,
            'has_evidence' => true,
            'evidence_count' => 1,
            'report_status' => 'Submitted',
            'report_version' => 1,
        ]);
        $this->assertDatabaseHas('inspection_check_rows', [
            'report_id' => $report->id,
            'equipment_key' => 'hydraulic-pump-motor-1',
            'check_key' => 'physical-condition',
            'check_value' => 'OK',
            'has_defect' => false,
            'has_evidence' => false,
            'evidence_count' => 0,
        ]);
        $this->assertDatabaseHas('inspection_check_rows', [
            'report_id' => $report->id,
            'equipment_key' => 'hydraulic-pump-motor-1',
            'check_key' => 'no-leakage',
            'check_value' => 'N/A',
            'remarks' => 'Leak test skipped because tool was isolated.',
            'has_defect' => false,
            'has_evidence' => false,
            'evidence_count' => 0,
        ]);
    }

    public function test_er_aux_report_creates_one_analytics_row_per_equipment_item(): void
    {
        $user = $this->actingAsInspectionUser();
        $payload = [
            'incidentType' => 'ER Aux Equipment Inspection',
            'location' => 'Store',
            'selectedLocation' => 'Store',
            'mainLocation' => 'Store',
            'erAuxInspectedBy' => 'Inspector One',
            'erAuxInspectionDate' => '2026-06-28',
            'photos' => [],
            'erAuxChecks' => [
                [
                    'id' => 'store:fire-jacket',
                    'location' => 'Store',
                    'equipment' => 'Fire Jacket',
                    'quantity' => '15',
                    'condition' => 'OK',
                ],
                [
                    'id' => 'store:chainsaw',
                    'location' => 'Store',
                    'equipment' => 'Chainsaw',
                    'quantity' => '0',
                    'condition' => 'Missing',
                    'remarks' => 'Sent for replacement.',
                ],
            ],
            'checklist' => [
                [
                    'id' => 'er-aux-equipment-inspection:chainsaw:missing',
                    'label' => 'Chainsaw - Qty 0: Missing',
                    'inspectionType' => 'ER Aux Equipment Inspection',
                    'selected' => true,
                ],
            ],
        ];

        $this->postJson('/api/reports', [
            'display_id' => 'INS-ERAUX-ANALYTICS-001',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => $payload,
        ])->assertCreated();

        $report = Report::query()->where('display_id', 'INS-ERAUX-ANALYTICS-001')->firstOrFail();

        $this->assertSame(2, InspectionCheckRow::query()->where('report_id', $report->id)->count());
        $this->assertDatabaseHas('inspection_check_rows', [
            'report_id' => $report->id,
            'owner_user_id' => $user->id,
            'inspection_type_key' => 'er-aux-equipment-inspection',
            'main_location' => 'Store',
            'equipment_key' => 'chainsaw',
            'check_group' => 'ER Aux Equipment Checks',
            'check_key' => 'condition',
            'check_value' => 'Missing',
            'has_defect' => true,
            'source_payload_key' => 'erAuxChecks',
        ]);
    }

    public function test_scba_report_creates_one_analytics_row_per_section_field(): void
    {
        $user = $this->actingAsInspectionUser();
        $payload = $this->scbaPayload('FRT');

        $this->postJson('/api/reports', [
            'display_id' => 'INS-SCBA-ANALYTICS-001',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => $payload,
        ])->assertCreated();

        $report = Report::query()->where('display_id', 'INS-SCBA-ANALYTICS-001')->firstOrFail();

        $this->assertSame(21, InspectionCheckRow::query()->where('report_id', $report->id)->count());
        $this->assertDatabaseHas('inspection_check_rows', [
            'report_id' => $report->id,
            'owner_user_id' => $user->id,
            'inspection_type_key' => 'scba-inspection',
            'main_location' => 'FRT',
            'equipment' => 'MSA 06',
            'check_group' => 'SCBA Back Plate Checks',
            'check_key' => 'high-pressure-hose',
            'check_value' => 'Not Good',
            'remarks' => 'Hose coupling worn.',
            'has_defect' => true,
            'source_payload_key' => 'scbaBackPlateChecks',
        ]);
        $this->assertDatabaseHas('inspection_check_rows', [
            'report_id' => $report->id,
            'equipment' => 'MSA 6.8L/08',
            'check_group' => 'SCBA Cylinder Checks',
            'check_key' => 'service-pressure',
            'check_value' => '300',
            'has_defect' => false,
            'source_payload_key' => 'scbaCylinderChecks',
        ]);
        $this->assertDatabaseHas('inspection_check_rows', [
            'report_id' => $report->id,
            'equipment' => 'Drager 02',
            'check_group' => 'SCBA Face Mask Checks',
            'check_key' => 'leak-test',
            'check_value' => 'Not Good',
            'remarks' => 'Leak test failed on seal.',
            'has_defect' => true,
            'source_payload_key' => 'scbaFaceMaskChecks',
        ]);
    }

    public function test_high_angle_report_creates_one_analytics_row_per_equipment_item(): void
    {
        $user = $this->actingAsInspectionUser();
        $payload = $this->highAnglePayload('Response Kit #1');

        $this->postJson('/api/reports', [
            'display_id' => 'INS-HA-ANALYTICS-001',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => $payload,
        ])->assertCreated();

        $report = Report::query()->where('display_id', 'INS-HA-ANALYTICS-001')->firstOrFail();

        $this->assertSame(24, InspectionCheckRow::query()->where('report_id', $report->id)->count());
        $this->assertDatabaseHas('inspection_check_rows', [
            'report_id' => $report->id,
            'owner_user_id' => $user->id,
            'inspection_type_key' => 'high-angle-rescue-equipment-inspection',
            'main_location' => 'Response Kit #1',
            'location' => 'Response Kit #1 > Heavy Duty Organizer Bag > Main Compartment',
            'sub_location' => 'Heavy Duty Organizer Bag > Main Compartment',
            'equipment' => 'Locking Carabiner - CT - Steel - S',
            'check_group' => 'High Angle Rescue Equipment Checks',
            'check_key' => 'condition',
            'check_value' => 'Not Good',
            'remarks' => 'Gate spring is sticking.',
            'has_defect' => true,
            'source_payload_key' => 'highAngleChecks',
        ]);
    }

    public function test_frt_daily_report_creates_one_analytics_row_per_seeded_row(): void
    {
        $user = $this->actingAsInspectionUser();

        $this->postJson('/api/reports', [
            'display_id' => 'INS-FRT-ANALYTICS-001',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => $this->frtPayload(),
        ])->assertCreated();

        $report = Report::query()->where('display_id', 'INS-FRT-ANALYTICS-001')->firstOrFail();

        $this->assertSame(138, InspectionCheckRow::query()->where('report_id', $report->id)->count());
        $this->assertDatabaseHas('inspection_check_rows', [
            'report_id' => $report->id,
            'owner_user_id' => $user->id,
            'inspection_type_key' => 'frt-daily-inspection',
            'main_location' => 'FIRE TRUCK',
            'location' => 'FIRE TRUCK',
            'sub_location' => '',
            'equipment' => 'OVERALL BODY',
            'check_group' => 'FRT Daily Roster',
            'check_key' => 'status',
            'check_value' => 'Issue',
            'remarks' => 'Panel dent needs repair.',
            'has_defect' => true,
            'source_payload_key' => 'frtDailyChecks',
        ]);
        $this->assertDatabaseHas('inspection_check_rows', [
            'report_id' => $report->id,
            'inspection_type_key' => 'frt-daily-inspection',
            'main_location' => 'FIRE TRUCK',
            'location' => 'FIRE TRUCK',
            'equipment' => 'MILEAGE (ODOMETER)',
            'check_group' => 'FRT Daily Roster',
            'check_key' => 'reading',
            'check_value' => '123456',
            'has_defect' => false,
            'source_payload_key' => 'frtDailyChecks',
        ]);
        $this->assertDatabaseHas('inspection_check_rows', [
            'report_id' => $report->id,
            'inspection_type_key' => 'frt-daily-inspection',
            'main_location' => 'FIRE TRUCK',
            'location' => 'TRUCK CHECKLIST',
            'sub_location' => '',
            'equipment' => 'ELECTRONIC SIREN',
            'check_group' => 'FRT One Off Checklist',
            'check_key' => 'condition',
            'check_value' => 'Not Good',
            'remarks' => 'Mute switch sticking.',
            'has_defect' => true,
            'source_payload_key' => 'frtOneOffChecks',
        ]);
    }

    public function test_custom_hydraulic_equipment_metadata_is_projected_to_analytics_rows(): void
    {
        $user = $this->actingAsInspectionUser();
        $payload = $this->hydraulicPayload('FRT', 'Custom ram failed under load.');
        $payload['hydraulicChecks'][0]['equipmentId'] = 99;
        $payload['hydraulicChecks'][0]['equipment'] = 'Custom Hydraulic Ram';
        $payload['hydraulicChecks'][0]['equipmentKey'] = 'custom-hydraulic-ram';
        $payload['hydraulicChecks'][0]['equipmentSource'] = 'custom';
        $payload['hydraulicChecks'][0]['isCustomEquipment'] = true;

        $this->postJson('/api/reports', [
            'display_id' => 'INS-HYD-ANALYTICS-CUSTOM-001',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => $payload,
        ])->assertCreated();

        $report = Report::query()->where('display_id', 'INS-HYD-ANALYTICS-CUSTOM-001')->firstOrFail();

        $this->assertDatabaseHas('inspection_check_rows', [
            'report_id' => $report->id,
            'owner_user_id' => $user->id,
            'equipment' => 'Custom Hydraulic Ram',
            'equipment_key' => 'custom-hydraulic-ram',
            'equipment_catalog_id' => 99,
            'equipment_source' => 'custom',
            'check_key' => 'function-test',
            'check_value' => 'Defect',
            'remarks' => 'Custom ram failed under load.',
        ]);
    }

    public function test_hydraulic_report_update_replaces_stale_analytics_rows(): void
    {
        $this->actingAsInspectionUser();

        $create = $this->postJson('/api/reports', [
            'display_id' => 'INS-HYD-ANALYTICS-002',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => $this->hydraulicPayload('FRT'),
        ]);
        $create->assertCreated();

        $reportUid = (string) $create->json('data.id');
        $update = $this->putJson("/api/reports/{$reportUid}", [
            'version' => 1,
            'status' => 'Submitted',
            'payload' => $this->hydraulicPayload('Store', 'Store rack loose.'),
        ]);
        $update->assertOk();

        $report = Report::query()->where('report_uid', $reportUid)->firstOrFail();
        $this->assertSame(24, InspectionCheckRow::query()->where('report_id', $report->id)->count());
        $this->assertSame(0, InspectionCheckRow::query()
            ->where('report_id', $report->id)
            ->where('main_location', 'FRT')
            ->count());
        $this->assertSame(24, InspectionCheckRow::query()
            ->where('report_id', $report->id)
            ->where('main_location', 'Store')
            ->count());
        $this->assertDatabaseHas('inspection_check_rows', [
            'report_id' => $report->id,
            'equipment_key' => 'hydraulic-pump-motor-2',
            'check_key' => 'function-test',
            'check_value' => 'Defect',
            'remarks' => 'Store rack loose.',
            'report_version' => 2,
            'report_revision' => 2,
        ]);
    }

    public function test_report_delete_soft_deletes_analytics_rows(): void
    {
        $this->actingAsInspectionUser();

        $create = $this->postJson('/api/reports', [
            'display_id' => 'INS-HYD-ANALYTICS-003',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => $this->hydraulicPayload('FRT'),
        ]);
        $create->assertCreated();

        $reportUid = (string) $create->json('data.id');
        $report = Report::query()->where('report_uid', $reportUid)->firstOrFail();

        $this->deleteJson("/api/reports/{$reportUid}")->assertNoContent();

        $this->assertSame(0, InspectionCheckRow::query()->where('report_id', $report->id)->count());
        $this->assertSame(24, InspectionCheckRow::withTrashed()->where('report_id', $report->id)->count());
        $this->assertSame(24, InspectionCheckRow::onlyTrashed()->where('report_id', $report->id)->count());
    }

    public function test_hydraulic_draft_save_and_update_do_not_create_analytics_rows(): void
    {
        $this->actingAsInspectionUser();

        $create = $this->postJson('/api/reports/drafts', [
            'report_type' => 'inspection',
            'payload' => $this->hydraulicPayload('FRT'),
        ]);
        $create->assertCreated();
        $this->assertSame(0, InspectionCheckRow::query()->count());

        $draftId = (string) $create->json('data.draft_id');
        $this->putJson("/api/reports/drafts/{$draftId}", [
            'payload' => $this->hydraulicPayload('Store'),
        ])->assertOk();

        $this->assertSame(0, InspectionCheckRow::query()->count());
    }

    public function test_workflow_transitions_update_analytics_row_status_and_version(): void
    {
        $this->actingAsInspectionUser();

        $create = $this->postJson('/api/reports', [
            'display_id' => 'INS-HYD-ANALYTICS-004',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => $this->hydraulicPayload('FRT'),
        ]);
        $create->assertCreated();

        $reportUid = (string) $create->json('data.id');
        $commander = User::factory()->create(['status' => 'active', 'name' => 'Workflow IC']);
        $this->grantInspectionPermission($commander, 'Incident Commander');
        $this->actingAs($commander);

        $this->postJson("/api/reports/{$reportUid}/review", ['version' => 1])->assertOk();

        $report = Report::query()->where('report_uid', $reportUid)->firstOrFail();
        $this->assertSame(24, InspectionCheckRow::query()
            ->where('report_id', $report->id)
            ->where('report_status', 'Reviewed')
            ->where('report_version', 2)
            ->count());

        $this->postJson("/api/reports/{$reportUid}/approve", ['version' => 2])->assertOk();

        $this->assertSame(24, InspectionCheckRow::query()
            ->where('report_id', $report->id)
            ->where('report_status', 'Approved')
            ->where('report_version', 3)
            ->count());
    }

    public function test_defect_remarks_are_queryable_from_normalized_rows(): void
    {
        $this->actingAsInspectionUser();

        $this->postJson('/api/reports', [
            'display_id' => 'INS-HYD-ANALYTICS-005',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => $this->hydraulicPayload('FRT', 'Hydraulic leak found.'),
        ])->assertCreated();

        $remarks = InspectionCheckRow::query()
            ->where('has_defect', true)
            ->whereNotNull('remarks')
            ->pluck('remarks')
            ->all();

        $this->assertContains('Hydraulic leak found.', $remarks);
    }

    public function test_backfill_command_rebuilds_rows_idempotently(): void
    {
        $this->actingAsInspectionUser();

        $this->postJson('/api/reports', [
            'display_id' => 'INS-HYD-ANALYTICS-006',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => $this->hydraulicPayload('FRT'),
        ])->assertCreated();

        InspectionCheckRow::withTrashed()->forceDelete();
        $this->assertSame(0, InspectionCheckRow::withTrashed()->count());

        $this->artisan('inspection:sync-check-rows')->assertExitCode(0);
        $this->assertSame(24, InspectionCheckRow::query()->count());
        $this->assertSame(24, InspectionCheckRow::withTrashed()->count());

        $this->artisan('inspection:sync-check-rows')->assertExitCode(0);
        $this->assertSame(24, InspectionCheckRow::query()->count());
        $this->assertSame(24, InspectionCheckRow::withTrashed()->count());
    }

    private function actingAsInspectionUser(): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->grantInspectionPermission($user);
        $this->actingAs($user);

        return $user;
    }

    private function hydraulicPayload(
        string $mainLocation,
        string $defectRemark = 'Slow response.',
        bool $includeDefectPhoto = false,
    ): array {
        $suffix = strcasecmp($mainLocation, 'Store') === 0 ? '2' : '1';
        $equipmentNames = [
            "Hydraulic Pump Motor {$suffix}",
            "Hydraulic Hose {$suffix}",
            "Hydraulic Spreader {$suffix}",
            "Hydraulic Cutter {$suffix}",
            "Hydraulic Combi {$suffix}",
            "Hydraulic Cylinder Ramp {$suffix}",
        ];

        $hydraulicChecks = [];
        foreach ($equipmentNames as $index => $equipment) {
            $hasDefect = $index === 0;
            $check = [
                'id' => strtolower(str_replace(' ', '-', "{$mainLocation}-{$equipment}")),
                'location' => $mainLocation,
                'mainLocation' => $mainLocation,
                'equipment' => $equipment,
                'physicalCondition' => 'OK',
                'mechanicalCondition' => 'OK',
                'noLeakage' => 'OK',
                'functionTest' => $hasDefect ? 'Defect' : 'OK',
                'remarks' => '',
                'functionTestRemarks' => $hasDefect ? $defectRemark : '',
            ];
            if ($includeDefectPhoto && $index === 0) {
                $check['functionTestPhotos'] = [
                    [
                        'id' => 'hydraulic-function-test-photo-1',
                        'fileName' => 'pump.jpg',
                        'description' => 'Function test evidence',
                        'url' => $this->makeImageDataUrl(16),
                    ],
                ];
            }
            $hydraulicChecks[] = $check;
        }

        return [
            'incidentType' => 'Hydraulic Rescue Tools Inspection',
            'location' => $mainLocation,
            'selectedLocation' => $mainLocation,
            'mainLocation' => $mainLocation,
            'description' => "Hydraulic rescue tools checked at {$mainLocation}.",
            'photos' => [
                [
                    'id' => 'photo-1',
                    'description' => 'hydraulic evidence',
                    'url' => $this->makeImageDataUrl(16),
                ],
            ],
            'hydraulicChecks' => $hydraulicChecks,
            'checklist' => [
                [
                    'id' => 'hydraulic-rescue-tools-inspection:sample',
                    'label' => 'Hydraulic sample check',
                    'inspectionType' => 'Hydraulic Rescue Tools Inspection',
                    'selected' => true,
                ],
            ],
        ];
    }

    private function scbaPayload(string $mainLocation): array
    {
        return [
            'incidentType' => 'SCBA Inspection',
            'location' => $mainLocation,
            'selectedLocation' => $mainLocation,
            'mainLocation' => $mainLocation,
            'description' => "SCBA checked at {$mainLocation}.",
            'scbaInspectedBy' => 'Inspector SCBA',
            'scbaInspectionDate' => '2026-06-28',
            'photos' => [],
            'scbaBackPlateChecks' => [
                [
                    'id' => 'backPlate:frt:msa:06',
                    'location' => $mainLocation,
                    'brand' => 'MSA',
                    'serialNo' => '06',
                    'backPlateHarnessCondition' => 'Good',
                    'highPressureHose' => 'Not Good',
                    'pressureGauge' => 'Good',
                    'alarmDevice' => 'Good',
                    'demandValve' => 'Good',
                    'sealing' => 'Good',
                    'cleanliness' => 'Good',
                    'remarks' => 'Hose coupling worn.',
                ],
            ],
            'scbaCylinderChecks' => [
                [
                    'id' => 'cylinder:frt:msa:6.8l-08',
                    'location' => $mainLocation,
                    'brand' => 'MSA',
                    'serialNo' => '6.8L/08',
                    'size' => '6.8',
                    'cylinderType' => 'Composite',
                    'servicePressure' => '300',
                    'containedPressure' => '280',
                    'physicalCondition' => 'Good',
                    'handwheelCondition' => 'Good',
                    'valveBodyCondition' => 'Good',
                    'screwPlugCondition' => 'Good',
                    'cleanliness' => 'Good',
                ],
            ],
            'scbaFaceMaskChecks' => [
                [
                    'id' => 'faceMask:frt:drager:02',
                    'location' => $mainLocation,
                    'brand' => 'Drager',
                    'serialNo' => '02',
                    'visorCondition' => 'Good',
                    'ldvPort' => 'Good',
                    'ldvReleaseButton' => 'Good',
                    'leakTest' => 'Not Good',
                    'speechDiaphragm' => 'Good',
                    'harness' => 'Good',
                    'neckStrap' => 'Good',
                    'remarks' => 'Leak test failed on seal.',
                ],
            ],
            'checklist' => [
                [
                    'id' => 'scba-inspection:sample',
                    'label' => 'SCBA sample check',
                    'inspectionType' => 'SCBA Inspection',
                    'selected' => true,
                ],
            ],
        ];
    }

    private function highAnglePayload(string $mainLocation): array
    {
        $checks = [];
        for ($rowNumber = 1; $rowNumber <= 24; $rowNumber++) {
            $checks[] = [
                'id' => 'response-kit-1:'.$rowNumber,
                'rowNumber' => (string) $rowNumber,
                'mainLocation' => $mainLocation,
                'location' => in_array($rowNumber, [1, 2], true) ? 'N/A' : ($rowNumber <= 20 ? 'Heavy Duty Organizer Bag' : 'Pallisade Bag'),
                'subLocation' => match (true) {
                    $rowNumber <= 2 => 'N/A',
                    $rowNumber <= 12 => 'Main Compartment',
                    $rowNumber <= 16 => '2nd Compartment',
                    $rowNumber <= 20 => '3rd Compartment',
                    default => 'N/A',
                },
                'equipment' => match ($rowNumber) {
                    1 => 'Heavy Duty Organizer Bag',
                    2 => 'Pallisade Pack',
                    3 => 'Locking Carabiner - CT - Steel - S',
                    default => 'Seeded Item '.$rowNumber,
                },
                'quantity' => match ($rowNumber) {
                    1 => '1',
                    2 => '1',
                    3 => '10',
                    default => '1',
                },
                'condition' => $rowNumber === 3 ? 'Not Good' : 'Good',
                'remarks' => $rowNumber === 3 ? 'Gate spring is sticking.' : '',
            ];
        }

        return [
            'incidentType' => 'High Angle Rescue Equipment Inspection',
            'location' => $mainLocation,
            'selectedLocation' => $mainLocation,
            'mainLocation' => $mainLocation,
            'description' => "High Angle rescue equipment checked for {$mainLocation}.",
            'highAngleInspectedBy' => 'Inspector Rope',
            'highAngleInspectionDate' => '2026-06-28',
            'photos' => [],
            'highAngleChecks' => $checks,
            'checklist' => [
                [
                    'id' => 'high-angle-rescue-equipment-inspection:sample',
                    'label' => 'High Angle sample check',
                    'inspectionType' => 'High Angle Rescue Equipment Inspection',
                    'selected' => true,
                ],
            ],
        ];
    }

    private function frtPayload(): array
    {
        $dailyChecks = [];
        foreach (range(1, 92) as $rowNumber) {
            $dailyChecks[] = [
                'id' => 'daily:fire-truck:'.$rowNumber,
                'rowNumber' => (string) $rowNumber,
                'mainLocation' => 'FIRE TRUCK',
                'location' => match (true) {
                    $rowNumber <= 6 => 'LOCKER 01',
                    $rowNumber <= 12 => 'LOCKER 02',
                    $rowNumber <= 19 => 'LOCKER 03',
                    $rowNumber <= 32 => 'LOCKER 04',
                    $rowNumber <= 45 => 'LOCKER 05',
                    $rowNumber <= 50 => 'LOCKER 06',
                    $rowNumber <= 51 => 'LOCKER 07',
                    $rowNumber <= 55 => 'LOCKER 08',
                    default => 'FIRE TRUCK',
                },
                'equipment' => match ($rowNumber) {
                    1 => 'FIRE HOSE 2.5"',
                    90 => 'OVERALL BODY',
                    91 => 'MILEAGE (ODOMETER)',
                    92 => 'FUEL LEVEL (%)',
                    default => 'Daily Item '.$rowNumber,
                },
                'quantity' => in_array($rowNumber, [91, 92], true) ? '' : '1',
                'rowKind' => in_array($rowNumber, [91, 92], true) ? 'reading' : 'status',
                'status' => $rowNumber === 90 ? 'Issue' : 'Checked',
                'readingValue' => match ($rowNumber) {
                    91 => '123456',
                    92 => '85',
                    default => '',
                },
                'remarks' => $rowNumber === 90 ? 'Panel dent needs repair.' : '',
            ];
        }

        $oneOffChecks = [];
        foreach (range(1, 46) as $rowNumber) {
            $oneOffChecks[] = [
                'id' => 'one-off:fire-truck:'.$rowNumber,
                'rowNumber' => (string) $rowNumber,
                'mainLocation' => 'FIRE TRUCK',
                'location' => match (true) {
                    $rowNumber <= 23 => 'TRUCK CHECKLIST',
                    $rowNumber <= 25 => 'LOCKER NO 01',
                    $rowNumber <= 27 => 'LOCKER NO 02',
                    $rowNumber <= 30 => 'LOCKER NO 03',
                    $rowNumber <= 36 => 'LOCKER NO 04',
                    $rowNumber <= 39 => 'LOCKER NO 05',
                    $rowNumber <= 41 => 'LOCKER NO 06',
                    $rowNumber <= 44 => 'LOCKER NO 07',
                    default => 'CREW CABIN',
                },
                'equipment' => match ($rowNumber) {
                    1 => 'POWER WINDOW',
                    16 => 'ELECTRONIC SIREN',
                    45 => 'BA SET : 4',
                    46 => 'RADIO SET : 1',
                    default => 'One Off Item '.$rowNumber,
                },
                'condition' => $rowNumber === 16 ? 'Not Good' : 'Good',
                'remarks' => $rowNumber === 16 ? 'Mute switch sticking.' : '',
            ];
        }

        return [
            'incidentType' => 'FRT Daily Inspection',
            'location' => 'FIRE TRUCK',
            'selectedLocation' => 'FIRE TRUCK',
            'mainLocation' => 'FIRE TRUCK',
            'description' => 'FRT Daily inspection checked for FIRE TRUCK.',
            'frtInspectedBy' => 'Inspector Truck',
            'frtInspectionDate' => '2026-06-29',
            'frtShift' => 'Day',
            'frtTruckReference' => [
                'plateNo' => 'AJG9555',
                'roadTaxExpiry' => '13/02/2026',
                'insuranceExpiry' => '13/02/2026',
                'puspakomExpiry' => '19/02/2026',
            ],
            'frtDailyChecks' => $dailyChecks,
            'frtDailyRemarks' => 'Truck ready for dispatch.',
            'frtOneOffChecks' => $oneOffChecks,
            'frtOneOffRemarks' => 'One-off issues tracked.',
            'photos' => [],
            'checklist' => [
                [
                    'id' => 'frt-daily-inspection:sample',
                    'label' => 'FRT sample check',
                    'inspectionType' => 'FRT Daily Inspection',
                    'selected' => true,
                ],
            ],
        ];
    }

    private function makeImageDataUrl(int $bytes): string
    {
        $binary = str_repeat('A', max(1, $bytes));
        return 'data:image/png;base64,'.base64_encode($binary);
    }

    private function grantInspectionPermission(User $user, string $roleName = 'Inspection Analytics Tester'): void
    {
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
        $user->assignRole($role);
    }
}
