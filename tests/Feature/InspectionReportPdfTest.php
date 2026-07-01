<?php

namespace Tests\Feature;

use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdfWrapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class InspectionReportPdfTest extends TestCase
{
    use RefreshDatabase;

    public function test_pdf_download_uses_live_timeline_entries_for_signoffs(): void
    {
        $user = User::factory()->create([
            'status' => 'active',
            'name' => 'Inspection Owner',
        ]);
        $this->grantInspectionPermission($user);
        $this->actingAs($user);

        $create = $this->postJson('/api/reports', [
            'display_id' => 'INS-01-29042026',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => [
                'incidentType' => 'Fire Pump House Inspection',
                'location' => 'Pump House A',
                'description' => 'Initial description before review.',
                'timeline' => [
                    [
                        'action' => 'Submitted',
                        'by' => 'Stale Payload User',
                        'at' => '2026-04-29T00:00:00+08:00',
                    ],
                ],
            ],
        ]);
        $create->assertCreated();
        $reportUid = (string) $create->json('data.id');

        $commander = User::factory()->create([
            'status' => 'active',
            'name' => 'Inspection Commander',
        ]);
        $this->grantInspectionPermission($commander, 'Incident Commander');
        $this->actingAs($commander);

        $review = $this->postJson("/api/reports/{$reportUid}/review", [
            'version' => 1,
            'remarks' => 'Reviewed by supervisor',
        ]);
        $review->assertOk();

        $approve = $this->postJson("/api/reports/{$reportUid}/approve", [
            'version' => 2,
            'remarks' => 'Approved by manager',
        ]);
        $approve->assertOk();
        $currentVersion = (int) $approve->json('data.version');

        $this->actingAs($user);

        $capturedRecord = null;
        $document = Mockery::mock(DomPdfWrapper::class);
        $document->shouldReceive('setPaper')->once()->andReturnSelf();
        $document->shouldReceive('setOption')->once()->andReturnSelf();
        $document->shouldReceive('output')->once()->andReturn('%PDF-1.4 mocked');

        Pdf::shouldReceive('loadView')
            ->once()
            ->withArgs(function (string $view, array $data) use (&$capturedRecord): bool {
                $capturedRecord = $data['record'] ?? null;
                return $view === 'pdf.inspection_report';
            })
            ->andReturn($document);

        $response = $this->postJson('/api/reports/inspection/pdf', [
            'report_uid' => $reportUid,
            'version' => $currentVersion,
        ]);

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('.pdf', (string) $response->headers->get('Content-Disposition'));

        $this->assertIsArray($capturedRecord);
        $this->assertSame('Approved', $capturedRecord['status'] ?? null);
        $this->assertIsArray($capturedRecord['timeline'] ?? null);

        $actions = collect($capturedRecord['timeline'])
            ->map(fn ($entry) => strtolower((string) ($entry['action'] ?? '')))
            ->values()
            ->all();

        $this->assertContains('submitted', $actions);
        $this->assertContains('reviewed', $actions);
        $this->assertContains('approved', $actions);
        $this->assertCount(3, $actions);
    }

    public function test_pdf_download_is_scoped_to_owner_for_report_uid_requests(): void
    {
        $owner = User::factory()->create(['status' => 'active']);
        $otherUser = User::factory()->create(['status' => 'active']);
        $this->grantInspectionPermission($owner);
        $this->grantInspectionPermission($otherUser);

        $this->actingAs($owner);
        $create = $this->postJson('/api/reports', [
            'display_id' => 'INS-02-29042026',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => [
                'incidentType' => 'Routine Inspection',
                'location' => 'Main Yard',
                'description' => 'Owner only report',
            ],
        ]);
        $create->assertCreated();
        $reportUid = (string) $create->json('data.id');

        $this->actingAs($otherUser);
        $response = $this->postJson('/api/reports/inspection/pdf', [
            'report_uid' => $reportUid,
        ]);
        $response->assertStatus(404);
    }

    public function test_pdf_download_requires_report_uid(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->grantInspectionPermission($user);
        $this->actingAs($user);

        $response = $this->postJson('/api/reports/inspection/pdf', []);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['report_uid']);
    }

    public function test_pdf_template_renders_required_inspection_fields(): void
    {
        $record = [
            'displayId' => 'INS-03-29042026',
            'status' => 'Reviewed',
            'incidentType' => 'Housekeeping 5S Inspection',
            'location' => 'Warehouse Block A',
            'description' => 'Housekeeping inspection found minor labelling gaps.',
            'submittedBy' => 'Inspector User',
            'submittedAt' => '2026-04-29T09:15:00+08:00',
            'photos' => [
                [
                    'url' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR4nGNgYAAAAAMAASsJTYQAAAAASUVORK5CYII=',
                    'description' => 'Label on aisle rack requires replacement.',
                ],
            ],
            'findings' => [
                [
                    'type' => 'Housekeeping 5S Inspection',
                    'location' => 'Warehouse Block A',
                    'description' => 'One label faded and unreadable.',
                ],
            ],
            'checklist' => [
                [
                    'id' => 'housekeeping-5s-inspection:area-clean',
                    'label' => 'Area clean',
                    'inspectionType' => 'Housekeeping 5S Inspection',
                    'selected' => true,
                    'selectedAt' => '2026-04-29T09:16:00+08:00',
                ],
            ],
            'hydraulicChecks' => [
                [
                    'location' => 'FRT',
                    'equipment' => 'Hydraulic Pump Motor 1',
                    'equipmentDescription' => 'FRT primary rescue pump.',
                    'physicalCondition' => 'OK',
                    'mechanicalCondition' => 'OK',
                    'noLeakage' => 'N/A',
                    'noLeakageRemarks' => 'Leak test skipped because tool was isolated.',
                    'functionTest' => 'Defect',
                    'remarks' => 'Slow response.',
                ],
            ],
            'timeline' => [
                [
                    'action' => 'Submitted',
                    'by' => 'Inspector User',
                    'at' => '2026-04-29T09:15:00+08:00',
                ],
                [
                    'action' => 'Reviewed',
                    'by' => 'Supervisor User',
                    'at' => '2026-04-29T10:10:00+08:00',
                ],
            ],
        ];

        $html = view('pdf.inspection_report', [
            'record' => $record,
        ])->render();

        $expectedText = [
            'INS-03-29042026',
            'Reviewed',
            'Housekeeping 5S Inspection',
            'Warehouse Block A',
            'Housekeeping inspection found minor labelling gaps.',
            'Label on aisle rack requires replacement.',
            'Area clean',
            'Hydraulic Equipment Checks',
            'Hydraulic Pump Motor 1',
            'FRT primary rescue pump.',
            'N/A Reason: Hydraulic Pump Motor 1 - No Leakage',
            'Leak test skipped because tool was isolated.',
            'Slow response.',
            'Inspector User',
            'Supervisor User',
        ];

        foreach ($expectedText as $text) {
            $this->assertStringContainsString($text, $html);
        }
    }

    public function test_pdf_template_renders_er_aux_equipment_section(): void
    {
        $record = [
            'displayId' => 'INS-ERAUX-29042026',
            'status' => 'Submitted',
            'incidentType' => 'ER Aux Equipment Inspection',
            'location' => 'Store',
            'description' => 'ER Aux equipment checked at Store by Inspector One on 2026-06-28.',
            'erAuxInspectedBy' => 'Inspector One',
            'erAuxInspectionDate' => '2026-06-28',
            'erAuxChecks' => [
                [
                    'location' => 'Store',
                    'equipment' => 'Fire Jacket',
                    'quantity' => '15',
                    'condition' => 'OK',
                    'remarks' => '',
                ],
                [
                    'location' => 'Store',
                    'equipment' => 'Chainsaw',
                    'quantity' => '0',
                    'condition' => 'Missing',
                    'remarks' => 'Sent for replacement.',
                ],
            ],
        ];

        $html = view('pdf.inspection_report', [
            'record' => $record,
        ])->render();

        foreach ([
            'ER Aux Equipment Checks',
            'Inspector One',
            '2026-06-28',
            'Fire Jacket',
            'Chainsaw',
            'Missing',
            'Sent for replacement.',
        ] as $text) {
            $this->assertStringContainsString($text, $html);
        }
    }

    public function test_pdf_template_renders_scba_section(): void
    {
        $record = [
            'displayId' => 'INS-SCBA-29042026',
            'status' => 'Submitted',
            'incidentType' => 'SCBA Inspection',
            'location' => 'FRT',
            'description' => 'SCBA checked at FRT by Inspector SCBA on 2026-06-28.',
            'scbaInspectedBy' => 'Inspector SCBA',
            'scbaInspectionDate' => '2026-06-28',
            'scbaBackPlateChecks' => [
                [
                    'location' => 'FRT',
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
                    'location' => 'FRT',
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
                    'remarks' => '',
                ],
            ],
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
                    'remarks' => 'Leak test failed on seal.',
                ],
            ],
        ];

        $html = view('pdf.inspection_report', [
            'record' => $record,
        ])->render();

        foreach ([
            'SCBA Checks',
            'Inspector SCBA',
            '2026-06-28',
            'Back Plate',
            'Cylinder',
            'Face Mask',
            'Sealing',
            'Cleanliness',
            'Harness',
            'MSA',
            '6.8L/08',
            'Composite',
            '300',
            '280',
            'Drager',
            'Not Good',
            'Hose coupling worn.',
            'Leak test failed on seal.',
        ] as $text) {
            $this->assertStringContainsString($text, $html);
        }

        $this->assertTrue(
            strpos($html, 'Back Plate') < strpos($html, 'Cylinder')
            && strpos($html, 'Cylinder') < strpos($html, 'Face Mask')
        );
    }

    public function test_pdf_template_renders_high_angle_section(): void
    {
        $record = [
            'displayId' => 'INS-HA-29042026',
            'status' => 'Submitted',
            'incidentType' => 'High Angle Rescue Equipment Inspection',
            'location' => 'Response Kit #1',
            'description' => 'High Angle rescue equipment checked for Response Kit #1 by Inspector Rope on 2026-06-28.',
            'highAngleInspectedBy' => 'Inspector Rope',
            'highAngleInspectionDate' => '2026-06-28',
            'highAngleChecks' => [
                [
                    'rowNumber' => '1',
                    'mainLocation' => 'Response Kit #1',
                    'location' => 'N/A',
                    'subLocation' => 'N/A',
                    'equipment' => 'Heavy Duty Organizer Bag',
                    'quantity' => '1',
                    'condition' => 'Good',
                    'remarks' => '',
                ],
                [
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
        ];

        $html = view('pdf.inspection_report', [
            'record' => $record,
        ])->render();

        foreach ([
            'High Angle Rescue Equipment Checks',
            'Inspector Rope',
            '2026-06-28',
            'General Kit Items',
            'Main Compartment',
            'Heavy Duty Organizer Bag',
            'Locking Carabiner - CT - Steel - S',
            '10',
            'Not Good',
            'Gate spring is sticking.',
        ] as $text) {
            $this->assertStringContainsString($text, $html);
        }
    }

    public function test_pdf_template_renders_frt_daily_section(): void
    {
        $record = [
            'displayId' => 'INS-FRT-29062026',
            'status' => 'Submitted',
            'incidentType' => 'FRT Daily Inspection',
            'location' => 'FIRE TRUCK',
            'description' => 'FRT Daily inspection checked for FIRE TRUCK on 2026-06-29.',
            'frtInspectedBy' => 'Inspector Truck',
            'frtInspectionDate' => '2026-06-29',
            'frtShift' => 'Day',
            'frtTruckReference' => [
                'plateNo' => 'AJG9555',
                'roadTaxExpiry' => '13/02/2026',
                'insuranceExpiry' => '13/02/2026',
                'puspakomExpiry' => '19/02/2026',
            ],
            'frtDailyRemarks' => 'Truck ready for dispatch.',
            'frtOneOffRemarks' => 'One-off issues tracked.',
            'frtDailyChecks' => [
                [
                    'rowNumber' => '1',
                    'mainLocation' => 'FIRE TRUCK',
                    'location' => 'LOCKER 01',
                    'equipment' => 'FIRE HOSE 2.5"',
                    'quantity' => '6',
                    'rowKind' => 'status',
                    'status' => 'Checked',
                    'remarks' => '',
                ],
                [
                    'rowNumber' => '90',
                    'mainLocation' => 'FIRE TRUCK',
                    'location' => 'FIRE TRUCK',
                    'equipment' => 'OVERALL BODY',
                    'quantity' => 'N/A',
                    'rowKind' => 'status',
                    'status' => 'Issue',
                    'remarks' => 'Panel dent needs repair.',
                ],
                [
                    'rowNumber' => '91',
                    'mainLocation' => 'FIRE TRUCK',
                    'location' => 'FIRE TRUCK',
                    'equipment' => 'MILEAGE (ODOMETER)',
                    'quantity' => '',
                    'rowKind' => 'reading',
                    'readingValue' => '123456',
                    'remarks' => '',
                ],
                [
                    'rowNumber' => '92',
                    'mainLocation' => 'FIRE TRUCK',
                    'location' => 'FIRE TRUCK',
                    'equipment' => 'FUEL LEVEL (%)',
                    'quantity' => '',
                    'rowKind' => 'reading',
                    'readingValue' => '85',
                    'remarks' => '',
                ],
            ],
            'frtOneOffChecks' => [
                [
                    'rowNumber' => '16',
                    'mainLocation' => 'FIRE TRUCK',
                    'location' => 'TRUCK CHECKLIST',
                    'equipment' => 'ELECTRONIC SIREN',
                    'condition' => 'Not Good',
                    'remarks' => 'Mute switch sticking.',
                ],
            ],
        ];

        $html = view('pdf.inspection_report', [
            'record' => $record,
        ])->render();

        foreach ([
            'FRT Daily Inspection',
            'Inspector Truck',
            '2026-06-29',
            'Day',
            'AJG9555',
            'FRT Daily Roster',
            'FRT One Off Checklist',
            'LOCKER 01',
            'TRUCK CHECKLIST',
            'MILEAGE (ODOMETER)',
            '123456',
            'FUEL LEVEL (%)',
            '85',
            'Not Good',
            'Panel dent needs repair.',
            'Mute switch sticking.',
            'Truck ready for dispatch.',
            'One-off issues tracked.',
        ] as $text) {
            $this->assertStringContainsString($text, $html);
        }

        $this->assertTrue(
            strpos($html, 'FRT Daily Roster') < strpos($html, 'FRT One Off Checklist')
        );
    }

    public function test_pdf_template_renders_hse_observation_section(): void
    {
        $record = [
            'displayId' => 'INS-HSE-29062026',
            'status' => 'Submitted',
            'incidentType' => 'Health Safety Environment Inspection',
            'location' => 'Zone A > Dock',
            'description' => 'HSE inspection found unsafe act and environmental issue.',
            'hseInspectedBy' => 'Inspector HSE',
            'hseInspectionDate' => '2026-06-29',
            'hseSelections' => ['unsafeAct', 'environmental'],
            'hseUnsafeActDetails' => 'Worker crossed active barricade.',
            'hseEnvironmentalDetails' => 'Minor oil sheen observed near drain.',
            'hseSeverity' => 'High',
            'hseImmediateAction' => 'Stopped work and placed absorbent pads.',
            'hseCorrectiveAction' => 'Brief contractor team before restart.',
            'hseResponsiblePerson' => 'Area Supervisor',
            'hseTargetDate' => '2026-06-30',
            'hseRemarks' => 'Follow up during next patrol.',
        ];

        $html = view('pdf.inspection_report', [
            'record' => $record,
        ])->render();

        foreach ([
            'HSE Observation',
            'Inspector HSE',
            '2026-06-29',
            'Unsafe Act',
            'Environmental',
            'High',
            'Worker crossed active barricade.',
            'Minor oil sheen observed near drain.',
            'Stopped work and placed absorbent pads.',
            'Brief contractor team before restart.',
            'Area Supervisor',
            '2026-06-30',
            'Follow up during next patrol.',
        ] as $text) {
            $this->assertStringContainsString($text, $html);
        }
    }

    private function grantInspectionPermission(User $user, string $roleName = 'Inspection Pdf Tester'): void
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
