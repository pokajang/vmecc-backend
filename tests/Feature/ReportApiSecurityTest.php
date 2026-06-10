<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportApiSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_report_endpoints(): void
    {
        $this->getJson('/api/reports')->assertStatus(401);
        $this->postJson('/api/reports', [])->assertStatus(401);
    }

    public function test_user_cannot_transition_other_users_report(): void
    {
        $owner = User::factory()->create(['status' => 'active']);
        $intruder = User::factory()->create(['status' => 'active']);

        $this->actingAs($owner);
        $created = $this->postJson('/api/reports', [
            'display_id' => 'ERCO-SEC-001',
            'report_type' => 'erco',
            'status' => 'Submitted',
            'payload' => ['incidentType' => 'Fire', 'location' => 'Zone S'],
        ]);
        $created->assertCreated();
        $reportUid = (string) $created->json('data.id');

        $this->actingAs($intruder);
        $this->postJson("/api/reports/{$reportUid}/review", [
            'version' => 1,
            'remarks' => 'Intruder review attempt',
        ])->assertStatus(404);
    }

    public function test_invalid_transition_is_rejected(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->actingAs($user);

        $created = $this->postJson('/api/reports', [
            'display_id' => 'DRL-SEC-002',
            'report_type' => 'drill',
            'status' => 'Submitted',
            'payload' => ['incidentType' => 'Drill', 'location' => 'Zone D'],
        ]);
        $created->assertCreated();
        $reportUid = (string) $created->json('data.id');

        $approve = $this->postJson("/api/reports/{$reportUid}/approve", [
            'version' => 1,
            'remarks' => 'Invalid direct approve',
        ]);
        $approve->assertStatus(409);
        $approve->assertJsonPath('code', 'REPORT_INVALID_TRANSITION');
    }

    public function test_owner_can_delete_report_regardless_of_status(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->actingAs($user);

        $created = $this->postJson('/api/reports', [
            'display_id' => 'FIT-SEC-003',
            'report_type' => 'fitness-test',
            'status' => 'Submitted',
            'payload' => ['incidentType' => 'Endurance Test', 'location' => 'Zone F'],
        ]);
        $created->assertCreated();
        $reportUid = (string) $created->json('data.id');

        $this->postJson("/api/reports/{$reportUid}/review", [
            'version' => 1,
            'remarks' => 'Reviewed',
        ])->assertOk();

        $this->postJson("/api/reports/{$reportUid}/approve", [
            'version' => 2,
            'remarks' => 'Approved',
        ])->assertOk();

        $delete = $this->deleteJson("/api/reports/{$reportUid}");
        $delete->assertNoContent();
    }
}
