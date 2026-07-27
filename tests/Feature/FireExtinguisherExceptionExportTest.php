<?php

namespace Tests\Feature;

use App\Models\InspectionCheckRow;
use App\Models\InspectionFireExtinguisher;
use App\Models\Report;
use App\Models\ReportMedia;
use App\Models\ReportMediaLink;
use App\Models\User;
use App\Services\InspectionFireExtinguishers\FireExtinguisherExceptionExportBuilder;
use App\Services\InspectionFireExtinguishers\FireExtinguisherExceptionPdfRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Smalot\PdfParser\Parser;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use ZipArchive;

class FireExtinguisherExceptionExportTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-07-15 12:00:00');
        $this->user = User::factory()->create(['status' => 'active']);
        $permission = Permission::query()->firstOrCreate([
            'name' => 'reports.inspection.view',
            'guard_name' => 'web',
        ]);
        $role = Role::query()->firstOrCreate([
            'name' => 'Exception Export Tester',
            'guard_name' => 'web',
        ]);
        $role->givePermissionTo($permission);
        $this->user->assignRole($role);
        $this->actingAs($this->user);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_preview_uses_union_semantics_and_deduplicates_overlap(): void
    {
        $both = $this->extinguisher('Export Zone 901', 'FE-001', '2026-07-01');
        $issueOnly = $this->extinguisher('Export Zone 901', 'FE-002', '2026-12-31');
        $this->extinguisher('Export Zone 901', 'FE-003', '2026-06-30');
        $this->extinguisher('Export Zone 901', 'FE-004', '2026-12-31');
        $this->submittedInspection($both, true);
        $this->submittedInspection($issueOnly, true);

        $response = $this->postJson('/api/inspection/fire-extinguishers/exception-export/preview', [
            'categories' => ['issues', 'expired'],
            'scope' => 'current_filters',
            'filters' => ['zone' => 'Export Zone 901'],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.total', 3)
            ->assertJsonPath('data.issues', 2)
            ->assertJsonPath('data.expired', 2)
            ->assertJsonPath('data.overlap', 1);
    }

    public function test_preview_applies_current_filter_context_without_pagination(): void
    {
        $zoneOne = $this->extinguisher('Zone 1', 'FE-010', '2026-12-31');
        $zoneTwo = $this->extinguisher('Zone 2', 'FE-020', '2026-12-31');
        $this->submittedInspection($zoneOne, true);
        $this->submittedInspection($zoneTwo, true);

        $response = $this->postJson('/api/inspection/fire-extinguishers/exception-export/preview', [
            'categories' => ['issues'],
            'scope' => 'current_filters',
            'filters' => ['zone' => 'Zone 2', 'period' => 'all'],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.appliedFilters.0.label', 'Zone: Zone 2');
    }

    public function test_pdf_and_docx_downloads_return_real_files_with_safe_names(): void
    {
        $row = $this->extinguisher('Zone 1', 'FE-100', '2026-07-01');
        $this->submittedInspection($row, true, true);
        $payload = [
            'categories' => ['issues', 'expired'],
            'scope' => 'all',
        ];

        $pdf = $this->postJson('/api/inspection/fire-extinguishers/exception-export/download', [
            ...$payload,
            'format' => 'pdf',
        ]);
        $pdf->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());
        $this->assertStringContainsString(
            'fire-extinguisher-issues-and-expired-2026-07-15.pdf',
            (string) $pdf->headers->get('content-disposition'),
        );

        $docx = $this->postJson('/api/inspection/fire-extinguishers/exception-export/download', [
            ...$payload,
            'format' => 'docx',
        ]);
        $docx->assertOk()->assertHeader(
            'content-type',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        );
        $temporary = tempnam(sys_get_temp_dir(), 'fe-docx-test-');
        $this->assertNotFalse($temporary);
        file_put_contents($temporary, $docx->getContent());
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($temporary) === true);
        $documentXml = $zip->getFromName('word/document.xml');
        $hasEmbeddedImage = false;
        for ($index = 0; $index < $zip->numFiles; $index++) {
            if (str_starts_with((string) $zip->getNameIndex($index), 'word/media/')) {
                $hasEmbeddedImage = true;
                break;
            }
        }
        $zip->close();
        @unlink($temporary);
        $this->assertIsString($documentXml);
        $this->assertStringContainsString('w:tblHeader', $documentXml);
        $this->assertStringContainsString('w:cantSplit', $documentXml);
        $this->assertStringContainsString('w:keepNext', $documentXml);
        $this->assertStringContainsString('ID Loc No. / Status', $documentXml);
        $this->assertStringContainsString('FE-100', $documentXml);
        $this->assertStringContainsString('Pressure indicator failed', $documentXml);
        $this->assertLessThan(
            strpos($documentXml, 'Pressure indicator failed'),
            strpos($documentXml, 'FE-100'),
        );
        $this->assertLessThan(
            strpos($documentXml, '<w:pict'),
            strpos($documentXml, 'Pressure indicator failed'),
        );
        $this->assertTrue($hasEmbeddedImage);
    }

    public function test_expiry_layout_uses_one_dense_register_instead_of_item_cards(): void
    {
        $data = $this->renderData('expired', [
            $this->renderItem('FE-EXP-001', true, false),
            $this->renderItem('FE-EXP-002', true, false),
        ]);

        $html = View::make('pdf.fire_extinguisher_exception_export', ['data' => $data])->render();

        $this->assertStringContainsString('class="exception-register"', $html);
        $this->assertStringContainsString('Certification validity', $html);
        $this->assertStringContainsString('Days expired', $html);
        $this->assertSame(2, substr_count($html, 'class="exception-register-parent"'));
        $this->assertStringNotContainsString('class="exception-item"', $html);
        $this->assertStringNotContainsString('class="exception-register-detail"', $html);
    }

    public function test_combined_layout_keeps_issue_details_immediately_after_their_parent_row(): void
    {
        $data = $this->renderData('combined', [
            $this->renderItem('FE-EXPIRED', true, false),
            $this->renderItem('FE-BOTH', true, true, 'Pressure indicator failed.'),
            $this->renderItem('FE-ISSUE', false, true, 'Safety seal is missing.'),
        ]);

        $html = View::make('pdf.fire_extinguisher_exception_export', ['data' => $data])->render();
        $bothPosition = strpos($html, 'FE-BOTH');
        $bothFindingPosition = strpos($html, 'Pressure indicator failed.');
        $issueOnlyPosition = strpos($html, 'FE-ISSUE');
        $issueOnlyFindingPosition = strpos($html, 'Safety seal is missing.');

        $this->assertIsInt($bothPosition);
        $this->assertIsInt($bothFindingPosition);
        $this->assertIsInt($issueOnlyPosition);
        $this->assertIsInt($issueOnlyFindingPosition);
        $this->assertLessThan($bothFindingPosition, $bothPosition);
        $this->assertLessThan($issueOnlyPosition, $bothFindingPosition);
        $this->assertLessThan($issueOnlyFindingPosition, $issueOnlyPosition);
        $this->assertSame(3, substr_count($html, '<tr class="exception-register-parent'));
        $this->assertSame(2, substr_count($html, 'class="exception-register-detail"'));
    }

    public function test_combined_layout_chunks_photo_evidence_into_non_splitting_rows(): void
    {
        $item = $this->renderItem('FE-PHOTOS', true, true, 'Five evidence photos attached.');
        $item['defects'][0]['photos'] = array_fill(0, 5, [
            'url' => '',
            'imageUnavailable' => true,
            'description' => 'Evidence image.',
        ]);

        $html = View::make('pdf.fire_extinguisher_exception_export', [
            'data' => $this->renderData('combined', [$item]),
        ])->render();

        $this->assertSame(4, substr_count($html, '<tr class="exception-register-detail'));
        $this->assertSame(3, substr_count($html, 'exception-register-detail--evidence'));
        $this->assertStringContainsString('.exception-register-detail { page-break-inside: avoid; }', $html);
        $this->assertStringContainsString('.exception-register-detail.has-evidence { page-break-after: avoid; }', $html);
    }

    public function test_long_expiry_register_paginates_and_repeats_its_column_header(): void
    {
        $items = [];
        for ($index = 1; $index <= 80; $index++) {
            $items[] = $this->renderItem(sprintf('FE-EXP-%03d', $index), true, false);
        }

        $pdf = app(FireExtinguisherExceptionPdfRenderer::class)->render($this->renderData('expired', $items));
        $pages = (new Parser)->parseContent($pdf)->getPages();

        $this->assertGreaterThan(1, count($pages));
        foreach ($pages as $page) {
            $this->assertStringContainsString('CERTIFICATION', $page->getText());
        }
    }

    public function test_combined_pdf_keeps_each_parent_with_its_first_finding_across_pages(): void
    {
        $items = [];
        for ($index = 1; $index <= 30; $index++) {
            $id = sprintf('FE-GROUP-%03d', $index);
            $items[] = $this->renderItem($id, true, true, 'Finding for '.$id.'.');
        }

        $pdf = app(FireExtinguisherExceptionPdfRenderer::class)->render($this->renderData('combined', $items));
        $pages = array_map(
            fn ($page): string => $page->getText(),
            (new Parser)->parseContent($pdf)->getPages(),
        );

        $this->assertGreaterThan(1, count($pages));
        foreach ($items as $item) {
            $id = $item['idLocNo'];
            $parentPage = collect($pages)->search(fn (string $text): bool => str_contains($text, $id));
            $findingPage = collect($pages)->search(fn (string $text): bool => str_contains($text, 'Finding for '.$id.'.'));
            $this->assertNotFalse($parentPage);
            $this->assertSame($parentPage, $findingPage, 'Parent and finding split for '.$id);
        }
    }

    public function test_report_data_keeps_defect_finding_and_photo_inline(): void
    {
        $row = $this->extinguisher('Zone 3', 'FE-300', '2026-12-31');
        $this->submittedInspection($row, true, true);
        $data = app(FireExtinguisherExceptionExportBuilder::class)->build([
            'categories' => ['issues'],
            'scope' => 'all',
        ], $this->user);

        $this->assertCount(1, $data['items']);
        $this->assertSame(1, $data['summary']['issues']);
        $this->assertSame(0, $data['summary']['expired']);
        $this->assertSame(0, $data['summary']['overlap']);
        $this->assertCount(1, $data['items'][0]['defects']);
        $this->assertSame('Pressure indicator failed.', $data['items'][0]['defects'][0]['remarks']);
        $this->assertNotEmpty($data['items'][0]['defects'][0]['photos'][0]['url']);

        $html = View::make('pdf.fire_extinguisher_exception_export', ['data' => $data])->render();
        $findingPosition = strpos($html, 'Pressure indicator failed.');
        $imagePosition = strpos($html, 'data:image/png;base64,');
        $this->assertIsInt($findingPosition);
        $this->assertIsInt($imagePosition);
        $this->assertLessThan($imagePosition, $findingPosition);
    }

    public function test_exception_pdf_embeds_linked_managed_inspection_photo(): void
    {
        Storage::fake('local');
        config([
            'cache.default' => 'array',
            'report_media.minimum_disk_free_bytes' => 0,
        ]);

        $upload = $this->post('/api/report-media', [
            'file' => UploadedFile::fake()->image('exception-evidence.jpg', 800, 1200),
            'module' => 'inspection',
            'source' => 'upload',
            'upload_id' => (string) Str::uuid(),
        ], ['Accept' => 'application/json'])->assertCreated();

        $row = $this->extinguisher('Managed Photo Zone', 'FE-MANAGED-EXCEPTION', '2026-12-31');
        $this->submittedInspection($row, true);
        $report = Report::query()->where('report_uid', 'report-'.$row->id)->firstOrFail();
        $payload = (array) $report->payload;
        data_set($payload, 'fireExtinguisherChecks.0.operationalConditionPhotos', [[
            'id' => 'managed-exception-photo',
            'mediaId' => (string) $upload->json('data.media_id'),
            'url' => (string) $upload->json('data.url'),
            'thumbnailUrl' => (string) $upload->json('data.thumbnail_url'),
            'fileName' => 'exception-evidence.jpg',
            'mimeType' => (string) $upload->json('data.mime_type'),
            'sizeBytes' => (int) $upload->json('data.size_bytes'),
            'width' => (int) $upload->json('data.width'),
            'height' => (int) $upload->json('data.height'),
            'description' => 'Managed exception evidence photograph.',
        ]]);
        $report->forceFill(['payload' => $payload])->save();

        $media = ReportMedia::query()
            ->where('public_id', (string) $upload->json('data.media_id'))
            ->firstOrFail();
        ReportMediaLink::query()->create([
            'report_media_id' => $media->id,
            'parent_type' => 'report',
            'parent_key' => $report->report_uid,
        ]);
        InspectionCheckRow::query()
            ->where('report_id', $report->id)
            ->update(['has_evidence' => true, 'evidence_count' => 1]);

        $pdf = $this->postJson('/api/inspection/fire-extinguishers/exception-export/download', [
            'categories' => ['issues'],
            'scope' => 'all',
            'format' => 'pdf',
        ])->assertOk()->getContent();

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertStringContainsString('/Subtype /Image', $pdf);
        $text = (new Parser)->parseContent($pdf)->getText();
        $this->assertStringContainsString('FE-MANAGED-EXCEPTION', $text);
        $this->assertStringContainsString('Managed exception evidence photograph.', $text);
    }

    public function test_export_requires_a_supported_category_and_format(): void
    {
        $this->postJson('/api/inspection/fire-extinguishers/exception-export/download', [
            'categories' => [],
            'scope' => 'all',
            'format' => 'xlsx',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['categories', 'format']);
    }

    public function test_export_requires_inspection_report_permission(): void
    {
        $unauthorized = User::factory()->create(['status' => 'active']);
        $this->actingAs($unauthorized);

        $this->postJson('/api/inspection/fire-extinguishers/exception-export/preview', [
            'categories' => ['issues'],
            'scope' => 'all',
        ])->assertForbidden();
    }

    private function extinguisher(string $zone, string $idLocNo, string $validity): InspectionFireExtinguisher
    {
        return InspectionFireExtinguisher::query()->create([
            'zone' => $zone,
            'main_location_name' => 'Workshop',
            'sub_location_name' => 'Bay A',
            'id_loc_no' => $idLocNo,
            'barcode_no' => 'BAR-'.$idLocNo,
            'fe_type' => 'DP 9KG',
            'certification_validity' => $validity,
            'source' => 'custom',
            'created_by' => $this->user->id,
            'is_active' => true,
            'sort_order' => 1,
        ]);
    }

    private function submittedInspection(
        InspectionFireExtinguisher $extinguisher,
        bool $hasDefect,
        bool $includePhoto = false,
    ): void {
        $photo = $includePhoto ? [[
            'url' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9Z2S8AAAAASUVORK5CYII=',
            'description' => 'Pressure gauge close-up.',
        ]] : [];
        $sourceRowId = 'fe:'.$extinguisher->id;
        $report = Report::query()->create([
            'report_uid' => 'report-'.$extinguisher->id,
            'display_id' => 'INS-'.$extinguisher->id,
            'owner_user_id' => $this->user->id,
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => [
                'incidentType' => 'Fire Extinguisher Inspection',
                'fireExtinguisherChecks' => [[
                    'id' => $sourceRowId,
                    'catalogId' => $extinguisher->id,
                    'idLocNo' => $extinguisher->id_loc_no,
                    'operationalCondition' => $hasDefect ? 'Not Good' : 'Good',
                    'operationalConditionRemarks' => $hasDefect ? 'Pressure indicator failed.' : '',
                    'operationalConditionPhotos' => $photo,
                ]],
            ],
            'submitted_at' => now()->subDay(),
        ]);
        InspectionCheckRow::query()->create([
            'report_id' => $report->id,
            'report_uid' => $report->report_uid,
            'display_id' => $report->display_id,
            'owner_user_id' => $this->user->id,
            'created_by_user_id' => $this->user->id,
            'updated_by_user_id' => $this->user->id,
            'submitted_by_user_id' => $this->user->id,
            'inspection_type' => 'Fire Extinguisher Inspection',
            'inspection_type_key' => 'fire-extinguisher-inspection',
            'location' => $extinguisher->main_location_name.' > '.$extinguisher->sub_location_name,
            'main_location' => $extinguisher->main_location_name,
            'sub_location' => $extinguisher->sub_location_name,
            'equipment' => $extinguisher->id_loc_no,
            'equipment_key' => strtolower((string) $extinguisher->id_loc_no),
            'equipment_catalog_id' => $extinguisher->id,
            'equipment_source' => 'custom',
            'check_group' => 'Fire Extinguisher Checks',
            'check_key' => 'operational-condition',
            'check_name' => 'Operational Condition',
            'check_value' => $hasDefect ? 'Not Good' : 'Good',
            'remarks' => $hasDefect ? 'Pressure indicator failed.' : null,
            'has_defect' => $hasDefect,
            'has_evidence' => $includePhoto,
            'evidence_count' => $includePhoto ? 1 : 0,
            'report_status' => 'Submitted',
            'submitted_at' => now()->subDay(),
            'source_payload_key' => 'fireExtinguisherChecks',
            'source_row_id' => $sourceRowId,
            'sort_order' => 1,
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function renderData(string $layoutMode, array $items): array
    {
        $issues = collect($items)->where('isIssue', true)->count();
        $expired = collect($items)->where('isExpired', true)->count();
        $overlap = collect($items)->filter(
            fn (array $item): bool => (bool) $item['isIssue'] && (bool) $item['isExpired'],
        )->count();

        return [
            'title' => 'Fire Extinguisher Exception Report',
            'layoutMode' => $layoutMode,
            'categories' => $layoutMode === 'combined' ? ['issues', 'expired'] : [$layoutMode],
            'generatedAtDisplay' => '15 Jul 2026, 12:00',
            'generatedBy' => 'Jang',
            'asOfDateDisplay' => '15 Jul 2026',
            'appliedFilters' => [],
            'summary' => [
                'total' => count($items),
                'issues' => $issues,
                'expired' => $expired,
                'overlap' => $overlap,
            ],
            'items' => $items,
            'renderMeta' => ['imageCount' => 0],
        ];
    }

    /** @return array<string, mixed> */
    private function renderItem(
        string $idLocNo,
        bool $expired,
        bool $issue,
        string $finding = 'Inspection defect.',
    ): array {
        return [
            'zone' => 'Zone A',
            'location' => 'Fire Station',
            'subLocation' => 'Bay 1',
            'idLocNo' => $idLocNo,
            'feType' => 'DP 9KG',
            'barcodeNo' => 'BAR-'.$idLocNo,
            'certificationValidity' => $expired ? '05 May 2026' : '31 Dec 2026',
            'latestInspectionAt' => '2026-07-14T08:00:00+08:00',
            'inspectedBy' => 'Inspector A',
            'isExpired' => $expired,
            'isIssue' => $issue,
            'daysExpired' => $expired ? 71 : 0,
            'defects' => $issue ? [[
                'label' => 'Operational condition',
                'remarks' => $finding,
                'photos' => [],
            ]] : [],
        ];
    }
}
