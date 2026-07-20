<?php

namespace Tests\Feature;

use App\Models\Report;
use App\Models\User;
use App\Services\InspectionReports\InspectionReportPdfRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery;
use Smalot\PdfParser\Parser;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class InspectionReportPdfTest extends TestCase
{
    use RefreshDatabase;

    public function test_shared_evidence_gallery_renders_two_photos_in_one_row_and_filters_invalid_urls(): void
    {
        $validPhoto = fn (string $description): array => [
            'description' => $description,
            'url' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR4nGNgYAAAAAMAASsJTYQAAAAASUVORK5CYII=',
        ];

        $html = view('pdf.inspection-report.partials.evidence-gallery', [
            'evidenceGroups' => [
                [
                    'kind' => 'Defect',
                    'title' => 'Defect Evidence: Fire Jacket',
                    'remarks' => 'Damaged seam.',
                    'photos' => [$validPhoto('Defect photo')],
                ],
                [
                    'kind' => 'Additional',
                    'title' => 'Additional Evidence: Fire Jacket',
                    'remarks' => 'Replacement requested.',
                    'photos' => [
                        $validPhoto('Additional photo'),
                        ['description' => 'Remote photo', 'url' => 'https://example.com/photo.jpg'],
                    ],
                ],
            ],
        ])->render();

        $this->assertSame(1, substr_count($html, '<tr>'));
        $this->assertSame(2, substr_count($html, 'class="evidence-card"'));
        $this->assertStringContainsString('Defect Evidence: Fire Jacket', $html);
        $this->assertStringContainsString('Additional Evidence: Fire Jacket', $html);
        $this->assertStringNotContainsString('https://example.com/photo.jpg', $html);
    }

    public function test_long_er_aux_pdf_starts_the_roster_on_page_one_and_uses_at_most_two_pages(): void
    {
        $checks = [];
        for ($index = 1; $index <= 25; $index++) {
            $checks[] = [
                'equipment' => 'Equipment '.$index,
                'location' => 'Store',
                'quantity' => (string) $index,
                'condition' => $index === 25 ? 'Defect' : 'OK',
                'remarks' => '',
            ];
        }

        $photo = [
            'description' => 'Evidence photo.',
            'url' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR4nGNgYAAAAAMAASsJTYQAAAAASUVORK5CYII=',
        ];
        $checks[24]['defectRemarks'] = 'Damaged during inspection.';
        $checks[24]['defectPhotos'] = [$photo];
        $checks[24]['additionalNotes'] = 'Replacement requested.';
        $checks[24]['photos'] = [$photo];

        $pdf = app(InspectionReportPdfRenderer::class)->render([
            'displayId' => 'INS-ER-AUX-LAYOUT',
            'status' => 'Submitted',
            'incidentType' => 'ER Aux Equipment Inspection',
            'location' => 'Store',
            'submittedBy' => 'Layout Tester',
            'submittedAt' => '2026-07-13T00:56:00+08:00',
            'erAuxInspectedBy' => 'Layout Tester',
            'erAuxInspectionDate' => '2026-07-13',
            'erAuxChecks' => $checks,
            'photos' => [],
        ]);

        $pages = (new Parser)->parseContent($pdf)->getPages();

        $this->assertLessThanOrEqual(2, count($pages));
        $this->assertStringContainsString('ER AUX EQUIPMENT CHECKS', strtoupper($pages[0]->getText()));
        $this->assertStringContainsString('DEFECT EVIDENCE: EQUIPMENT 25', strtoupper(collect($pages)->map->getText()->implode("\n")));
        $this->assertStringContainsString('ADDITIONAL EVIDENCE: EQUIPMENT 25', strtoupper(collect($pages)->map->getText()->implode("\n")));
        $this->assertStringContainsString('Page 1 of '.count($pages), $pages[0]->getText());
        $this->assertStringContainsString('Page '.count($pages).' of '.count($pages), $pages[array_key_last($pages)]->getText());
    }

    public function test_actual_renderer_handles_unicode_long_footer_and_unavailable_report_image(): void
    {
        Log::shouldReceive('info')
            ->once()
            ->withArgs(function (string $message, array $context): bool {
                return $message === 'inspection_report_pdf_rendered'
                    && $context['inspection_type'] === 'general'
                    && $context['page_count'] === 1
                    && $context['image_count'] === 0
                    && $context['unavailable_image_count'] === 1
                    && $context['omitted_image_count'] === 0
                    && ! array_key_exists('display_id', $context)
                    && ! array_key_exists('remarks', $context);
            });
        $pdf = app(InspectionReportPdfRenderer::class)->render([
            'displayId' => 'INS-'.str_repeat('VERY-LONG-IDENTIFIER-', 12),
            'status' => 'Submitted',
            'incidentType' => 'General Inspection',
            'location' => 'Kawasan pemeriksaan',
            'submittedBy' => 'Élodie François',
            'description' => 'Pemeriksaan selesai - façade, tekanan 50%, keadaan selamat.',
            'reportRemarks' => 'Catatan keseluruhan untuk pemeriksaan.',
            'checklist' => [[
                'label' => 'Peralatan diperiksa',
                'inspectionType' => 'General Inspection',
            ]],
            'photos' => [[
                'description' => 'Retained caption for unavailable image.',
                'url' => 'https://example.com/remote-image.jpg',
            ]],
        ]);

        $pages = (new Parser)->parseContent($pdf)->getPages();
        $text = collect($pages)->map->getText()->implode("\n");

        $this->assertCount(1, $pages);
        $this->assertStringContainsString('Élodie François', $text);
        $this->assertStringContainsString('façade', $text);
        $this->assertStringContainsString('Image unavailable', $text);
        $this->assertStringContainsString('Retained caption for unavailable image.', $text);
        $this->assertStringContainsString('Page 1 of 1', $text);
        $this->assertStringContainsString('... | Page 1 of 1', $text);
        $this->assertStringNotContainsString('example.com', $text);
    }

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
        $renderer = Mockery::mock(InspectionReportPdfRenderer::class);
        $renderer->shouldReceive('render')
            ->once()
            ->withArgs(function (array $record) use (&$capturedRecord): bool {
                $capturedRecord = $record;

                return true;
            })
            ->andReturn('%PDF-1.4 mocked');
        $this->app->instance(InspectionReportPdfRenderer::class, $renderer);

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

    public function test_pdf_download_allows_module_viewers_and_rejects_users_without_permission(): void
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
        $response
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('X-Report-Version', '1');
        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $otherUser->id,
            'action' => 'report_pdf_downloaded',
        ]);

        $unauthorizedUser = User::factory()->create(['status' => 'active']);
        $this->actingAs($unauthorizedUser);
        $this->postJson('/api/reports/inspection/pdf', [
            'report_uid' => $reportUid,
        ])->assertForbidden();
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

    public function test_pdf_download_rejects_draft_reports(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->grantInspectionPermission($user);
        $report = Report::query()->create([
            'report_uid' => 'inspection-draft-pdf-test',
            'display_id' => 'INS-DRAFT-PDF',
            'owner_user_id' => $user->id,
            'report_type' => 'inspection',
            'status' => 'Draft',
            'version' => 1,
            'revision' => 1,
            'payload' => [],
        ]);

        $this->actingAs($user)
            ->postJson('/api/reports/inspection/pdf', ['report_uid' => $report->report_uid])
            ->assertStatus(422)
            ->assertJsonPath('code', 'REPORT_PDF_UNAVAILABLE');
    }

    public function test_pdf_download_endpoint_returns_a_real_rendered_pdf(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->grantInspectionPermission($user);
        $this->actingAs($user);

        $create = $this->postJson('/api/reports', [
            'display_id' => 'INS-REAL-PDF-ENDPOINT',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => [
                'incidentType' => 'General Inspection',
                'location' => 'Main Yard',
                'description' => 'Real endpoint renderer verification.',
                'reportRemarks' => 'Endpoint report-level remarks.',
            ],
        ]);
        $create->assertCreated();

        $response = $this->postJson('/api/reports/inspection/pdf', [
            'report_uid' => $create->json('data.id'),
            'version' => $create->json('data.version'),
        ]);

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $text = (new Parser)->parseContent($response->getContent())->getText();
        $this->assertStringContainsString('Real endpoint renderer verification.', $text);
        $this->assertStringContainsString('Endpoint report-level remarks.', $text);
        $this->assertStringContainsString('Page 1 of 1', $text);
    }

    public function test_pdf_template_renders_required_inspection_fields(): void
    {
        $record = [
            'displayId' => 'INS-03-29042026',
            'status' => 'Reviewed',
            'incidentType' => 'Housekeeping 5S Inspection',
            'location' => 'Warehouse Block A',
            'description' => 'Housekeeping inspection found minor labelling gaps.',
            'reportRemarks' => 'Additional report remark for the full warehouse.',
            'submittedBy' => 'Inspector User',
            'submittedByRole' => 'Tactical Response Team',
            'submittedByRoleCode' => 'TRT',
            'submittedAt' => '2026-04-29T09:15:00+08:00',
            'inspectionActor' => [
                'userId' => 10,
                'name' => 'Inspector User',
                'email' => 'inspector@example.test',
                'role' => 'Tactical Response Team',
                'roleCode' => 'TRT',
            ],
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
                [
                    'id' => 'hse-inspection:unsafe-act',
                    'label' => 'Leaked HSE checklist item',
                    'inspectionType' => 'Health Safety Environment Inspection',
                    'selected' => true,
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
                    'meta' => [
                        'actorRole' => 'Tactical Response Team',
                        'actorRoleCode' => 'TRT',
                    ],
                ],
                [
                    'action' => 'Reviewed',
                    'by' => 'Supervisor User',
                    'at' => '2026-04-29T10:10:00+08:00',
                    'meta' => [
                        'actorRole' => 'Assistant Incident Commander',
                        'actorRoleCode' => 'AIC',
                    ],
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
            'Additional report remarks',
            'Additional report remark for the full warehouse.',
            'Label on aisle rack requires replacement.',
            'Area clean',
            'Inspector User',
            'Tactical Response Team (TRT)',
            'Supervisor User',
            'Assistant Incident Commander (AIC)',
        ];

        foreach ($expectedText as $text) {
            $this->assertStringContainsString($text, $html);
        }

        foreach ([
            'Leaked HSE checklist item',
            'Hydraulic Equipment Checks',
            'Hydraulic Pump Motor 1',
            'FRT primary rescue pump.',
            'N/A Reason: Hydraulic Pump Motor 1 - No Leakage',
            'Leak test skipped because tool was isolated.',
            'Slow response.',
        ] as $text) {
            $this->assertStringNotContainsString($text, $html);
        }
    }

    public function test_pdf_template_omits_empty_additional_report_remarks(): void
    {
        $html = view('pdf.inspection_report', [
            'record' => [
                'displayId' => 'INS-NO-REPORT-REMARKS',
                'status' => 'Submitted',
                'incidentType' => 'Routine Inspection',
                'location' => 'Main Yard',
                'description' => 'Routine inspection summary.',
                'reportRemarks' => '',
                'photos' => [],
            ],
        ])->render();

        $this->assertStringContainsString('Routine inspection summary.', $html);
        $this->assertStringNotContainsString('Additional report remarks', $html);
        $this->assertStringNotContainsString('Additional report evidence', $html);
        $this->assertStringNotContainsString('No photos uploaded.', $html);
    }

    public function test_report_level_evidence_follows_item_checks_for_every_inspection_type(): void
    {
        $photo = [
            'description' => 'Whole-report evidence photo.',
            'url' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR4nGNgYAAAAAMAASsJTYQAAAAASUVORK5CYII=',
        ];
        $records = [
            'general' => [
                'incidentType' => 'General Inspection',
                'checklist' => [[
                    'label' => 'General item marker',
                    'inspectionType' => 'General Inspection',
                ]],
                'marker' => 'General item marker',
            ],
            'er-aux' => [
                'incidentType' => 'ER Aux Equipment Inspection',
                'erAuxChecks' => [[
                    'equipment' => 'ER Aux item marker',
                    'location' => 'Store',
                    'condition' => 'OK',
                ]],
                'marker' => 'ER Aux item marker',
            ],
            'fire-extinguisher' => [
                'incidentType' => 'Fire Extinguisher Inspection',
                'fireExtinguisherChecks' => [[
                    'idLocNo' => 'Fire extinguisher item marker',
                    'physicalCondition' => 'Good',
                ]],
                'marker' => 'Fire extinguisher item marker',
            ],
            'hydraulic' => [
                'incidentType' => 'Hydraulic Rescue Tools Inspection',
                'hydraulicChecks' => [[
                    'equipment' => 'Hydraulic item marker',
                    'location' => 'FRT',
                    'physicalCondition' => 'OK',
                ]],
                'marker' => 'Hydraulic item marker',
            ],
            'frt' => [
                'incidentType' => 'FRT Daily Inspection',
                'frtDailyChecks' => [[
                    'rowNumber' => '1',
                    'location' => 'LOCKER 01',
                    'equipment' => 'FRT item marker',
                    'rowKind' => 'status',
                    'status' => 'Checked',
                ]],
                'marker' => 'FRT item marker',
            ],
            'high-angle' => [
                'incidentType' => 'High Angle Rescue Equipment Inspection',
                'highAngleChecks' => [[
                    'rowNumber' => '1',
                    'mainLocation' => 'Response Kit #1',
                    'equipment' => 'High Angle item marker',
                    'condition' => 'Good',
                ]],
                'marker' => 'High Angle item marker',
            ],
            'scba' => [
                'incidentType' => 'SCBA Inspection',
                'scbaBackPlateChecks' => [[
                    'location' => 'FRT',
                    'brand' => 'SCBA item marker',
                    'serialNo' => 'SCBA-01',
                    'backPlateHarnessCondition' => 'Good',
                ]],
                'marker' => 'SCBA item marker',
            ],
            'hse' => [
                'incidentType' => 'Health Safety Environment Inspection',
                'hseSelections' => ['unsafeAct'],
                'hseUnsafeActDetails' => 'HSE item marker',
                'marker' => 'HSE item marker',
            ],
        ];

        foreach ($records as $type => $record) {
            $marker = $record['marker'];
            unset($record['marker']);
            $html = view('pdf.inspection_report', [
                'record' => array_merge($record, [
                    'displayId' => 'INS-EVIDENCE-'.strtoupper($type),
                    'status' => 'Submitted',
                    'location' => 'Test location',
                    'reportRemarks' => 'Whole-report remarks for '.$type.'.',
                    'photos' => [$photo],
                ]),
            ])->render();

            $itemPosition = strpos($html, $marker);
            $evidencePosition = strpos($html, 'Additional report evidence');
            $signoffPosition = strpos($html, 'Workflow Sign-offs');

            $this->assertNotFalse($itemPosition, "Missing {$type} item marker.");
            $this->assertNotFalse($evidencePosition, "Missing {$type} report evidence.");
            $this->assertNotFalse($signoffPosition, "Missing {$type} workflow sign-offs.");
            $this->assertTrue(
                $itemPosition < $evidencePosition && $evidencePosition < $signoffPosition,
                "Report evidence is out of order for {$type}.",
            );
            $this->assertStringContainsString('Whole-report remarks for '.$type.'.', $html);
            $this->assertStringContainsString('Whole-report evidence photo.', $html);
        }
    }

    public function test_pdf_template_does_not_render_sections_from_other_inspection_forms(): void
    {
        $records = [
            [
                'record' => [
                    'displayId' => 'INS-LEAK-GENERAL',
                    'status' => 'Submitted',
                    'incidentType' => 'General Inspection',
                    'location' => 'General Area',
                    'description' => 'General inspection should stay generic.',
                    'checklist' => [
                        ['label' => 'General area checked', 'inspectionType' => 'General Inspection'],
                        ['label' => 'HSE checklist should not leak', 'inspectionType' => 'Health Safety Environment Inspection'],
                    ],
                    'hseSelections' => ['unsafeAct'],
                    'hseUnsafeActDetails' => 'HSE SHOULD NOT LEAK INTO GENERAL',
                    'erAuxChecks' => [
                        ['location' => 'Store', 'equipment' => 'ER AUX SHOULD NOT LEAK TO GENERAL'],
                    ],
                ],
                'expected' => ['General inspection should stay generic.', 'General area checked'],
                'forbidden' => [
                    'HSE Observation',
                    'HSE checklist should not leak',
                    'HSE SHOULD NOT LEAK INTO GENERAL',
                    'ER Aux Equipment Checks',
                    'ER AUX SHOULD NOT LEAK TO GENERAL',
                ],
            ],
            [
                'record' => [
                    'displayId' => 'INS-LEAK-ERAUX',
                    'status' => 'Submitted',
                    'incidentType' => 'ER Aux Equipment Inspection',
                    'location' => 'Store',
                    'description' => 'ER Aux leak guard.',
                    'erAuxChecks' => [
                        ['location' => 'Store', 'equipment' => 'ER Aux Unique Pump', 'condition' => 'OK'],
                    ],
                    'hseSelections' => ['unsafeAct'],
                    'hseUnsafeActDetails' => 'HSE SHOULD NOT LEAK INTO ER AUX',
                    'hseSeverity' => 'Critical',
                ],
                'expected' => ['ER Aux Equipment Checks', 'ER Aux Unique Pump'],
                'forbidden' => ['HSE Observation', 'HSE SHOULD NOT LEAK INTO ER AUX'],
            ],
            [
                'record' => [
                    'displayId' => 'INS-LEAK-HSE',
                    'status' => 'Submitted',
                    'incidentType' => 'Health Safety Environment Inspection',
                    'location' => 'Dock',
                    'description' => 'HSE leak guard.',
                    'hseSelections' => ['unsafeCondition'],
                    'hseUnsafeConditionDetails' => 'HSE Unique Finding',
                    'hseSeverity' => 'High',
                    'erAuxChecks' => [
                        ['location' => 'Store', 'equipment' => 'ER AUX SHOULD NOT LEAK', 'condition' => 'Missing'],
                    ],
                ],
                'expected' => ['HSE Observation', 'HSE Unique Finding'],
                'forbidden' => ['ER Aux Equipment Checks', 'ER AUX SHOULD NOT LEAK'],
            ],
            [
                'record' => [
                    'displayId' => 'INS-LEAK-FE',
                    'status' => 'Submitted',
                    'incidentType' => 'Fire Extinguisher Inspection',
                    'location' => 'Yard',
                    'description' => 'FE leak guard.',
                    'fireExtinguisherChecks' => [
                        [
                            'idLocNo' => 'FE-UNIQUE-01',
                            'barcodeNo' => 'BAR-FE-UNIQUE-01',
                            'physicalCondition' => 'Good',
                        ],
                    ],
                    'hydraulicChecks' => [
                        ['location' => 'FRT', 'equipment' => 'HYD SHOULD NOT LEAK', 'physicalCondition' => 'Defect'],
                    ],
                ],
                'expected' => ['Fire Extinguisher Checks', 'FE-UNIQUE-01'],
                'forbidden' => ['Hydraulic Equipment Checks', 'HYD SHOULD NOT LEAK'],
            ],
            [
                'record' => [
                    'displayId' => 'INS-LEAK-HYD',
                    'status' => 'Submitted',
                    'incidentType' => 'Hydraulic Rescue Tools Inspection',
                    'location' => 'FRT',
                    'description' => 'Hydraulic leak guard.',
                    'hydraulicChecks' => [
                        ['location' => 'FRT', 'equipment' => 'Hydraulic Unique Cutter', 'physicalCondition' => 'OK'],
                    ],
                    'fireExtinguisherChecks' => [
                        ['idLocNo' => 'FE SHOULD NOT LEAK', 'barcodeNo' => 'BAR-LEAK'],
                    ],
                ],
                'expected' => ['Hydraulic Equipment Checks', 'Hydraulic Unique Cutter'],
                'forbidden' => ['Fire Extinguisher Checks', 'FE SHOULD NOT LEAK'],
            ],
            [
                'record' => [
                    'displayId' => 'INS-LEAK-FRT',
                    'status' => 'Submitted',
                    'incidentType' => 'Fire Truck Daily Readiness',
                    'location' => 'FIRE TRUCK',
                    'description' => 'FRT leak guard.',
                    'frtTruckReference' => ['plateNo' => 'FRT-UNIQUE-01'],
                    'frtDailyChecks' => [
                        ['rowNumber' => '1', 'location' => 'LOCKER', 'equipment' => 'FRT Unique Hose', 'status' => 'Checked'],
                    ],
                    'highAngleChecks' => [
                        ['rowNumber' => '1', 'equipment' => 'HIGH ANGLE SHOULD NOT LEAK', 'condition' => 'Good'],
                    ],
                ],
                'expected' => ['Fire Truck Daily Readiness', 'FRT Unique Hose'],
                'forbidden' => ['High Angle Rescue Equipment Checks', 'HIGH ANGLE SHOULD NOT LEAK'],
            ],
            [
                'record' => [
                    'displayId' => 'INS-LEAK-HA',
                    'status' => 'Submitted',
                    'incidentType' => 'High Angle Rescue Equipment Inspection',
                    'location' => 'Response Kit',
                    'description' => 'High Angle leak guard.',
                    'highAngleChecks' => [
                        ['rowNumber' => '1', 'equipment' => 'High Angle Unique Rope', 'condition' => 'Good'],
                    ],
                    'scbaBackPlateChecks' => [
                        ['brand' => 'SCBA SHOULD NOT LEAK', 'serialNo' => 'SCBA-LEAK'],
                    ],
                ],
                'expected' => ['High Angle Rescue Equipment Checks', 'High Angle Unique Rope'],
                'forbidden' => ['SCBA Checks', 'SCBA SHOULD NOT LEAK'],
            ],
            [
                'record' => [
                    'displayId' => 'INS-LEAK-SCBA',
                    'status' => 'Submitted',
                    'incidentType' => 'SCBA Inspection',
                    'location' => 'FRT',
                    'description' => 'SCBA leak guard.',
                    'scbaBackPlateChecks' => [
                        ['brand' => 'SCBA Unique Brand', 'serialNo' => 'SCBA-UNIQUE-01'],
                    ],
                    'frtDailyChecks' => [
                        ['rowNumber' => '1', 'location' => 'LOCKER', 'equipment' => 'FRT SHOULD NOT LEAK'],
                    ],
                ],
                'expected' => ['SCBA Checks', 'SCBA Unique Brand'],
                'forbidden' => ['Fire Truck Daily Readiness', 'FRT SHOULD NOT LEAK'],
            ],
        ];

        foreach ($records as $case) {
            $html = view('pdf.inspection_report', [
                'record' => $case['record'],
            ])->render();

            foreach ($case['expected'] as $text) {
                $this->assertStringContainsString($text, $html, $case['record']['displayId']);
            }
            foreach ($case['forbidden'] as $text) {
                $this->assertStringNotContainsString($text, $html, $case['record']['displayId']);
            }
        }
    }

    public function test_pdf_template_renders_general_and_hse_repeatable_finding_cards(): void
    {
        $pixel = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR4nGNgYAAAAAMAASsJTYQAAAAASUVORK5CYII=';

        $generalHtml = view('pdf.inspection_report', [
            'record' => [
                'displayId' => 'INS-GEN-ISSUES-001',
                'status' => 'Submitted',
                'incidentType' => 'General Inspection',
                'location' => 'Zone 1 > Workshop',
                'description' => 'General inspection completed.',
                'inspectionIssues' => [
                    [
                        'description' => 'Blocked emergency exit.',
                        'actionRequired' => 'Remove stored items immediately.',
                        'photos' => [
                            [
                                'url' => $pixel,
                                'description' => 'Blocked exit finding evidence.',
                            ],
                        ],
                    ],
                    [
                        'description' => '',
                        'actionRequired' => '',
                        'photos' => [],
                    ],
                ],
            ],
        ])->render();

        foreach ([
            'Findings (1)',
            'Finding 1',
            'Finding Photos',
            'Blocked emergency exit.',
            'Remove stored items immediately.',
            'Blocked exit finding evidence.',
        ] as $text) {
            $this->assertStringContainsString($text, $generalHtml);
        }
        $this->assertStringNotContainsString('Issues (1)', $generalHtml);
        $this->assertStringNotContainsString('Issue Photos', $generalHtml);

        $hseHtml = view('pdf.inspection_report', [
            'record' => [
                'displayId' => 'INS-HSE-ISSUES-001',
                'status' => 'Submitted',
                'incidentType' => 'Health Safety Environment Inspection',
                'location' => 'Zone 1 > Dock',
                'description' => 'HSE inspection completed.',
                'hseSelections' => ['unsafeCondition'],
                'hseUnsafeConditionDetails' => 'Guardrail gap observed.',
                'issues' => [
                    [
                        'details' => 'Oil spill near walkway.',
                        'action_required' => 'Barricade and clean area.',
                        'issue_photos' => [
                            [
                                'url' => $pixel,
                                'description' => 'Oil spill finding evidence.',
                            ],
                        ],
                    ],
                ],
            ],
        ])->render();

        foreach ([
            'HSE Observation',
            'Findings (1)',
            'Finding 1',
            'Oil spill near walkway.',
            'Barricade and clean area.',
            'Oil spill finding evidence.',
        ] as $text) {
            $this->assertStringContainsString($text, $hseHtml);
        }
        $this->assertStringNotContainsString('Issues (1)', $hseHtml);
        $this->assertStringNotContainsString('Issue Photos', $hseHtml);

        $highAngleHtml = view('pdf.inspection_report', [
            'record' => [
                'displayId' => 'INS-HA-ISSUES-GUARD',
                'status' => 'Submitted',
                'incidentType' => 'High Angle Rescue Equipment Inspection',
                'location' => 'Response Kit',
                'description' => 'High Angle inspection completed.',
                'issues' => [
                    ['description' => 'This legacy field must not render as General/HSE issue.'],
                ],
            ],
        ])->render();

        $this->assertStringNotContainsString('Findings (1)', $highAngleHtml);
        $this->assertStringNotContainsString('Issues (1)', $highAngleHtml);
        $this->assertStringNotContainsString(
            'This legacy field must not render as General/HSE issue.',
            $highAngleHtml,
        );
    }

    public function test_pdf_template_renders_fire_extinguisher_section_without_other_form_data(): void
    {
        $record = [
            'displayId' => 'INS-FE-29062026',
            'status' => 'Submitted',
            'incidentType' => 'Fire Extinguisher Inspection',
            'location' => 'Smoke Yard > Rack A',
            'description' => 'Fire extinguisher inspection completed for Rack A.',
            'fireExtinguisherInspectedBy' => 'Inspector Fire',
            'fireExtinguisherInspectionDate' => '2026-06-29',
            'fireExtinguisherChecks' => [
                [
                    'mainLocation' => 'Smoke Yard',
                    'subLocation' => 'Rack A',
                    'idLocNo' => 'FE-A-001',
                    'barcodeNo' => 'BAR-FE-A-001',
                    'feType' => 'CO2 5KG',
                    'certificationValidity' => '2026-12-31',
                    'physicalCondition' => 'Not Good',
                    'physicalConditionRemarks' => 'Cylinder body dented.',
                    'physicalConditionPhotos' => [
                        [
                            'url' => 'data:image/png;base64,QUFB',
                            'description' => 'Dented cylinder photo.',
                        ],
                    ],
                    'signageCondition' => 'Good',
                    'boxKeyAvailability' => 'N/A',
                    'boxGlassAvailability' => 'Good',
                    'operationalCondition' => 'Good',
                    'remarks' => 'Replace during next service.',
                ],
                [
                    'mainLocation' => 'Smoke Yard',
                    'subLocation' => 'Rack B',
                    'idLocNo' => 'FE-A-RAW-ONLY',
                    'barcodeNo' => 'BAR-FE-A-RAW-ONLY',
                    'feType' => 'DCP 9KG',
                    'certificationValidityRaw' => 'Raw date pending parse',
                    'physicalCondition' => 'Good',
                    'signageCondition' => 'Good',
                    'boxKeyAvailability' => 'Yes',
                    'boxGlassAvailability' => 'Yes',
                    'operationalCondition' => 'Good',
                ],
            ],
            'hseSelections' => ['environmental'],
            'hseEnvironmentalDetails' => 'HSE SHOULD NOT LEAK INTO FE',
            'erAuxChecks' => [
                ['equipment' => 'ER AUX SHOULD NOT LEAK INTO FE'],
            ],
        ];

        $html = view('pdf.inspection_report', [
            'record' => $record,
        ])->render();

        foreach ([
            'Fire Extinguisher Checks',
            'Inspector Fire',
            '2026-06-29',
            'FE-A-001',
            'BAR-FE-A-001',
            'CO2 5KG',
            '2026-12-31',
            'FE-A-RAW-ONLY',
            'Raw date pending parse',
            'Defect Evidence: FE-A-001 - FE Physical Condition',
            'Cylinder body dented.',
            'Dented cylinder photo.',
            'Replace during next service.',
        ] as $text) {
            $this->assertStringContainsString($text, $html);
        }

        foreach ([
            'HSE Observation',
            'HSE SHOULD NOT LEAK INTO FE',
            'ER Aux Equipment Checks',
            'ER AUX SHOULD NOT LEAK INTO FE',
        ] as $text) {
            $this->assertStringNotContainsString($text, $html);
        }
    }

    public function test_pdf_template_summarizes_and_lists_multiple_inspection_locations(): void
    {
        $html = view('pdf.inspection_report', [
            'record' => [
                'displayId' => 'INS-FE-MULTI-LOCATION',
                'status' => 'Submitted',
                'incidentType' => 'Fire Extinguisher Inspection',
                'location' => 'Stale single location',
                'fireExtinguisherChecks' => [
                    [
                        'zone' => '1',
                        'mainLocation' => 'Manjung Hub',
                        'subLocation' => 'Reception',
                        'idLocNo' => 'FE-RECEPTION',
                    ],
                    [
                        'zone' => '1',
                        'mainLocation' => 'Manjung Hub',
                        'subLocation' => 'Workshop',
                        'idLocNo' => 'FE-WORKSHOP',
                    ],
                ],
            ],
        ])->render();

        $this->assertStringContainsString('Zone 1 &gt; Manjung Hub · 2 locations', $html);
        $this->assertStringContainsString('Inspected Locations (2)', $html);
        $this->assertStringContainsString('Zone 1 &gt; Manjung Hub &gt; Reception', $html);
        $this->assertStringContainsString('Zone 1 &gt; Manjung Hub &gt; Workshop', $html);
        $this->assertStringNotContainsString('Stale single location', $html);
    }

    public function test_pdf_template_renders_hydraulic_section_without_other_form_data(): void
    {
        $record = [
            'displayId' => 'INS-HYD-29062026',
            'status' => 'Submitted',
            'incidentType' => 'Hydraulic Rescue Tools Inspection',
            'location' => 'FRT',
            'description' => 'Hydraulic rescue tools checked at FRT.',
            'hydraulicChecks' => [
                [
                    'location' => 'FRT',
                    'equipment' => 'Hydraulic Pump Motor 1',
                    'equipmentDescription' => 'FRT primary rescue pump.',
                    'equipmentSource' => 'custom',
                    'physicalCondition' => 'OK',
                    'mechanicalCondition' => 'Defect',
                    'mechanicalConditionRemarks' => 'Handle sticks under load.',
                    'mechanicalConditionPhotos' => [
                        [
                            'url' => 'data:image/png;base64,QUFB',
                            'description' => 'Sticky handle photo.',
                        ],
                    ],
                    'noLeakage' => 'N/A',
                    'noLeakageRemarks' => 'Leak test skipped because tool was isolated.',
                    'functionTest' => 'OK',
                    'remarks' => 'Monitor next shift.',
                ],
            ],
            'fireExtinguisherChecks' => [
                ['idLocNo' => 'FE SHOULD NOT LEAK INTO HYD', 'barcodeNo' => 'BAR-HYD-LEAK'],
            ],
            'scbaBackPlateChecks' => [
                ['brand' => 'SCBA SHOULD NOT LEAK INTO HYD', 'serialNo' => 'SCBA-HYD-LEAK'],
            ],
        ];

        $html = view('pdf.inspection_report', [
            'record' => $record,
        ])->render();

        foreach ([
            'Hydraulic Equipment Checks',
            'Hydraulic Pump Motor 1',
            'FRT primary rescue pump.',
            'Custom',
            'Defect Evidence: Hydraulic Pump Motor 1 - Mechanical Condition',
            'Handle sticks under load.',
            'Sticky handle photo.',
            'N/A Reason: Hydraulic Pump Motor 1 - No Leakage',
            'Leak test skipped because tool was isolated.',
            'Monitor next shift.',
        ] as $text) {
            $this->assertStringContainsString($text, $html);
        }

        foreach ([
            'Fire Extinguisher Checks',
            'FE SHOULD NOT LEAK INTO HYD',
            'SCBA Checks',
            'SCBA SHOULD NOT LEAK INTO HYD',
        ] as $text) {
            $this->assertStringNotContainsString($text, $html);
        }
    }

    public function test_pdf_template_renders_er_aux_equipment_section(): void
    {
        $record = [
            'displayId' => 'INS-ERAUX-29042026',
            'status' => 'Submitted',
            'incidentType' => 'ER Aux Equipment Inspection',
            'location' => 'Store',
            'description' => "ER Aux equipment checked at Store by Inspector One on 2026-06-28.\nIssue item(s): 1.\n- Chainsaw (qty 0) - Missing: Sent for replacement.",
            'erAuxInspectedBy' => 'Inspector One',
            'erAuxInspectionDate' => '2026-06-28',
            'checklist' => [
                [
                    'id' => 'er-aux:fire-jacket:ok',
                    'label' => 'Fire Jacket - Qty 15: OK',
                    'inspectionType' => 'ER Aux Equipment Inspection',
                    'selected' => true,
                ],
                [
                    'id' => 'er-aux:chainsaw:missing',
                    'label' => 'Chainsaw - Qty 0: Missing',
                    'inspectionType' => 'ER Aux Equipment Inspection',
                    'selected' => true,
                ],
            ],
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

        foreach ([
            'Inspection Description',
            'Issue item(s): 1.',
            '- Chainsaw (qty 0) - Missing: Sent for replacement.',
            '<div class="card-head">Checklist</div>',
            'Fire Jacket - Qty 15: OK',
            'Chainsaw - Qty 0: Missing',
        ] as $text) {
            $this->assertStringNotContainsString($text, $html);
        }
        $this->assertStringContainsString('Additional Evidence: Chainsaw', $html);
        $this->assertStringContainsString('compact-info-grid', $html);
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
            'scbaCustomSections' => [
                [
                    'title' => 'Regulator',
                    'shortLabel' => 'Regulator',
                    'fields' => [
                        ['key' => 'purgeValve', 'label' => 'Purge Valve', 'kind' => 'status'],
                    ],
                    'rows' => [
                        [
                            'location' => 'FRT',
                            'brand' => 'MSA',
                            'serialNo' => 'R-01',
                            'purgeValve' => 'Not Good',
                            'purgeValveRemarks' => 'Purge valve sticks.',
                            'purgeValvePhotos' => [
                                [
                                    'url' => 'data:image/png;base64,QUFB',
                                    'description' => 'Purge valve photo caption.',
                                ],
                            ],
                            'photos' => [
                                [
                                    'url' => 'data:image/png;base64,QkJC',
                                    'description' => 'General regulator photo.',
                                ],
                            ],
                        ],
                    ],
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
            'Regulator',
            'Purge Valve',
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
            'Purge valve sticks.',
            'Purge valve photo caption.',
            'General regulator photo.',
        ] as $text) {
            $this->assertStringContainsString($text, $html);
        }

        $this->assertTrue(
            strpos($html, 'Back Plate') < strpos($html, 'Cylinder')
            && strpos($html, 'Cylinder') < strpos($html, 'Face Mask')
            && strpos($html, 'Face Mask') < strpos($html, 'Regulator')
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
                    'additionalNotes' => 'Stored in upper pouch.',
                    'additionalPhotos' => [
                        [
                            'id' => 'high-angle-additional-pdf-photo',
                            'fileName' => 'high-angle-additional-pdf-photo.png',
                            'description' => 'Organizer bag storage photo.',
                            'url' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR4nGNgYAAAAAMAASsJTYQAAAAASUVORK5CYII=',
                        ],
                    ],
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
            'Additional Info: Heavy Duty Organizer Bag',
            'General equipment remarks',
            'Stored in upper pouch.',
            'Organizer bag storage photo.',
            'Locking Carabiner - CT - Steel - S',
            '10',
            'Not Good',
            'Gate spring is sticking.',
        ] as $text) {
            $this->assertStringContainsString($text, $html);
        }

        $this->assertStringContainsString('compact-info-grid', $html);
        $this->assertStringContainsString('Issue Evidence: Locking Carabiner - CT - Steel - S', $html);
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
                    'additionalNotes' => 'Washed after drill.',
                    'additionalPhotos' => [
                        [
                            'id' => 'frt-daily-additional-pdf-photo',
                            'fileName' => 'frt-daily-additional-pdf-photo.png',
                            'description' => 'Hose locker additional photo.',
                            'url' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR4nGNgYAAAAAMAASsJTYQAAAAASUVORK5CYII=',
                        ],
                    ],
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
                    'photos' => [
                        [
                            'id' => 'frt-daily-pdf-photo',
                            'fileName' => 'frt-daily-pdf-photo.png',
                            'description' => 'Panel dent evidence.',
                            'url' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR4nGNgYAAAAAMAASsJTYQAAAAASUVORK5CYII=',
                        ],
                    ],
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
                    'additionalNotes' => 'Reading confirmed with driver.',
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
                    'additionalNotes' => 'Retest scheduled after repair.',
                    'additionalPhotos' => [
                        [
                            'id' => 'frt-one-off-additional-pdf-photo',
                            'fileName' => 'frt-one-off-additional-pdf-photo.png',
                            'description' => 'Siren panel additional photo.',
                            'url' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR4nGNgYAAAAAMAASsJTYQAAAAASUVORK5CYII=',
                        ],
                    ],
                    'photos' => [
                        [
                            'id' => 'frt-one-off-pdf-photo',
                            'fileName' => 'frt-one-off-pdf-photo.png',
                            'description' => 'Siren switch evidence.',
                            'url' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR4nGNgYAAAAAMAASsJTYQAAAAASUVORK5CYII=',
                        ],
                    ],
                ],
            ],
        ];

        $html = view('pdf.inspection_report', [
            'record' => $record,
        ])->render();

        foreach ([
            'Fire Truck Daily Readiness',
            'Inspector Truck',
            '2026-06-29',
            'AJG9555',
            'Daily Readiness Roster',
            'One-Off Readiness Checklist',
            'LOCKER 01',
            'TRUCK CHECKLIST',
            'MILEAGE (ODOMETER)',
            '123456',
            'FUEL LEVEL (%)',
            '85',
            'Not Good',
            'Additional Info - Row 1',
            'Washed after drill.',
            'Hose locker additional photo.',
            'Additional Info - Row 91',
            'Reading confirmed with driver.',
            'Panel dent needs repair.',
            'Mute switch sticking.',
            'Additional Info - Row 16',
            'Retest scheduled after repair.',
            'Siren panel additional photo.',
            'Issue Evidence - Row 90',
            'Panel dent evidence.',
            'Issue Evidence - Row 16',
            'Siren switch evidence.',
            'Truck ready for dispatch.',
            'One-off issues tracked.',
        ] as $text) {
            $this->assertStringContainsString($text, $html);
        }
        $this->assertStringContainsString('compact-info-grid', $html);

        $this->assertTrue(
            strpos($html, 'Daily Readiness Roster') < strpos($html, 'One-Off Readiness Checklist')
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

    public function test_pdf_template_renders_lean_hse_v2_without_duplicate_legacy_sections(): void
    {
        $record = [
            'displayId' => 'INS-HSE-V2-14072026',
            'status' => 'Submitted',
            'incidentType' => 'Health Safety Environment Inspection',
            'hsePayloadVersion' => 2,
            'location' => 'Zone A > Dock',
            'selectedLocation' => 'Zone A > Dock',
            'inspectedAt' => '2026-07-14T09:30:00+08:00',
            'hseInspectedBy' => 'Inspector HSE',
            'hseSelections' => ['unsafeCondition'],
            'hseUnsafeConditionDetails' => 'Open edge without protection.',
            'hseImmediateAction' => 'Stopped access and installed a barrier.',
            'hseSeverity' => 'Critical',
            'inspectionIssues' => [[
                'description' => 'Duplicate finding must not render.',
                'actionRequired' => 'Duplicate action must not render.',
            ]],
            'photos' => [[
                'description' => 'Open edge observation.',
                'url' => 'data:image/png;base64,AA==',
            ]],
        ];

        $html = view('pdf.inspection_report', ['record' => $record])->render();

        foreach ([
            'HSE Observation',
            'Observed At',
            'Zone A &gt; Dock',
            'Unsafe Condition',
            'Open edge without protection.',
            'Immediate Corrective Action',
            'Stopped access and installed a barrier.',
            'Observation Photos (1)',
            'Open edge observation.',
        ] as $text) {
            $this->assertStringContainsString($text, $html);
        }
        $this->assertStringNotContainsString('Additional report evidence', $html);
        $this->assertStringNotContainsString('Duplicate finding must not render.', $html);
        $this->assertStringNotContainsString('Critical', $html);
    }

    private function grantInspectionPermission(User $user, string $roleName = 'Inspection Pdf Tester'): void
    {
        $permissions = collect(['reports.inspection.view', 'reports.inspection.conduct'])
            ->map(fn (string $name) => Permission::query()->firstOrCreate([
                'name' => $name,
                'guard_name' => 'web',
            ]));
        $role = Role::query()->firstOrCreate([
            'name' => $roleName,
            'guard_name' => 'web',
        ]);
        $role->givePermissionTo($permissions);
        $user->assignRole($role);
    }
}
