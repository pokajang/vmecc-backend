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
            'Inspector User',
            'Supervisor User',
        ];

        foreach ($expectedText as $text) {
            $this->assertStringContainsString($text, $html);
        }
    }

    private function grantInspectionPermission(User $user): void
    {
        $permission = Permission::query()->firstOrCreate([
            'name' => 'reports.inspection.view',
            'guard_name' => 'web',
        ]);
        $role = Role::query()->firstOrCreate([
            'name' => 'Inspection Pdf Tester',
            'guard_name' => 'web',
        ]);
        if (! $role->hasPermissionTo($permission)) {
            $role->givePermissionTo($permission);
        }
        $user->assignRole($role);
    }
}
