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
        $record = [
            'displayId' => 'DRL-03-2842026',
            'status' => 'Approved',
            'reportDate' => '2026-04-28',
            'reportTime' => '17:03',
            'weather' => 'Clear',
            'incidentType' => 'Fire Drill',
            'location' => 'Workshop',
            'details' => 'Evacuation drill scenario',
            'summary' => 'Outcome token for drill PDF',
            'chronology' => [
                ['time' => '17:08', 'action' => 'Alarm activated'],
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
            'Clear',
            'Workshop',
            'Evacuation drill scenario',
            'Outcome token for drill PDF',
            '17:08',
            'Alarm activated',
            'Prep Officer',
            'Review Officer',
            'Approve Officer',
        ] as $text) {
            $this->assertStringContainsString($text, $html);
        }
    }

    private function grantDrillPermission(User $user): void
    {
        $permission = Permission::query()->firstOrCreate([
            'name' => 'reports.drill.view',
            'guard_name' => 'web',
        ]);
        $role = Role::query()->firstOrCreate([
            'name' => 'Drill Pdf Tester',
            'guard_name' => 'web',
        ]);
        if (! $role->hasPermissionTo($permission)) {
            $role->givePermissionTo($permission);
        }
        $user->assignRole($role);
    }
}
