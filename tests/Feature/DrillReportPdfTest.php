<?php

namespace Tests\Feature;

use App\Models\Report;
use App\Models\ReportMedia;
use App\Models\ReportMediaLink;
use App\Models\User;
use App\Services\ReportMediaService;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdfWrapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Fixtures\DrillReferenceScenarios;
use Tests\TestCase;

class DrillReportPdfTest extends TestCase
{
    use RefreshDatabase;

    public function test_pdf_download_uses_live_timeline_entries_for_signoffs(): void
    {
        $user = User::factory()->create([
            'status' => 'active',
            'name' => 'Drill Supervisor',
        ]);
        $this->grantDrillPermission($user);
        $this->actingAs($user);
        $reviewer = User::factory()->create([
            'status' => 'active',
            'name' => 'Drill Incident Commander',
        ]);
        $this->grantDrillPermission($reviewer);

        $create = $this->postJson('/api/reports', [
            'display_id' => 'DRL-01-28042026',
            'report_type' => 'drill',
            'status' => 'Submitted',
            'payload' => [
                'incidentType' => 'Fire Drill',
                'location' => 'Workshop',
                'timeline' => [
                    [
                        'action' => 'Submitted',
                        'by' => 'Stale Payload User',
                        'at' => '2026-04-28T00:00:00+08:00',
                    ],
                ],
            ],
        ]);
        $create->assertCreated();
        $reportUid = (string) $create->json('data.id');

        $this->actingAs($reviewer);
        $review = $this->postJson("/api/reports/{$reportUid}/review", [
            'version' => 1,
            'remarks' => 'Reviewed by safety officer',
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

                return $view === 'pdf.drill_report';
            })
            ->andReturn($document);

        $response = $this->postJson('/api/reports/drill/pdf', [
            'report_uid' => $reportUid,
            'version' => $currentVersion,
        ]);
        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');

        $this->assertIsArray($capturedRecord);
        $this->assertSame('Approved', $capturedRecord['status'] ?? null);
        $actions = collect($capturedRecord['timeline'])
            ->map(fn ($entry) => strtolower((string) ($entry['action'] ?? '')))
            ->values()
            ->all();

        $this->assertContains('submitted', $actions);
        $this->assertContains('reviewed', $actions);
        $this->assertContains('approved', $actions);
        $this->assertNotContains('Stale Payload User', array_column($capturedRecord['timeline'], 'by'));
    }

    public function test_pdf_download_is_scoped_to_owner_for_report_uid_requests(): void
    {
        $owner = User::factory()->create(['status' => 'active']);
        $otherUser = User::factory()->create(['status' => 'active']);
        $this->grantDrillPermission($owner);
        $this->grantDrillPermission($otherUser);

        $this->actingAs($owner);
        $create = $this->postJson('/api/reports', [
            'display_id' => 'DRL-02-28042026',
            'report_type' => 'drill',
            'status' => 'Submitted',
            'payload' => [
                'incidentType' => 'Rescue Drill',
                'location' => 'Main plant',
                'details' => 'Owner only drill',
            ],
        ]);
        $create->assertCreated();
        $reportUid = (string) $create->json('data.id');

        $this->actingAs($otherUser);
        $response = $this->postJson('/api/reports/drill/pdf', [
            'report_uid' => $reportUid,
        ]);
        $response->assertStatus(404);
    }

    public function test_pdf_download_requires_report_uid(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->grantDrillPermission($user);
        $this->actingAs($user);

        $response = $this->postJson('/api/reports/drill/pdf', []);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['report_uid']);
    }

    public function test_pdf_download_requires_drill_permission(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->actingAs($user);

        $response = $this->postJson('/api/reports/drill/pdf', [
            'report_uid' => 'any-report',
        ]);
        $response->assertStatus(403);
    }

    public function test_pdf_template_renders_required_drill_fields(): void
    {
        $pixel = 'data:image/png;base64,'.base64_encode(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='));
        $record = [
            'displayId' => 'DRL-03-2842026',
            'status' => 'Approved',
            'schemaVersion' => 2,
            'reportDate' => '2026-04-28',
            'reportTime' => '17:03',
            'reportIssuanceDate' => '2026-04-29',
            'weather' => 'Clear',
            'incidentType' => 'Fire Drill',
            'exerciseCategories' => ['Fire', 'Rescue'],
            'location' => 'Workshop',
            'exerciseTitle' => 'Major workshop response exercise',
            'details' => 'Evacuation drill scenario',
            'exerciseObjectives' => [['text' => 'Validate command readiness']],
            'erpReferences' => [['annexNumber' => 'ERP-13', 'title' => 'ERP Fire']],
            'summary' => 'Outcome token for drill PDF',
            'respondingTeam' => [
                'name' => 'Alpha Team',
                'shift' => 'day',
                'attendance' => [[
                    'name' => 'Commander Token',
                    'role' => 'Station Commander',
                    'exerciseRole' => 'SC',
                    'teamName' => 'VMECC',
                ]],
            ],
            'chronology' => [
                ['time' => '17:08', 'action' => 'Alarm activated'],
            ],
            'postIncidentAnalysis' => [
                'strengths' => ['Strong command token'],
                'resourcesMobilised' => ['Rescue vehicle token'],
                'improvementOpportunities' => ['Radio improvement token'],
                'photos' => [[
                    'url' => $pixel,
                    'description' => 'Photo description token',
                ]],
            ],
            'timeline' => [
                [
                    'action' => 'Submitted',
                    'by' => 'Prep Officer',
                    'at' => '2026-04-28T09:04:00+08:00',
                ],
                [
                    'action' => 'Reviewed',
                    'by' => 'Review Officer',
                    'at' => '2026-04-28T16:45:00+08:00',
                ],
                [
                    'action' => 'Approved',
                    'by' => 'Approve Officer',
                    'at' => '2026-04-28T17:03:00+08:00',
                ],
            ],
        ];

        $html = view('pdf.drill_report', [
            'record' => $record,
        ])->render();

        foreach ([
            'DRL-03-2842026',
            'Approved',
            'Fire Drill',
            'Fire',
            'Rescue',
            'Clear',
            'Workshop',
            '29 Apr 2026',
            'Major workshop response exercise',
            'Evacuation drill scenario',
            'Validate command readiness',
            'ERP-13',
            'ERP Fire',
            'Commander Token',
            'Station Commander',
            'Outcome token for drill PDF',
            '17:08',
            'Alarm activated',
            'Strong command token',
            'Rescue vehicle token',
            'Radio improvement token',
            'Photo description token',
            'Prep Officer',
            'Review Officer',
            'Approve Officer',
            'Station Commander Review',
            'VMM Review',
        ] as $text) {
            $this->assertStringContainsString($text, $html);
        }
        $this->assertStringContainsString('photo-grid', $html);
    }

    #[DataProvider('referenceScenarioProvider')]
    public function test_supplied_reference_scenarios_round_trip_through_persisted_v2_pdf(
        array $payload,
        array $expectedTokens,
    ): void {
        Storage::fake('local');
        config(['report_media.modules.drill.upload_enabled' => true]);

        $owner = User::factory()->create(['status' => 'active']);
        $this->grantDrillPermission($owner);
        $this->actingAs($owner);

        $upload = $this->post('/api/report-media', [
            'file' => UploadedFile::fake()->image('reference-photo.jpg', 800, 600),
            'module' => 'drill',
            'source' => 'upload',
            'upload_id' => (string) Str::uuid(),
            'context_key' => 'drill-reference-coverage',
        ], ['Accept' => 'application/json'])->assertCreated();
        $mediaId = (string) $upload->json('data.media_id');
        $payload['postIncidentAnalysis']['photos'] = [[
            'mediaId' => $mediaId,
            'description' => 'Reference exercise photograph',
        ]];

        $created = $this->postJson('/api/reports', [
            'display_id' => 'DRL-REFERENCE-'.strtoupper(substr(md5($payload['exerciseTitle']), 0, 8)),
            'report_type' => 'drill',
            'status' => 'Submitted',
            'payload' => $payload,
        ])->assertCreated();
        $reportUid = (string) $created->json('data.id');
        $media = ReportMedia::query()->where('public_id', $mediaId)->firstOrFail();
        $this->assertDatabaseHas('report_media_links', [
            'report_media_id' => $media->id,
            'parent_type' => 'report',
            'parent_key' => $reportUid,
        ]);

        $pdfResponse = $this->postJson('/api/reports/drill/pdf', [
            'report_uid' => $reportUid,
            'version' => 1,
        ])->assertOk();
        $this->assertStringStartsWith('%PDF-', $pdfResponse->getContent());
        $this->assertGreaterThan(5000, strlen($pdfResponse->getContent()));

        $report = Report::query()->with('timelineEntries')->where('report_uid', $reportUid)->firstOrFail();
        $record = app(ReportMediaService::class)->hydrateLinkedPayloadForPdf(
            (array) $report->payload,
            'report',
            $reportUid,
            'drill',
        );
        $record['displayId'] = $report->display_id;
        $record['status'] = $report->status;
        $record['version'] = (int) $report->version;
        $record['revision'] = (int) $report->revision;
        $record['timeline'] = $report->timelineEntries->map(fn ($entry): array => [
            'action' => $entry->action,
            'by' => $entry->by_name_snapshot,
            'at' => optional($entry->created_at)->toIso8601String(),
            'remarks' => $entry->remarks,
            'revision' => (int) $entry->revision,
        ])->all();

        $html = view('pdf.drill_report', ['record' => $record])->render();
        foreach (array_merge([
            'Exercise Overview',
            'Exercise Details',
            'Exercise Personnel',
            'Summary of Exercise',
            'Chronology of Drill Events',
            'Post-Exercise Analysis',
            'Photographs',
            'Workflow Sign-Off',
            'Reference exercise photograph',
        ], $expectedTokens) as $token) {
            $this->assertStringContainsString($token, $html);
        }
    }

    public static function referenceScenarioProvider(): array
    {
        return DrillReferenceScenarios::cases();
    }

    public function test_pdf_template_uses_only_current_revision_signoffs(): void
    {
        $html = view('pdf.drill_report', [
            'record' => [
                'displayId' => 'DRL-REVISION-2',
                'revision' => 2,
                'timeline' => [
                    ['revision' => 1, 'action' => 'Submitted', 'by' => 'Original preparer'],
                    ['revision' => 1, 'action' => 'Reviewed', 'by' => 'Stale reviewer'],
                    ['revision' => 1, 'action' => 'Approved', 'by' => 'Stale approver'],
                    ['revision' => 2, 'action' => 'Resubmitted', 'by' => 'Current preparer'],
                    ['revision' => 2, 'action' => 'Reviewed', 'by' => 'Current reviewer'],
                ],
            ],
        ])->render();

        $this->assertStringContainsString('Current preparer', $html);
        $this->assertStringContainsString('Current reviewer', $html);
        $this->assertStringNotContainsString('Stale reviewer', $html);
        $this->assertStringNotContainsString('Stale approver', $html);
    }

    public function test_pdf_hydrates_only_linked_drill_media_and_strips_untrusted_urls(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create(['status' => 'active']);
        $this->grantDrillPermission($owner);
        $linked = $this->createMedia($owner, 'rpm_pdf_linked', 'drill', 'linked');
        $unlinked = $this->createMedia($owner, 'rpm_pdf_unlinked', 'drill', 'unlinked');
        $wrongModule = $this->createMedia($owner, 'rpm_pdf_erco', 'erco', 'wrong-module');
        $report = Report::query()->create([
            'report_uid' => 'drill-pdf-media-scope',
            'display_id' => 'DRL-PDF-MEDIA',
            'owner_user_id' => $owner->id,
            'report_type' => 'drill',
            'status' => 'Submitted',
            'version' => 1,
            'revision' => 1,
            'payload' => [
                'schemaVersion' => 2,
                'postIncidentAnalysis' => [
                    'photos' => [
                        ['mediaId' => $linked->public_id, 'url' => 'https://evil.test/linked.jpg'],
                        ['mediaId' => $unlinked->public_id, 'url' => 'https://evil.test/unlinked.jpg'],
                        ['mediaId' => $wrongModule->public_id, 'url' => 'https://evil.test/wrong.jpg'],
                        ['url' => 'https://evil.test/legacy.jpg'],
                    ],
                ],
            ],
        ]);
        foreach ([$linked, $wrongModule] as $media) {
            ReportMediaLink::query()->create([
                'report_media_id' => $media->id,
                'parent_type' => 'report',
                'parent_key' => $report->report_uid,
            ]);
        }

        $capturedRecord = null;
        $document = Mockery::mock(DomPdfWrapper::class);
        $document->shouldReceive('setPaper')->once()->andReturnSelf();
        $document->shouldReceive('setOption')->once()->andReturnSelf();
        $document->shouldReceive('output')->once()->andReturn('%PDF-1.4 mocked');
        Pdf::shouldReceive('loadView')
            ->once()
            ->withArgs(function (string $view, array $data) use (&$capturedRecord): bool {
                $capturedRecord = $data['record'] ?? null;

                return $view === 'pdf.drill_report';
            })
            ->andReturn($document);

        $this->actingAs($owner)->postJson('/api/reports/drill/pdf', [
            'report_uid' => $report->report_uid,
            'version' => 1,
        ])->assertOk();

        $photos = data_get($capturedRecord, 'postIncidentAnalysis.photos');
        $this->assertStringStartsWith('data:image/jpeg;base64,', $photos[0]['url']);
        $this->assertSame('linked-thumbnail', base64_decode(explode(',', $photos[0]['url'], 2)[1]));
        $this->assertSame('', $photos[1]['url']);
        $this->assertSame('', $photos[2]['url']);
        $this->assertSame('', $photos[3]['url']);
        $this->assertStringNotContainsString('evil.test', json_encode($capturedRecord));
    }

    public function test_actual_pdf_render_handles_ten_photos_and_long_chronology(): void
    {
        $pixel = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';
        $record = [
            'displayId' => 'DRL-PDF-STRESS',
            'status' => 'Submitted',
            'reportDate' => '2026-07-11',
            'reportTime' => '09:00',
            'incidentType' => 'Fire Drill',
            'location' => 'Workshop',
            'details' => str_repeat('Long scenario narrative. ', 100),
            'summary' => str_repeat('Long outcome summary. ', 100),
            'chronology' => array_map(
                fn (int $index): array => [
                    'time' => sprintf('%02d:%02d', 9 + intdiv($index, 60), $index % 60),
                    'action' => 'Chronology event '.$index.' '.str_repeat('with operational detail ', 3),
                ],
                range(0, 249),
            ),
            'postIncidentAnalysis' => [
                'photos' => array_map(
                    fn (int $index): array => [
                        'url' => $pixel,
                        'description' => 'Stress photograph '.$index,
                    ],
                    range(1, 10),
                ),
            ],
            'timeline' => [],
        ];

        $output = Pdf::loadView('pdf.drill_report', ['record' => $record])
            ->setPaper('a4')
            ->setOption(['isRemoteEnabled' => false])
            ->output(['compress' => 1]);

        $this->assertStringStartsWith('%PDF-', $output);
        $this->assertGreaterThan(5000, strlen($output));
    }

    private function createMedia(User $owner, string $publicId, string $module, string $prefix): ReportMedia
    {
        $fullPath = 'report-media/'.$prefix.'.jpg';
        $thumbnailPath = 'report-media/'.$prefix.'-thumb.jpg';
        Storage::disk('local')->put($fullPath, $prefix.'-full');
        Storage::disk('local')->put($thumbnailPath, $prefix.'-thumbnail');

        return ReportMedia::query()->create([
            'public_id' => $publicId,
            'user_id' => $owner->id,
            'module' => $module,
            'disk' => 'local',
            'storage_path' => $fullPath,
            'thumbnail_path' => $thumbnailPath,
            'original_name' => $prefix.'.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => strlen($prefix.'-full'),
            'thumbnail_size_bytes' => strlen($prefix.'-thumbnail'),
            'width' => 100,
            'height' => 100,
            'thumbnail_width' => 50,
            'thumbnail_height' => 50,
        ]);
    }

    private function grantDrillPermission(User $user): void
    {
        $permission = Permission::query()->firstOrCreate([
            'name' => 'reports.drill.view',
            'guard_name' => 'web',
        ]);
        $role = Role::query()->firstOrCreate([
            'name' => 'Incident Commander',
            'guard_name' => 'web',
        ]);
        if (! $role->hasPermissionTo($permission)) {
            $role->givePermissionTo($permission);
        }
        $user->assignRole($role);
    }
}
