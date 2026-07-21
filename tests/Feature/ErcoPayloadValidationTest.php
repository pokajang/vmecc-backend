<?php

namespace Tests\Feature;

use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ErcoPayloadValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_complete_erco_submission_round_trips_and_replays_idempotently(): void
    {
        $user = $this->reporter();
        $request = [
            'report_uid' => 'erco-validation-1',
            'display_id' => 'ERCO-VALIDATION-001',
            'report_type' => 'erco',
            'status' => 'Submitted',
            'submission_key' => 'erco-validation-submit-1',
            'payload' => $this->validPayload(),
        ];

        $first = $this->actingAs($user)->postJson('/api/reports', $request);
        $first->assertCreated()
            ->assertJsonPath('data.location', 'Zone 1 | Workshop')
            ->assertJsonPath('data.postIncidentAnalysis.strengths.0', 'Prompt mobilisation')
            ->assertJsonPath('data.idempotent_replay', false);

        $this->postJson('/api/reports', $request)
            ->assertOk()
            ->assertJsonPath('data.id', 'erco-validation-1')
            ->assertJsonPath('data.idempotent_replay', true);

        $this->assertSame(1, Report::query()->where('submission_key', 'erco-validation-submit-1')->count());
    }

    public function test_erco_final_submission_rejects_missing_required_sections(): void
    {
        $user = $this->reporter();

        $response = $this->actingAs($user)->postJson('/api/reports', [
            'display_id' => 'ERCO-INVALID-001',
            'report_type' => 'erco',
            'status' => 'Submitted',
            'payload' => [
                'schemaVersion' => 1,
                'incidentType' => 'Fire',
                'location' => '',
            ],
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors([
            'incidentDate',
            'incidentTime',
            'weather',
            'location',
            'details',
            'summary',
            'respondingTeam',
            'chronology',
            'postIncidentAnalysis',
        ]);
        $this->assertDatabaseMissing('reports', ['display_id' => 'ERCO-INVALID-001']);
    }

    public function test_erco_final_submission_requires_incident_evidence_and_rejects_future_dates(): void
    {
        $this->travelTo(now()->setDate(2026, 7, 21)->setTime(10, 30));
        $user = $this->reporter();
        $payload = $this->validPayload();
        $payload['incidentDate'] = now()->addDay()->format('Y-m-d');
        $payload['postIncidentAnalysis']['photos'] = [];

        $this->actingAs($user)->postJson('/api/reports', [
            'display_id' => 'ERCO-EVIDENCE-REQUIRED-001',
            'report_type' => 'erco',
            'status' => 'Submitted',
            'payload' => $payload,
        ])->assertUnprocessable()->assertJsonValidationErrors([
            'incidentDate',
            'postIncidentAnalysis.photos',
        ]);

        $payload = $this->validPayload();
        $payload['incidentDate'] = '2026-07-21';
        $payload['incidentTime'] = '10:31';

        $this->postJson('/api/reports', [
            'display_id' => 'ERCO-FUTURE-TIME-001',
            'report_type' => 'erco',
            'status' => 'Submitted',
            'payload' => $payload,
        ])->assertUnprocessable()->assertJsonValidationErrors(['incidentTime']);
    }

    public function test_erco_draft_accepts_incomplete_progress_but_rejects_malformed_rows(): void
    {
        $user = $this->reporter();

        $this->actingAs($user)->postJson('/api/reports/drafts', [
            'report_type' => 'erco',
            'payload' => [
                'schemaVersion' => 1,
                'incidentDate' => '2026-07-13',
                'incidentType' => 'Fire',
            ],
        ])->assertCreated();

        $this->postJson('/api/reports/drafts', [
            'report_type' => 'erco',
            'create_new' => true,
            'payload' => [
                'schemaVersion' => 1,
                'chronology' => [['time' => 'not-a-time', 'action' => []]],
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors([
            'chronology.0.time',
            'chronology.0.action',
        ]);
    }

    public function test_erco_update_enforces_server_version_and_preserves_single_record_identity(): void
    {
        $user = $this->reporter();
        $created = $this->actingAs($user)->postJson('/api/reports', [
            'report_uid' => 'erco-update-1',
            'display_id' => 'ERCO-UPDATE-001',
            'report_type' => 'erco',
            'status' => 'Submitted',
            'payload' => $this->validPayload(),
        ])->assertCreated();

        $updatedPayload = $this->validPayload();
        $updatedPayload['summary'] = 'Updated ERCO summary.';
        $this->putJson('/api/reports/erco-update-1', [
            'version' => $created->json('data.version'),
            'status' => 'Submitted',
            'payload' => $updatedPayload,
        ])->assertOk()
            ->assertJsonPath('data.id', 'erco-update-1')
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.summary', 'Updated ERCO summary.');

        $this->putJson('/api/reports/erco-update-1', [
            'version' => 1,
            'status' => 'Submitted',
            'payload' => $this->validPayload(),
        ])->assertConflict()->assertJsonPath('code', 'REPORT_VERSION_CONFLICT');

        $this->assertDatabaseCount('reports', 1);
    }

    public function test_legacy_inline_erco_photo_remains_writable_for_existing_record_compatibility(): void
    {
        $user = $this->reporter();
        $payload = $this->validPayload();
        $payload['postIncidentAnalysis']['photos'] = [[
            'id' => 'legacy-photo-1',
            'url' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            'description' => 'Legacy response image.',
        ]];

        $this->actingAs($user)->postJson('/api/reports', [
            'report_uid' => 'erco-legacy-photo-1',
            'display_id' => 'ERCO-LEGACY-PHOTO-001',
            'report_type' => 'erco',
            'status' => 'Submitted',
            'payload' => $payload,
        ])->assertCreated()
            ->assertJsonPath('data.postIncidentAnalysis.photos.0.id', 'legacy-photo-1');
    }

    private function validPayload(): array
    {
        return [
            'schemaVersion' => 1,
            'incidentDate' => '2026-07-13',
            'incidentTime' => '09:00',
            'reportDate' => '2026-07-13',
            'reportTime' => '09:00',
            'weather' => 'Clear',
            'incidentType' => 'Fire',
            'location' => 'Zone 1 | Workshop',
            'details' => 'Emergency response details.',
            'detailsSource' => 'manual',
            'summary' => 'Emergency response summary.',
            'respondingTeam' => [
                'name' => 'Alpha',
                'shift' => 'Day',
                'attendance' => [['memberId' => 'member-1', 'name' => 'Responder One', 'role' => 'TRT']],
            ],
            'chronology' => [['time' => '09:00', 'action' => 'Response started.']],
            'postIncidentAnalysis' => [
                'strengths' => ['Prompt mobilisation'],
                'resourcesMobilised' => ['Fire appliance'],
                'improvementOpportunities' => ['Improve radio checks'],
                'photos' => [[
                    'id' => 'required-photo-1',
                    'url' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
                ]],
            ],
        ];
    }

    private function reporter(): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $permission = Permission::query()->firstOrCreate([
            'name' => 'reports.erco.view',
            'guard_name' => 'web',
        ]);
        $role = Role::query()->firstOrCreate([
            'name' => 'ERCO Payload Reporter',
            'guard_name' => 'web',
        ]);
        $role->givePermissionTo($permission);
        $user->assignRole($role);

        return $user;
    }
}
