<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportApiWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_crud_and_transition_workflow(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->actingAs($user);

        $create = $this->postJson('/api/reports', [
            'display_id' => 'ERCO-01-28042026',
            'report_type' => 'erco',
            'status' => 'Submitted',
            'payload' => [
                'incidentType' => 'Fire',
                'location' => 'Zone 1',
            ],
        ]);
        $create->assertCreated();
        $create->assertJsonPath('data.status', 'Submitted');
        $create->assertJsonPath('data.version', 1);
        $reportUid = (string) $create->json('data.id');

        $get = $this->getJson("/api/reports/{$reportUid}");
        $get->assertOk();
        $get->assertJsonPath('data.id', $reportUid);

        $update = $this->putJson("/api/reports/{$reportUid}", [
            'version' => 1,
            'status' => 'Submitted',
            'payload' => [
                'incidentType' => 'Fire Updated',
                'location' => 'Zone 2',
            ],
        ]);
        $update->assertOk();
        $update->assertJsonPath('data.version', 2);
        $update->assertJsonPath('data.revision', 2);

        $review = $this->postJson("/api/reports/{$reportUid}/review", [
            'version' => 2,
            'remarks' => 'Reviewed by supervisor',
        ]);
        $review->assertOk();
        $review->assertJsonPath('data.status', 'Reviewed');
        $review->assertJsonPath('data.version', 3);

        $approve = $this->postJson("/api/reports/{$reportUid}/approve", [
            'version' => 3,
            'remarks' => 'Approved by manager',
        ]);
        $approve->assertOk();
        $approve->assertJsonPath('data.status', 'Approved');
        $approve->assertJsonPath('data.version', 4);
    }

    public function test_report_reject_requires_remarks_and_version_conflict_is_enforced(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->actingAs($user);

        $create = $this->postJson('/api/reports', [
            'display_id' => 'DRL-01-28042026',
            'report_type' => 'drill',
            'status' => 'Submitted',
            'payload' => [
                'incidentType' => 'Drill',
                'location' => 'Zone A',
            ],
        ]);
        $create->assertCreated();
        $reportUid = (string) $create->json('data.id');

        $this->postJson("/api/reports/{$reportUid}/review", [
            'version' => 1,
            'remarks' => 'Reviewed',
        ])->assertOk();

        $rejectMissingRemarks = $this->postJson("/api/reports/{$reportUid}/reject", [
            'version' => 2,
        ]);
        $rejectMissingRemarks->assertStatus(422);
        $rejectMissingRemarks->assertJsonValidationErrors(['remarks']);

        $reject = $this->postJson("/api/reports/{$reportUid}/reject", [
            'version' => 2,
            'remarks' => 'Need more detail',
        ]);
        $reject->assertOk();
        $reject->assertJsonPath('data.status', 'Rejected');
        $reject->assertJsonPath('data.version', 3);

        $conflict = $this->putJson("/api/reports/{$reportUid}", [
            'version' => 2,
            'status' => 'Submitted',
            'payload' => [
                'incidentType' => 'Stale update',
                'location' => 'Zone X',
            ],
        ]);
        $conflict->assertStatus(409);
        $conflict->assertJsonPath('code', 'REPORT_VERSION_CONFLICT');
    }

    public function test_report_submission_key_replays_same_record_without_duplicate_create(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->actingAs($user);

        $payload = [
            'display_id' => 'FIT-01-28042026',
            'report_type' => 'fitness-test',
            'status' => 'Submitted',
            'submission_key' => 'report-submit-abc123',
            'payload' => [
                'incidentType' => 'Endurance Test',
                'location' => 'Zone T',
            ],
        ];

        $first = $this->postJson('/api/reports', $payload);
        $first->assertCreated();
        $first->assertJsonPath('data.idempotent_replay', false);
        $reportUid = (string) $first->json('data.id');

        $second = $this->postJson('/api/reports', $payload);
        $second->assertOk();
        $second->assertJsonPath('data.id', $reportUid);
        $second->assertJsonPath('data.idempotent_replay', true);

        $this->assertDatabaseCount('reports', 1);
    }
}
