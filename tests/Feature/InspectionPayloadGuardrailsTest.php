<?php

namespace Tests\Feature;

use App\Models\Report;
use App\Models\ReportDraft;
use App\Models\User;
use App\Support\Inspection\FrtDailyReference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class InspectionPayloadGuardrailsTest extends TestCase
{
    use RefreshDatabase;

    public function test_inspection_endpoints_require_inspection_permission(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->actingAs($user);

        $create = $this->postJson('/api/reports', [
            'display_id' => 'INS-GUARD-000',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => [
                'incidentType' => 'Routine Inspection',
                'location' => 'Zone Z',
                'description' => 'Permission guardrail',
                'photos' => [
                    [
                        'id' => 'photo-1',
                        'description' => 'ok',
                        'url' => $this->makeImageDataUrl(16),
                    ],
                ],
            ],
        ]);
        $create->assertStatus(403);

        $pdf = $this->postJson('/api/reports/inspection/pdf', [
            'report_uid' => 'non-existent',
        ]);
        $pdf->assertStatus(403);

        $summary = $this->getJson('/api/reports/inspection/checklist-summary');
        $summary->assertStatus(403);
    }

    public function test_inspection_report_rejects_more_than_max_photo_count(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->grantInspectionPermission($user);
        $this->actingAs($user);

        $photos = [];
        for ($i = 0; $i < 11; $i++) {
            $photos[] = [
                'id' => "photo-{$i}",
                'description' => "photo {$i}",
                'url' => $this->makeImageDataUrl(32),
            ];
        }

        $response = $this->postJson('/api/reports', [
            'display_id' => 'INS-GUARD-001',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => [
                'incidentType' => 'Routine Inspection',
                'location' => 'Zone A',
                'description' => 'Payload count guardrail',
                'photos' => $photos,
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['payload.photos']);
    }

    public function test_inspection_report_counts_nested_hydraulic_defect_photos_against_photo_limit(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->grantInspectionPermission($user);
        $this->actingAs($user);

        $defectPhotos = [];
        for ($i = 0; $i < 11; $i++) {
            $defectPhotos[] = [
                'id' => "defect-photo-{$i}",
                'description' => "defect photo {$i}",
                'url' => $this->makeImageDataUrl(32),
            ];
        }

        $response = $this->postJson('/api/reports', [
            'display_id' => 'INS-GUARD-NESTED-PHOTOS',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => [
                'incidentType' => 'Hydraulic Rescue Tools Inspection',
                'location' => 'FRT',
                'mainLocation' => 'FRT',
                'description' => 'Nested payload count guardrail',
                'photos' => [],
                'hydraulicChecks' => [
                    [
                        'id' => 'frt:hydraulic-pump-motor-1',
                        'location' => 'FRT',
                        'equipment' => 'Hydraulic Pump Motor 1',
                        'physicalCondition' => 'OK',
                        'mechanicalCondition' => 'OK',
                        'noLeakage' => 'OK',
                        'functionTest' => 'Defect',
                        'functionTestRemarks' => 'Slow response.',
                        'functionTestPhotos' => $defectPhotos,
                    ],
                ],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['payload.photos']);
    }

    public function test_inspection_report_accepts_structured_checklist_payload(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->grantInspectionPermission($user);
        $this->actingAs($user);

        $response = $this->postJson('/api/reports', [
            'display_id' => 'INS-GUARD-CHECKLIST',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => [
                'incidentType' => 'Routine Inspection',
                'location' => 'Zone Checklist',
                'description' => 'Checklist payload guardrail',
                'photos' => [
                    [
                        'id' => 'photo-1',
                        'description' => 'ok',
                        'url' => $this->makeImageDataUrl(16),
                    ],
                ],
                'checklistVersion' => 'inspection-checklist-v1',
                'checklist' => [
                    [
                        'id' => 'routine-inspection:area-checked',
                        'label' => 'Area checked',
                        'inspectionType' => 'Routine Inspection',
                        'selected' => true,
                        'selectedAt' => '2026-06-26T00:00:00.000Z',
                    ],
                ],
            ],
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.checklist.0.label', 'Area checked');
        $response->assertJsonPath('data.checklistVersion', 'inspection-checklist-v1');

        $report = Report::query()->where('display_id', 'INS-GUARD-CHECKLIST')->firstOrFail();
        $this->assertTrue((bool) $report->inspection_has_checklist);
        $this->assertContains('routine-inspection:area-checked', $report->inspection_checklist_item_ids);
        $this->assertContains('Area checked', $report->inspection_checklist_item_labels);

        $filtered = $this->getJson('/api/reports?reportType=inspection&has_checklist=true&checklist_item=routine-inspection:area-checked');
        $filtered->assertOk();
        $filtered->assertJsonCount(1, 'data');
        $filtered->assertJsonPath('data.0.displayId', 'INS-GUARD-CHECKLIST');
    }

    public function test_hydraulic_inspection_report_persists_structured_checks_to_database_and_response(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->grantInspectionPermission($user);
        $this->actingAs($user);

        $response = $this->postJson('/api/reports', [
            'display_id' => 'INS-HYD-DB',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => [
                'incidentType' => 'Hydraulic Rescue Tools Inspection',
                'location' => 'FRT',
                'mainLocation' => 'FRT',
                'description' => 'Hydraulic rescue tools checked at FRT.',
                'photos' => [
                    [
                        'id' => 'photo-1',
                        'description' => 'hydraulic evidence',
                        'url' => $this->makeImageDataUrl(16),
                    ],
                ],
                'hydraulicChecks' => [
                    [
                        'id' => 'frt:hydraulic-pump-motor-1',
                        'location' => 'FRT',
                        'equipment' => 'Hydraulic Pump Motor 1',
                        'equipmentDescription' => 'FRT primary rescue pump.',
                        'physicalCondition' => 'ok',
                        'mechanicalCondition' => 'OK',
                        'noLeakage' => 'N/A',
                        'noLeakageRemarks' => 'Leak test skipped because tool was isolated.',
                        'functionTest' => 'Defect',
                        'remarks' => 'General equipment note.',
                        'functionTestRemarks' => 'Slow response.',
                        'functionTestPhotos' => [
                            [
                                'id' => 'function-test-photo-1',
                                'description' => 'Function test defect photo',
                                'url' => $this->makeImageDataUrl(16),
                            ],
                        ],
                    ],
                ],
                'checklist' => [
                    [
                        'id' => 'hydraulic-rescue-tools-inspection:hydraulic-pump-motor-1:function-test:defect',
                        'label' => 'Hydraulic Pump Motor 1 - Function Test: Defect',
                        'inspectionType' => 'Hydraulic Rescue Tools Inspection',
                        'selected' => true,
                    ],
                ],
            ],
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.hydraulicChecks.0.physicalCondition', 'OK');
        $response->assertJsonPath('data.hydraulicChecks.0.equipmentDescription', 'FRT primary rescue pump.');
        $response->assertJsonPath('data.hydraulicChecks.0.noLeakage', 'N/A');
        $response->assertJsonPath('data.hydraulicChecks.0.noLeakageRemarks', 'Leak test skipped because tool was isolated.');
        $response->assertJsonPath('data.hydraulicChecks.0.functionTest', 'Defect');
        $response->assertJsonPath('data.hydraulicChecks.0.functionTestRemarks', 'Slow response.');
        $response->assertJsonPath('data.hydraulicChecks.0.functionTestPhotos.0.description', 'Function test defect photo');
        $response->assertJsonPath('data.checklistVersion', 'inspection-checklist-v1');

        $report = Report::query()->where('display_id', 'INS-HYD-DB')->firstOrFail();
        $this->assertSame(
            'Hydraulic Pump Motor 1',
            $report->payload['hydraulicChecks'][0]['equipment'] ?? null,
        );
        $this->assertSame(
            'FRT primary rescue pump.',
            $report->payload['hydraulicChecks'][0]['equipmentDescription'] ?? null,
        );
        $this->assertSame('OK', $report->payload['hydraulicChecks'][0]['physicalCondition'] ?? null);
        $this->assertSame('N/A', $report->payload['hydraulicChecks'][0]['noLeakage'] ?? null);
        $this->assertSame(
            'Leak test skipped because tool was isolated.',
            $report->payload['hydraulicChecks'][0]['noLeakageRemarks'] ?? null,
        );
        $this->assertSame('Defect', $report->payload['hydraulicChecks'][0]['functionTest'] ?? null);
        $this->assertSame('Slow response.', $report->payload['hydraulicChecks'][0]['functionTestRemarks'] ?? null);
        $this->assertSame(
            'Function test defect photo',
            $report->payload['hydraulicChecks'][0]['functionTestPhotos'][0]['description'] ?? null,
        );
        $this->assertTrue((bool) $report->inspection_has_checklist);
        $this->assertContains(
            'Hydraulic Pump Motor 1 - Function Test: Defect',
            $report->inspection_checklist_item_labels,
        );

        $fetched = $this->getJson('/api/reports?reportType=inspection&checklist_item=Hydraulic%20Pump%20Motor%201%20-%20Function%20Test:%20Defect');
        $fetched->assertOk();
        $fetched->assertJsonPath('data.0.hydraulicChecks.0.functionTestRemarks', 'Slow response.');
    }

    public function test_hydraulic_inspection_draft_persists_structured_checks_to_database(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->grantInspectionPermission($user);
        $this->actingAs($user);

        $response = $this->postJson('/api/reports/draft', [
            'report_type' => 'inspection',
            'payload' => [
                'incidentType' => 'Hydraulic Rescue Tools Inspection',
                'location' => 'Store',
                'mainLocation' => 'Store',
                'photos' => [],
                'hydraulic_checks' => [
                    [
                        'location' => 'Store',
                        'equipment' => 'Hydraulic Cutter 2',
                        'physical_condition' => 'N/A',
                        'function_test' => 'OK',
                        'function_test_remarks' => 'Works during draft check.',
                        'function_test_photos' => [
                            [
                                'id' => 'draft-function-photo-1',
                                'description' => 'Draft function evidence',
                                'url' => $this->makeImageDataUrl(16),
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.payload.hydraulicChecks.0.physicalCondition', 'N/A');
        $response->assertJsonPath('data.payload.hydraulicChecks.0.functionTest', 'OK');
        $response->assertJsonPath('data.payload.hydraulicChecks.0.functionTestRemarks', 'Works during draft check.');
        $response->assertJsonPath('data.payload.hydraulicChecks.0.functionTestPhotos.0.description', 'Draft function evidence');
        $response->assertJsonPath('data.payload.checklist', []);

        $draft = ReportDraft::query()
            ->where('user_id', $user->id)
            ->where('report_type', 'inspection')
            ->firstOrFail();

        $this->assertSame('Hydraulic Cutter 2', $draft->payload['hydraulicChecks'][0]['equipment'] ?? null);
        $this->assertSame('N/A', $draft->payload['hydraulicChecks'][0]['physicalCondition'] ?? null);
        $this->assertSame('Works during draft check.', $draft->payload['hydraulicChecks'][0]['functionTestRemarks'] ?? null);
        $this->assertArrayNotHasKey('hydraulic_checks', $draft->payload);
    }

    public function test_er_aux_inspection_report_persists_structured_checks_to_database_and_response(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->grantInspectionPermission($user);
        $this->actingAs($user);

        $response = $this->postJson('/api/reports', [
            'display_id' => 'INS-ERAUX-DB',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => [
                'incidentType' => 'ER Aux Equipment Inspection',
                'location' => 'Store',
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
            ],
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.erAuxInspectedBy', 'Inspector One');
        $response->assertJsonPath('data.erAuxInspectionDate', '2026-06-28');
        $response->assertJsonPath('data.erAuxChecks.1.condition', 'Missing');
        $response->assertJsonPath('data.erAuxChecks.1.remarks', 'Sent for replacement.');

        $report = Report::query()->where('display_id', 'INS-ERAUX-DB')->firstOrFail();
        $this->assertSame('Inspector One', $report->payload['erAuxInspectedBy'] ?? null);
        $this->assertSame('2026-06-28', $report->payload['erAuxInspectionDate'] ?? null);
        $this->assertSame('Fire Jacket', $report->payload['erAuxChecks'][0]['equipment'] ?? null);
        $this->assertSame('15', $report->payload['erAuxChecks'][0]['quantity'] ?? null);
        $this->assertSame('Missing', $report->payload['erAuxChecks'][1]['condition'] ?? null);
        $this->assertSame('Sent for replacement.', $report->payload['erAuxChecks'][1]['remarks'] ?? null);
    }

    public function test_scba_inspection_report_persists_structured_checks_to_database_and_response(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->grantInspectionPermission($user);
        $this->actingAs($user);

        $response = $this->postJson('/api/reports', [
            'display_id' => 'INS-SCBA-DB',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => [
                'incidentType' => 'SCBA Inspection',
                'location' => 'FRT',
                'mainLocation' => 'FRT',
                'scbaInspectedBy' => 'Inspector SCBA',
                'scbaInspectionDate' => '2026-06-28',
                'photos' => [],
                'scbaBackPlateChecks' => [
                    [
                        'id' => 'backPlate:frt:msa:06',
                        'location' => 'FRT',
                        'brand' => 'MSA',
                        'serialNo' => '06',
                        'backPlateHarnessCondition' => 'good',
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
                        'location' => 'FRT',
                        'brand' => 'MSA',
                        'serialNo' => '6.8L/08',
                        'size' => '6.8',
                        'type' => 'Composite',
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
                        'location' => 'FRT',
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
            ],
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.scbaInspectedBy', 'Inspector SCBA');
        $response->assertJsonPath('data.scbaInspectionDate', '2026-06-28');
        $response->assertJsonPath('data.scbaBackPlateChecks.0.backPlateHarnessCondition', 'Good');
        $response->assertJsonPath('data.scbaBackPlateChecks.0.highPressureHose', 'Not Good');
        $response->assertJsonPath('data.scbaBackPlateChecks.0.sealing', 'Good');
        $response->assertJsonPath('data.scbaBackPlateChecks.0.cleanliness', 'Good');
        $response->assertJsonPath('data.scbaCylinderChecks.0.cylinderType', 'Composite');
        $response->assertJsonPath('data.scbaCylinderChecks.0.servicePressure', '300');
        $response->assertJsonPath('data.scbaCylinderChecks.0.containedPressure', '280');
        $response->assertJsonPath('data.scbaCylinderChecks.0.cleanliness', 'Good');
        $response->assertJsonPath('data.scbaFaceMaskChecks.0.leakTest', 'Not Good');
        $response->assertJsonPath('data.scbaFaceMaskChecks.0.harness', 'Good');

        $report = Report::query()->where('display_id', 'INS-SCBA-DB')->firstOrFail();
        $this->assertSame('Inspector SCBA', $report->payload['scbaInspectedBy'] ?? null);
        $this->assertSame('2026-06-28', $report->payload['scbaInspectionDate'] ?? null);
        $this->assertSame('Good', $report->payload['scbaBackPlateChecks'][0]['backPlateHarnessCondition'] ?? null);
        $this->assertSame('Not Good', $report->payload['scbaBackPlateChecks'][0]['highPressureHose'] ?? null);
        $this->assertSame('Good', $report->payload['scbaBackPlateChecks'][0]['sealing'] ?? null);
        $this->assertSame('Good', $report->payload['scbaBackPlateChecks'][0]['cleanliness'] ?? null);
        $this->assertSame('Composite', $report->payload['scbaCylinderChecks'][0]['cylinderType'] ?? null);
        $this->assertSame('300', $report->payload['scbaCylinderChecks'][0]['servicePressure'] ?? null);
        $this->assertSame('280', $report->payload['scbaCylinderChecks'][0]['containedPressure'] ?? null);
        $this->assertSame('Good', $report->payload['scbaCylinderChecks'][0]['cleanliness'] ?? null);
        $this->assertSame('Not Good', $report->payload['scbaFaceMaskChecks'][0]['leakTest'] ?? null);
        $this->assertSame('Good', $report->payload['scbaFaceMaskChecks'][0]['harness'] ?? null);
    }

    public function test_scba_inspection_draft_persists_structured_checks_to_database(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->grantInspectionPermission($user);
        $this->actingAs($user);

        $response = $this->postJson('/api/reports/draft', [
            'report_type' => 'inspection',
            'payload' => [
                'incidentType' => 'SCBA Inspection',
                'location' => 'Store',
                'mainLocation' => 'Store',
                'scba_inspected_by' => 'Draft Inspector',
                'scba_inspection_date' => '2026-06-28',
                'scba_back_plate_checks' => [
                    [
                        'location' => 'Store',
                        'brand' => 'MSA',
                        'serial_no' => '01',
                        'back_plate_harness_condition' => 'Good',
                        'high_pressure_hose' => 'Good',
                        'pressure_gauge' => 'Good',
                        'alarm_device' => 'Good',
                        'demand_valve' => 'Good',
                        'sealing' => 'Good',
                        'cleanliness' => 'Good',
                    ],
                ],
                'scba_cylinder_checks' => [
                    [
                        'location' => 'Store',
                        'brand' => 'Drager',
                        'serial_no' => '6L/01',
                        'size' => '6',
                        'cylinder_type' => 'Steel',
                        'service_pressure' => '200',
                        'contained_pressure' => '180',
                        'physical_condition' => 'Good',
                        'handwheel_condition' => 'Good',
                        'valve_body_condition' => 'Good',
                        'screw_plug_condition' => 'Good',
                        'cleanliness' => 'Good',
                    ],
                ],
                'scba_face_mask_checks' => [
                    [
                        'location' => 'Store',
                        'brand' => 'Drager',
                        'serial_no' => '07',
                        'visor_condition' => 'Good',
                        'ldv_port' => 'Good',
                        'ldv_release_button' => 'Good',
                        'leak_test' => 'Good',
                        'speech_diaphragm' => 'Good',
                        'harness' => 'Good',
                        'neck_strap' => 'Good',
                    ],
                ],
            ],
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.payload.scbaInspectedBy', 'Draft Inspector');
        $response->assertJsonPath('data.payload.scbaInspectionDate', '2026-06-28');
        $response->assertJsonPath('data.payload.scbaBackPlateChecks.0.serialNo', '01');
        $response->assertJsonPath('data.payload.scbaBackPlateChecks.0.cleanliness', 'Good');
        $response->assertJsonPath('data.payload.scbaCylinderChecks.0.cylinderType', 'Steel');
        $response->assertJsonPath('data.payload.scbaCylinderChecks.0.servicePressure', '200');
        $response->assertJsonPath('data.payload.scbaCylinderChecks.0.containedPressure', '180');
        $response->assertJsonPath('data.payload.scbaCylinderChecks.0.cleanliness', 'Good');
        $response->assertJsonPath('data.payload.scbaFaceMaskChecks.0.leakTest', 'Good');
        $response->assertJsonPath('data.payload.scbaFaceMaskChecks.0.harness', 'Good');

        $draft = ReportDraft::query()
            ->where('user_id', $user->id)
            ->where('report_type', 'inspection')
            ->firstOrFail();

        $this->assertSame('Draft Inspector', $draft->payload['scbaInspectedBy'] ?? null);
        $this->assertSame('Good', $draft->payload['scbaBackPlateChecks'][0]['cleanliness'] ?? null);
        $this->assertSame('Steel', $draft->payload['scbaCylinderChecks'][0]['cylinderType'] ?? null);
        $this->assertSame('200', $draft->payload['scbaCylinderChecks'][0]['servicePressure'] ?? null);
        $this->assertSame('180', $draft->payload['scbaCylinderChecks'][0]['containedPressure'] ?? null);
        $this->assertSame('Good', $draft->payload['scbaCylinderChecks'][0]['cleanliness'] ?? null);
        $this->assertSame('Good', $draft->payload['scbaFaceMaskChecks'][0]['leakTest'] ?? null);
        $this->assertSame('Good', $draft->payload['scbaFaceMaskChecks'][0]['harness'] ?? null);
        $this->assertArrayNotHasKey('scba_back_plate_checks', $draft->payload);
        $this->assertArrayNotHasKey('scba_cylinder_checks', $draft->payload);
        $this->assertArrayNotHasKey('scba_face_mask_checks', $draft->payload);
    }

    public function test_high_angle_inspection_report_persists_structured_checks_to_database_and_response(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->grantInspectionPermission($user);
        $this->actingAs($user);

        $response = $this->postJson('/api/reports', [
            'display_id' => 'INS-HA-DB',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => [
                'incidentType' => 'High Angle Rescue Equipment Inspection',
                'location' => 'Response Kit #1',
                'mainLocation' => 'Response Kit #1',
                'highAngleInspectedBy' => 'Inspector Rope',
                'highAngleInspectionDate' => '2026-06-28',
                'photos' => [],
                'highAngleChecks' => [
                    [
                        'id' => 'response-kit-1:1',
                        'rowNumber' => '1',
                        'mainLocation' => 'Response Kit #1',
                        'location' => 'N/A',
                        'subLocation' => 'N/A',
                        'equipment' => 'Heavy Duty Organizer Bag',
                        'quantity' => '1',
                        'condition' => 'good',
                        'remarks' => '',
                    ],
                    [
                        'id' => 'response-kit-1:3',
                        'rowNumber' => '3',
                        'mainLocation' => 'Response Kit #1',
                        'location' => 'Heavy Duty Organizer Bag',
                        'subLocation' => 'Main Compartment',
                        'equipment' => 'Locking Carabiner - CT - Steel - S',
                        'quantity' => '10',
                        'condition' => 'Not Good',
                        'remarks' => 'Gate spring is sticking.',
                    ],
                ],
            ],
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.highAngleInspectedBy', 'Inspector Rope');
        $response->assertJsonPath('data.highAngleInspectionDate', '2026-06-28');
        $response->assertJsonPath('data.highAngleChecks.0.condition', 'Good');
        $response->assertJsonPath('data.highAngleChecks.1.subLocation', 'Main Compartment');
        $response->assertJsonPath('data.highAngleChecks.1.quantity', '10');
        $response->assertJsonPath('data.highAngleChecks.1.remarks', 'Gate spring is sticking.');

        $report = Report::query()->where('display_id', 'INS-HA-DB')->firstOrFail();
        $this->assertSame('Inspector Rope', $report->payload['highAngleInspectedBy'] ?? null);
        $this->assertSame('2026-06-28', $report->payload['highAngleInspectionDate'] ?? null);
        $this->assertSame('Good', $report->payload['highAngleChecks'][0]['condition'] ?? null);
        $this->assertSame('Main Compartment', $report->payload['highAngleChecks'][1]['subLocation'] ?? null);
        $this->assertSame('10', $report->payload['highAngleChecks'][1]['quantity'] ?? null);
        $this->assertSame('Gate spring is sticking.', $report->payload['highAngleChecks'][1]['remarks'] ?? null);
    }

    public function test_high_angle_inspection_draft_persists_structured_checks_to_database(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->grantInspectionPermission($user);
        $this->actingAs($user);

        $response = $this->postJson('/api/reports/draft', [
            'report_type' => 'inspection',
            'payload' => [
                'incidentType' => 'High Angle Rescue Equipment Inspection',
                'location' => 'Rescue Rope',
                'mainLocation' => 'Rescue Rope',
                'high_angle_inspected_by' => 'Draft Rope Inspector',
                'high_angle_inspection_date' => '2026-06-28',
                'high_angle_checks' => [
                    [
                        'id' => 'rescue-rope:101',
                        'row_number' => '101',
                        'main_location' => 'Rescue Rope',
                        'location' => 'N/A',
                        'sub_location' => 'N/A',
                        'equipment' => 'R – 13.0mm - 200m – 001/6-2021',
                        'quantity' => '1',
                        'condition' => 'Not Good',
                        'remarks' => 'Outer sheath frayed.',
                    ],
                ],
            ],
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.payload.highAngleInspectedBy', 'Draft Rope Inspector');
        $response->assertJsonPath('data.payload.highAngleInspectionDate', '2026-06-28');
        $response->assertJsonPath('data.payload.highAngleChecks.0.rowNumber', '101');
        $response->assertJsonPath('data.payload.highAngleChecks.0.mainLocation', 'Rescue Rope');
        $response->assertJsonPath('data.payload.highAngleChecks.0.remarks', 'Outer sheath frayed.');

        $draft = ReportDraft::query()
            ->where('user_id', $user->id)
            ->where('report_type', 'inspection')
            ->firstOrFail();

        $this->assertSame('Draft Rope Inspector', $draft->payload['highAngleInspectedBy'] ?? null);
        $this->assertSame('2026-06-28', $draft->payload['highAngleInspectionDate'] ?? null);
        $this->assertSame('101', $draft->payload['highAngleChecks'][0]['rowNumber'] ?? null);
        $this->assertSame('Rescue Rope', $draft->payload['highAngleChecks'][0]['mainLocation'] ?? null);
        $this->assertSame('Outer sheath frayed.', $draft->payload['highAngleChecks'][0]['remarks'] ?? null);
        $this->assertArrayNotHasKey('high_angle_checks', $draft->payload);
    }

    public function test_frt_inspection_report_persists_structured_checks_to_database_and_response(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->grantInspectionPermission($user);
        $this->actingAs($user);

        $response = $this->postJson('/api/reports', [
            'display_id' => 'INS-FRT-DB',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => $this->frtPayload(),
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.frtInspectedBy', 'Inspector Truck');
        $response->assertJsonPath('data.frtInspectionDate', '2026-06-28');
        $response->assertJsonPath('data.frtShift', 'Day');
        $response->assertJsonPath('data.frtTruckReference.plateNo', 'AJG9555');
        $dailyRows = collect($response->json('data.frtDailyChecks') ?? [])->keyBy('id');
        $oneOffRows = collect($response->json('data.frtOneOffChecks') ?? [])->keyBy('id');
        $this->assertSame('Checked', $dailyRows->get('daily:fire-truck:56')['status'] ?? null);
        $this->assertSame('123456', $dailyRows->get('daily:fire-truck:91')['readingValue'] ?? null);
        $this->assertSame('Not Good', $oneOffRows->get('one-off:fire-truck:16')['condition'] ?? null);
        $this->assertSame('Siren mute switch sticking.', $oneOffRows->get('one-off:fire-truck:16')['remarks'] ?? null);

        $report = Report::query()->where('display_id', 'INS-FRT-DB')->firstOrFail();
        $this->assertSame('Inspector Truck', $report->payload['frtInspectedBy'] ?? null);
        $this->assertSame('2026-06-28', $report->payload['frtInspectionDate'] ?? null);
        $this->assertSame('Day', $report->payload['frtShift'] ?? null);
        $this->assertSame('AJG9555', $report->payload['frtTruckReference']['plateNo'] ?? null);
        $reportDailyRows = collect($report->payload['frtDailyChecks'] ?? [])->keyBy('id');
        $reportOneOffRows = collect($report->payload['frtOneOffChecks'] ?? [])->keyBy('id');
        $this->assertSame('Checked', $reportDailyRows->get('daily:fire-truck:56')['status'] ?? null);
        $this->assertSame('123456', $reportDailyRows->get('daily:fire-truck:91')['readingValue'] ?? null);
        $this->assertSame('Not Good', $reportOneOffRows->get('one-off:fire-truck:16')['condition'] ?? null);
        $this->assertSame('Siren mute switch sticking.', $reportOneOffRows->get('one-off:fire-truck:16')['remarks'] ?? null);
    }

    public function test_frt_inspection_draft_persists_structured_checks_to_database(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->grantInspectionPermission($user);
        $this->actingAs($user);

        $response = $this->postJson('/api/reports/draft', [
            'report_type' => 'inspection',
            'payload' => $this->frtPayload(useSnakeCase: true),
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.payload.frtInspectedBy', 'Inspector Truck');
        $response->assertJsonPath('data.payload.frtInspectionDate', '2026-06-28');
        $response->assertJsonPath('data.payload.frtShift', 'Day');
        $response->assertJsonPath('data.payload.frtTruckReference.plateNo', 'AJG9555');
        $draftResponseDailyRows = collect($response->json('data.payload.frtDailyChecks') ?? [])->keyBy('id');
        $draftResponseOneOffRows = collect($response->json('data.payload.frtOneOffChecks') ?? [])->keyBy('id');
        $this->assertSame('123456', $draftResponseDailyRows->get('daily:fire-truck:91')['readingValue'] ?? null);
        $this->assertSame('Not Good', $draftResponseOneOffRows->get('one-off:fire-truck:16')['condition'] ?? null);

        $draft = ReportDraft::query()
            ->where('user_id', $user->id)
            ->where('report_type', 'inspection')
            ->firstOrFail();

        $this->assertSame('Inspector Truck', $draft->payload['frtInspectedBy'] ?? null);
        $this->assertSame('2026-06-28', $draft->payload['frtInspectionDate'] ?? null);
        $this->assertSame('Day', $draft->payload['frtShift'] ?? null);
        $this->assertSame('AJG9555', $draft->payload['frtTruckReference']['plateNo'] ?? null);
        $draftDailyRows = collect($draft->payload['frtDailyChecks'] ?? [])->keyBy('id');
        $draftOneOffRows = collect($draft->payload['frtOneOffChecks'] ?? [])->keyBy('id');
        $this->assertSame('123456', $draftDailyRows->get('daily:fire-truck:91')['readingValue'] ?? null);
        $this->assertSame('Not Good', $draftOneOffRows->get('one-off:fire-truck:16')['condition'] ?? null);
        $this->assertArrayNotHasKey('frt_daily_checks', $draft->payload);
        $this->assertArrayNotHasKey('frt_one_off_checks', $draft->payload);
    }

    public function test_inspection_report_rejects_invalid_frt_daily_status(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->grantInspectionPermission($user);
        $this->actingAs($user);

        $payload = $this->frtPayload();
        $payload['frtDailyChecks'][55]['status'] = 'Broken';

        $response = $this->postJson('/api/reports', [
            'display_id' => 'INS-GUARD-BAD-FRT-DAILY',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => $payload,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['payload.frtDailyChecks.55.status']);
    }

    public function test_inspection_report_rejects_invalid_frt_one_off_status(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->grantInspectionPermission($user);
        $this->actingAs($user);

        $payload = $this->frtPayload();
        $payload['frtOneOffChecks'][15]['condition'] = 'Broken';

        $response = $this->postJson('/api/reports', [
            'display_id' => 'INS-GUARD-BAD-FRT-ONEOFF',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => $payload,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['payload.frtOneOffChecks.15.condition']);
    }

    public function test_inspection_report_rejects_frt_missing_daily_reading_value(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->grantInspectionPermission($user);
        $this->actingAs($user);

        $payload = $this->frtPayload();
        $payload['frtDailyChecks'][90]['readingValue'] = '';

        $response = $this->postJson('/api/reports', [
            'display_id' => 'INS-GUARD-FRT-READING',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => $payload,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['payload.frtDailyChecks.90.readingValue']);
    }

    public function test_inspection_report_rejects_frt_issue_rows_without_remarks(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->grantInspectionPermission($user);
        $this->actingAs($user);

        $payload = $this->frtPayload();
        $payload['frtDailyChecks'][89]['remarks'] = '';

        $response = $this->postJson('/api/reports', [
            'display_id' => 'INS-GUARD-FRT-ISSUE-REMARKS',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => $payload,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['payload.frtDailyChecks.89.remarks']);
    }

    public function test_inspection_report_rejects_frt_not_good_rows_without_remarks(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->grantInspectionPermission($user);
        $this->actingAs($user);

        $payload = $this->frtPayload();
        $payload['frtOneOffChecks'][15]['remarks'] = '';

        $response = $this->postJson('/api/reports', [
            'display_id' => 'INS-GUARD-FRT-NOT-GOOD-REMARKS',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => $payload,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['payload.frtOneOffChecks.15.remarks']);
    }

    public function test_inspection_report_rejects_frt_reports_with_incomplete_seeded_roster(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->grantInspectionPermission($user);
        $this->actingAs($user);

        $payload = $this->frtPayload();
        array_pop($payload['frtDailyChecks']);

        $response = $this->postJson('/api/reports', [
            'display_id' => 'INS-GUARD-FRT-ROSTER',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => $payload,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['payload.frtDailyChecks']);
    }

    public function test_inspection_report_rejects_frt_reports_without_required_session_meta(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->grantInspectionPermission($user);
        $this->actingAs($user);

        $payload = $this->frtPayload();
        unset($payload['frtInspectedBy']);

        $missingInspector = $this->postJson('/api/reports', [
            'display_id' => 'INS-GUARD-FRT-META-1',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => $payload,
        ]);
        $missingInspector->assertStatus(422);
        $missingInspector->assertJsonValidationErrors(['payload.frtInspectedBy']);

        $payload = $this->frtPayload();
        $payload['frtShift'] = '';

        $missingShift = $this->postJson('/api/reports', [
            'display_id' => 'INS-GUARD-FRT-META-2',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => $payload,
        ]);
        $missingShift->assertStatus(422);
        $missingShift->assertJsonValidationErrors(['payload.frtShift']);
    }

    public function test_inspection_report_rejects_invalid_scba_check_payload(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->grantInspectionPermission($user);
        $this->actingAs($user);

        $response = $this->postJson('/api/reports', [
            'display_id' => 'INS-GUARD-BAD-SCBA',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => [
                'incidentType' => 'SCBA Inspection',
                'location' => 'FRT',
                'scbaBackPlateChecks' => [
                    [
                        'location' => 'FRT',
                        'brand' => 'MSA',
                        'serialNo' => '06',
                        'backPlateHarnessCondition' => 'Broken',
                    ],
                ],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['payload.scbaBackPlateChecks.0.backPlateHarnessCondition']);
    }

    public function test_inspection_report_rejects_scba_not_good_rows_without_remarks(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->grantInspectionPermission($user);
        $this->actingAs($user);

        $response = $this->postJson('/api/reports', [
            'display_id' => 'INS-GUARD-SCBA-REMARKS',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => [
                'incidentType' => 'SCBA Inspection',
                'location' => 'FRT',
                'mainLocation' => 'FRT',
                'scbaFaceMaskChecks' => [
                    [
                        'location' => 'FRT',
                        'brand' => 'Drager',
                        'serialNo' => '02',
                        'visorCondition' => 'Good',
                        'ldvPort' => 'Good',
                        'ldvReleaseButton' => 'Good',
                        'leakTest' => 'Not Good',
                        'speechDiaphragm' => 'Good',
                        'harness' => 'Good',
                        'neckStrap' => 'Good',
                        'remarks' => '',
                    ],
                ],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['payload.scbaFaceMaskChecks.0.remarks']);
    }

    public function test_inspection_report_rejects_invalid_high_angle_check_payload(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->grantInspectionPermission($user);
        $this->actingAs($user);

        $response = $this->postJson('/api/reports', [
            'display_id' => 'INS-GUARD-BAD-HA',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => [
                'incidentType' => 'High Angle Rescue Equipment Inspection',
                'location' => 'Response Kit #1',
                'highAngleChecks' => [
                    [
                        'mainLocation' => 'Response Kit #1',
                        'equipment' => 'Heavy Duty Organizer Bag',
                        'condition' => 'Broken',
                    ],
                ],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['payload.highAngleChecks.0.condition']);
    }

    public function test_inspection_report_rejects_high_angle_not_good_rows_without_remarks(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->grantInspectionPermission($user);
        $this->actingAs($user);

        $response = $this->postJson('/api/reports', [
            'display_id' => 'INS-GUARD-HA-REMARKS',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => [
                'incidentType' => 'High Angle Rescue Equipment Inspection',
                'location' => 'Response Kit #1',
                'mainLocation' => 'Response Kit #1',
                'highAngleInspectedBy' => 'Inspector Rope',
                'highAngleInspectionDate' => '2026-06-28',
                'highAngleChecks' => [
                    [
                        'rowNumber' => '3',
                        'mainLocation' => 'Response Kit #1',
                        'location' => 'Heavy Duty Organizer Bag',
                        'subLocation' => 'Main Compartment',
                        'equipment' => 'Locking Carabiner - CT - Steel - S',
                        'quantity' => '10',
                        'condition' => 'Not Good',
                        'remarks' => '',
                    ],
                ],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['payload.highAngleChecks.0.remarks']);
    }

    public function test_inspection_report_rejects_high_angle_reports_without_session_meta(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->grantInspectionPermission($user);
        $this->actingAs($user);

        $basePayload = [
            'incidentType' => 'High Angle Rescue Equipment Inspection',
            'location' => 'Response Kit #1',
            'mainLocation' => 'Response Kit #1',
            'highAngleChecks' => [
                [
                    'id' => 'response-kit-1:1',
                    'rowNumber' => '1',
                    'mainLocation' => 'Response Kit #1',
                    'location' => 'N/A',
                    'subLocation' => 'N/A',
                    'equipment' => 'Heavy Duty Organizer Bag',
                    'quantity' => '1',
                    'condition' => 'Good',
                    'remarks' => '',
                ],
            ],
        ];

        $missingInspector = $this->postJson('/api/reports', [
            'display_id' => 'INS-GUARD-HA-META',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => $basePayload,
        ]);

        $missingInspector->assertStatus(422);
        $missingInspector->assertJsonValidationErrors([
            'payload.highAngleInspectedBy',
        ]);

        $missingDate = $this->postJson('/api/reports', [
            'display_id' => 'INS-GUARD-HA-META-DATE',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => array_merge($basePayload, [
                'highAngleInspectedBy' => 'Inspector Rope',
            ]),
        ]);

        $missingDate->assertStatus(422);
        $missingDate->assertJsonValidationErrors([
            'payload.highAngleInspectionDate',
        ]);
    }

    public function test_inspection_report_rejects_invalid_checklist_payload(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->grantInspectionPermission($user);
        $this->actingAs($user);

        $response = $this->postJson('/api/reports', [
            'display_id' => 'INS-GUARD-BAD-CHECKLIST',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => [
                'incidentType' => 'Routine Inspection',
                'location' => 'Zone Checklist',
                'description' => 'Checklist payload guardrail',
                'photos' => [
                    [
                        'id' => 'photo-1',
                        'description' => 'ok',
                        'url' => $this->makeImageDataUrl(16),
                    ],
                ],
                'checklist' => [
                    ['id' => 'missing-label'],
                ],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['payload.checklist.0.label']);
    }

    public function test_inspection_report_rejects_invalid_hydraulic_check_payload(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->grantInspectionPermission($user);
        $this->actingAs($user);

        $response = $this->postJson('/api/reports', [
            'display_id' => 'INS-GUARD-BAD-HYD',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => [
                'incidentType' => 'Hydraulic Rescue Tools Inspection',
                'location' => 'FRT',
                'description' => 'Hydraulic payload guardrail',
                'photos' => [
                    [
                        'id' => 'photo-1',
                        'description' => 'ok',
                        'url' => $this->makeImageDataUrl(16),
                    ],
                ],
                'hydraulicChecks' => [
                    [
                        'location' => 'FRT',
                        'equipment' => 'Hydraulic Pump Motor 1',
                        'physicalCondition' => 'Broken',
                    ],
                ],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['payload.hydraulicChecks.0.physicalCondition']);
    }

    public function test_inspection_report_rejects_invalid_er_aux_check_payload(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->grantInspectionPermission($user);
        $this->actingAs($user);

        $response = $this->postJson('/api/reports', [
            'display_id' => 'INS-GUARD-BAD-ERAUX',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => [
                'incidentType' => 'ER Aux Equipment Inspection',
                'location' => 'Store',
                'erAuxChecks' => [
                    [
                        'location' => 'Store',
                        'equipment' => 'Chainsaw',
                        'condition' => 'Broken',
                    ],
                ],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['payload.erAuxChecks.0.condition']);
    }

    public function test_inspection_checklist_summary_counts_and_filters_reports(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->grantInspectionPermission($user);
        $this->actingAs($user);

        $this->postJson('/api/reports', [
            'display_id' => 'INS-SUMMARY-001',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => [
                'incidentType' => 'Pump House',
                'location' => 'Zone Summary',
                'description' => 'Checklist summary report',
                'photos' => [
                    [
                        'id' => 'photo-1',
                        'description' => 'ok',
                        'url' => $this->makeImageDataUrl(16),
                    ],
                ],
                'checklist' => [
                    [
                        'id' => 'pump-house:pressure-checked',
                        'label' => 'Pressure checked',
                        'inspectionType' => 'Pump House',
                        'selected' => true,
                    ],
                    [
                        'id' => 'pump-house:access-clear',
                        'label' => 'Access clear',
                        'inspectionType' => 'Pump House',
                        'selected' => true,
                    ],
                ],
            ],
        ])->assertCreated();

        $this->postJson('/api/reports', [
            'display_id' => 'INS-SUMMARY-002',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => [
                'incidentType' => 'Housekeeping',
                'location' => 'Zone Summary',
                'description' => 'Legacy no checklist report',
                'photos' => [
                    [
                        'id' => 'photo-1',
                        'description' => 'ok',
                        'url' => $this->makeImageDataUrl(16),
                    ],
                ],
            ],
        ])->assertCreated();

        $summary = $this->getJson('/api/reports/inspection/checklist-summary');
        $summary->assertOk();
        $summary->assertJsonPath('data.totalReports', 2);
        $summary->assertJsonPath('data.withChecklist', 1);
        $summary->assertJsonPath('data.withoutChecklist', 1);
        $summary->assertJsonPath('data.items.0.id', 'pump-house:access-clear');

        $filtered = $this->getJson('/api/reports/inspection/checklist-summary?has_checklist=true&checklist_item=pump-house:pressure-checked');
        $filtered->assertOk();
        $filtered->assertJsonPath('data.totalReports', 1);
        $filtered->assertJsonPath('data.items.0.label', 'Pressure checked');

        $typeFiltered = $this->getJson('/api/reports/inspection/checklist-summary?inspection_type=Housekeeping&has_checklist=false');
        $typeFiltered->assertOk();
        $typeFiltered->assertJsonPath('data.totalReports', 1);
        $typeFiltered->assertJsonPath('data.withoutChecklist', 1);
    }

    public function test_inspection_update_conflict_returns_current_report_snapshot(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->grantInspectionPermission($user);
        $this->actingAs($user);

        $create = $this->postJson('/api/reports', [
            'display_id' => 'INS-GUARD-CONFLICT',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => [
                'incidentType' => 'Routine Inspection',
                'location' => 'Zone Conflict',
                'description' => 'Original',
                'photos' => [
                    [
                        'id' => 'photo-1',
                        'description' => 'ok',
                        'url' => $this->makeImageDataUrl(16),
                    ],
                ],
            ],
        ]);
        $create->assertCreated();
        $reportUid = (string) $create->json('data.id');

        $firstUpdate = $this->putJson("/api/reports/{$reportUid}", [
            'version' => 1,
            'status' => 'Submitted',
            'payload' => [
                'incidentType' => 'Routine Inspection',
                'location' => 'Zone Conflict',
                'description' => 'Server changed',
                'photos' => [
                    [
                        'id' => 'photo-1',
                        'description' => 'ok',
                        'url' => $this->makeImageDataUrl(16),
                    ],
                ],
            ],
        ]);
        $firstUpdate->assertOk();

        $conflict = $this->putJson("/api/reports/{$reportUid}", [
            'version' => 1,
            'status' => 'Submitted',
            'payload' => [
                'incidentType' => 'Routine Inspection',
                'location' => 'Zone Conflict',
                'description' => 'Offline local edit',
                'photos' => [
                    [
                        'id' => 'photo-1',
                        'description' => 'ok',
                        'url' => $this->makeImageDataUrl(16),
                    ],
                ],
            ],
        ]);

        $conflict->assertStatus(409);
        $conflict->assertJsonPath('code', 'REPORT_VERSION_CONFLICT');
        $conflict->assertJsonPath('currentReport.description', 'Server changed');
    }

    public function test_inspection_report_rejects_non_data_url_photo(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->grantInspectionPermission($user);
        $this->actingAs($user);

        $response = $this->postJson('/api/reports', [
            'display_id' => 'INS-GUARD-002',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => [
                'incidentType' => 'Routine Inspection',
                'location' => 'Zone B',
                'description' => 'Payload URL guardrail',
                'photos' => [
                    [
                        'id' => 'photo-1',
                        'description' => 'invalid remote url',
                        'url' => 'https://example.test/photo.jpg',
                    ],
                ],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['payload.photos.0.url']);
    }

    public function test_inspection_draft_rejects_non_data_url_photo(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->grantInspectionPermission($user);
        $this->actingAs($user);

        $response = $this->postJson('/api/reports/draft', [
            'report_type' => 'inspection',
            'payload' => [
                'incidentType' => 'Routine Inspection',
                'location' => 'Zone C',
                'description' => 'Draft URL guardrail',
                'photos' => [
                    [
                        'id' => 'photo-1',
                        'description' => 'invalid remote url',
                        'url' => 'https://example.test/photo.jpg',
                    ],
                ],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['payload.photos.0.url']);
    }

    private function makeImageDataUrl(int $bytes): string
    {
        $binary = str_repeat('A', max(1, $bytes));
        return 'data:image/png;base64,'.base64_encode($binary);
    }

    private function frtPayload(bool $useSnakeCase = false): array
    {
        $dailyChecks = array_map(function (array $row): array {
            $isReading = ($row['rowKind'] ?? 'status') === 'reading';
            $status = '';
            $readingValue = '';
            $remarks = '';

            if ($row['id'] === 'daily:fire-truck:90') {
                $status = 'Issue';
                $remarks = 'Fuel gauge indicator lagging.';
            } elseif ($row['id'] === 'daily:fire-truck:91') {
                $readingValue = '123456';
            } elseif ($row['id'] === 'daily:fire-truck:92') {
                $readingValue = '85';
            } elseif (! $isReading) {
                $status = 'Checked';
            }

            return [
                'id' => $row['id'],
                'rowNumber' => $row['rowNumber'],
                'mainLocation' => $row['mainLocation'],
                'location' => $row['location'],
                'equipment' => $row['equipment'],
                'quantity' => $row['quantity'],
                'rowKind' => $row['rowKind'],
                'status' => $status,
                'readingValue' => $readingValue,
                'remarks' => $remarks,
            ];
        }, FrtDailyReference::dailyRows());

        $oneOffChecks = array_map(function (array $row): array {
            $isIssue = $row['id'] === 'one-off:fire-truck:16';

            return [
                'id' => $row['id'],
                'rowNumber' => $row['rowNumber'],
                'mainLocation' => $row['mainLocation'],
                'location' => $row['location'],
                'equipment' => $row['equipment'],
                'condition' => $isIssue ? 'Not Good' : 'Good',
                'remarks' => $isIssue ? 'Siren mute switch sticking.' : '',
            ];
        }, FrtDailyReference::oneOffRows());

        $payload = [
            'incidentType' => 'FRT Daily Inspection',
            'location' => 'FIRE TRUCK',
            'selectedLocation' => 'FIRE TRUCK',
            'mainLocation' => 'FIRE TRUCK',
            'description' => 'FRT daily inspection checked for FIRE TRUCK.',
            'photos' => [],
            'frtInspectedBy' => 'Inspector Truck',
            'frtInspectionDate' => '2026-06-28',
            'frtShift' => 'Day',
            'frtTruckReference' => [
                'plateNo' => 'AJG9555',
                'roadTaxExpiry' => '13/02/2026',
                'insuranceExpiry' => '13/02/2026',
                'puspakomExpiry' => '19/02/2026',
            ],
            'frtDailyRemarks' => 'Truck ready for service.',
            'frtOneOffRemarks' => 'One-off defects logged.',
            'frtDailyChecks' => $dailyChecks,
            'frtOneOffChecks' => $oneOffChecks,
        ];

        if (! $useSnakeCase) {
            return $payload;
        }

        return [
            'incidentType' => $payload['incidentType'],
            'location' => $payload['location'],
            'selectedLocation' => $payload['selectedLocation'],
            'mainLocation' => $payload['mainLocation'],
            'description' => $payload['description'],
            'photos' => $payload['photos'],
            'frt_inspected_by' => $payload['frtInspectedBy'],
            'frt_inspection_date' => $payload['frtInspectionDate'],
            'frt_shift' => $payload['frtShift'],
            'frt_truck_reference' => [
                'plate_no' => 'AJG9555',
                'road_tax_expiry' => '13/02/2026',
                'insurance_expiry' => '13/02/2026',
                'puspakom_expiry' => '19/02/2026',
            ],
            'frt_daily_remarks' => $payload['frtDailyRemarks'],
            'frt_one_off_remarks' => $payload['frtOneOffRemarks'],
            'frt_daily_checks' => array_map(
                fn (array $row): array => [
                    'id' => $row['id'],
                    'row_number' => $row['rowNumber'],
                    'main_location' => $row['mainLocation'],
                    'location' => $row['location'],
                    'equipment' => $row['equipment'],
                    'quantity' => $row['quantity'],
                    'row_kind' => $row['rowKind'],
                    'status' => $row['status'],
                    'reading_value' => $row['readingValue'],
                    'remarks' => $row['remarks'],
                ],
                $dailyChecks
            ),
            'frt_one_off_checks' => array_map(
                fn (array $row): array => [
                    'id' => $row['id'],
                    'row_number' => $row['rowNumber'],
                    'main_location' => $row['mainLocation'],
                    'location' => $row['location'],
                    'equipment' => $row['equipment'],
                    'condition' => $row['condition'],
                    'remarks' => $row['remarks'],
                ],
                $oneOffChecks
            ),
        ];
    }

    private function grantInspectionPermission(User $user): void
    {
        $permission = Permission::query()->firstOrCreate([
            'name' => 'reports.inspection.view',
            'guard_name' => 'web',
        ]);
        $role = Role::query()->firstOrCreate([
            'name' => 'Inspection Guardrail Tester',
            'guard_name' => 'web',
        ]);
        if (! $role->hasPermissionTo($permission)) {
            $role->givePermissionTo($permission);
        }
        $user->assignRole($role);
    }
}
