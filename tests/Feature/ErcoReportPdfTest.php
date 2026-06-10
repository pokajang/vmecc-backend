<?php

namespace Tests\Feature;

use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdfWrapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ErcoReportPdfTest extends TestCase
{
    use RefreshDatabase;

    public function test_pdf_download_uses_live_timeline_entries_for_signoffs(): void
    {
        $user = User::factory()->create([
            'status' => 'active',
            'name' => 'Operations Supervisor',
        ]);
        $this->actingAs($user);

        $create = $this->postJson('/api/reports', [
            'display_id' => 'ERCO-02-28042026',
            'report_type' => 'erco',
            'status' => 'Submitted',
            'payload' => [
                'incidentType' => 'Special Assistance',
                'location' => 'Zone 2',
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

        $capturedRecord = null;
        $document = Mockery::mock(DomPdfWrapper::class);
        $document->shouldReceive('setPaper')->once()->andReturnSelf();
        $document->shouldReceive('setOption')->once()->andReturnSelf();
        $document->shouldReceive('output')->once()->andReturn('%PDF-1.4 mocked');

        Pdf::shouldReceive('loadView')
            ->once()
            ->withArgs(function (string $view, array $data) use (&$capturedRecord): bool {
                $capturedRecord = $data['record'] ?? null;
                return $view === 'pdf.erco_report';
            })
            ->andReturn($document);

        $response = $this->postJson('/api/reports/erco/pdf', [
            'report_uid' => $reportUid,
            'version' => $currentVersion,
        ]);
        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');

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

    public function test_pdf_template_maps_reviewed_action_to_checked_by_signoff(): void
    {
        $record = $this->buildComprehensiveRecord();

        $html = view('pdf.erco_report', [
            'record' => $record,
        ])->render();

        $this->assertStringContainsString('Checked By', $html);
        $this->assertStringContainsString('Review Officer', $html);
        $this->assertStringContainsString('Approve Officer', $html);
    }

    public function test_pdf_template_renders_required_erco_fields(): void
    {
        $record = $this->buildComprehensiveRecord();

        $html = view('pdf.erco_report', [
            'record' => $record,
        ])->render();

        $expectedText = [
            'ERCO-02-2842026',
            'Approved',
            'Special Assistance',
            'Clear',
            'Zone 2 | Pier B',
            'Special Assistance incident reported in Zone 2',
            'Summary token for ERCO PDF',
            'Operations Supervisor Team',
            'Shift B',
            'AIC Leader',
            '17:08',
            'Arrived at site',
            'Pump Unit',
            'Strong radio handover',
            'Improve staging area lighting',
            'makan nasi ayam.',
            'Prep Officer',
            'Review Officer',
            'Approve Officer',
        ];

        foreach ($expectedText as $text) {
            $this->assertStringContainsString($text, $html);
        }
    }

    private function buildComprehensiveRecord(): array
    {
        return [
            'displayId' => 'ERCO-02-2842026',
            'status' => 'Approved',
            'incidentDate' => '2026-04-28',
            'incidentTime' => '17:03',
            'weather' => 'Clear',
            'incidentType' => 'Special Assistance',
            'details' => 'Special Assistance incident reported in Zone 2',
            'summary' => 'Summary token for ERCO PDF',
            'location' => ['Zone 2', 'Pier B'],
            'respondingTeam' => [
                'name' => 'Operations Supervisor Team',
                'shift' => 'Shift B',
                'attendance' => [
                    ['name' => 'AIC Leader', 'role' => 'Assistant Incident Commander'],
                    ['name' => 'Responder One', 'role' => 'Medic'],
                ],
            ],
            'chronology' => [
                ['time' => '17:08', 'action' => 'Arrived at site'],
            ],
            'postIncidentAnalysis' => [
                'strengths' => ['Strong radio handover'],
                'resourcesMobilised' => ['Pump Unit'],
                'improvementOpportunities' => ['Improve staging area lighting'],
                'photos' => [
                    [
                        'url' => 'https://example.test/erco-photo.jpg',
                        'description' => 'Figure 1. Makan nasi ayam.',
                    ],
                ],
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
    }
}
