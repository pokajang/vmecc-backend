<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DrillPayloadValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_v2_draft_accepts_incomplete_progress_without_inventing_submit_requirements(): void
    {
        $user = $this->userWithDrillPermission();

        $this->actingAs($user)->postJson('/api/reports/drafts', [
            'report_type' => 'drill',
            'payload' => [
                'schemaVersion' => 2,
                'incidentType' => 'Fire Drill',
                'erpReferences' => [
                    ['id' => 'erp-1', 'annexNumber' => 'ERP-10', 'title' => ''],
                ],
                'chronology' => [
                    ['id' => 'event-1', 'time' => '09:00', 'action' => ''],
                ],
            ],
        ])->assertCreated()
            ->assertJsonPath('data.payload.schemaVersion', 2)
            ->assertJsonPath('data.payload.erpReferences.0.annexNumber', 'ERP-10');
    }

    public function test_complete_v2_final_payload_with_custom_category_is_preserved(): void
    {
        $user = $this->userWithDrillPermission();

        $response = $this->actingAs($user)->postJson('/api/reports', [
            'display_id' => 'DRL-V2-VALID',
            'report_type' => 'drill',
            'status' => 'Submitted',
            'payload' => $this->completePayload(),
        ])->assertCreated();

        $response->assertJsonPath('data.schemaVersion', 2);
        $response->assertJsonPath('data.exerciseCategories.0', 'Fire');
        $response->assertJsonPath('data.exerciseCategories.1', 'Medical Response');
        $response->assertJsonPath('data.respondingTeam.attendance.0.exerciseRole', 'SC');
        $this->assertDatabaseHas('reports', ['display_id' => 'DRL-V2-VALID']);
    }

    public function test_v2_final_payload_requires_the_frontend_submission_fields(): void
    {
        $user = $this->userWithDrillPermission();

        $this->actingAs($user)->postJson('/api/reports', [
            'display_id' => 'DRL-V2-MISSING',
            'report_type' => 'drill',
            'status' => 'Submitted',
            'payload' => ['schemaVersion' => 2],
        ])->assertUnprocessable()->assertJsonValidationErrors([
            'reportDate',
            'reportTime',
            'weather',
            'incidentType',
            'location',
            'details',
            'summary',
            'chronology',
        ]);

        $this->assertDatabaseMissing('reports', ['display_id' => 'DRL-V2-MISSING']);
    }

    public function test_v2_rejects_partial_erp_rows_and_duplicate_exclusive_roles(): void
    {
        $user = $this->userWithDrillPermission();
        $payload = $this->completePayload();
        $payload['erpReferences'] = [
            ['annexNumber' => 'ERP-10', 'title' => ''],
        ];
        $payload['respondingTeam']['attendance'][] = [
            'name' => 'Second commander',
            'exerciseRole' => 'SC',
        ];

        $this->actingAs($user)->postJson('/api/reports', [
            'display_id' => 'DRL-V2-PAIRS',
            'report_type' => 'drill',
            'status' => 'Submitted',
            'payload' => $payload,
        ])->assertUnprocessable()->assertJsonValidationErrors([
            'erpReferences.0',
            'respondingTeam.attendance',
        ]);
    }

    public function test_v2_rejects_future_schema_malformed_dates_remote_urls_and_duplicate_media(): void
    {
        $user = $this->userWithDrillPermission();

        $this->actingAs($user)->postJson('/api/reports', [
            'display_id' => 'DRL-V3-UNSUPPORTED',
            'report_type' => 'drill',
            'status' => 'Submitted',
            'payload' => ['schemaVersion' => 3],
        ])->assertUnprocessable()->assertJsonValidationErrors(['schemaVersion']);

        $payload = $this->completePayload();
        $payload['reportDate'] = '11/07/2026';
        $payload['reportTime'] = '25:15';
        $payload['postIncidentAnalysis']['photos'] = [
            ['mediaId' => 'rpm_duplicate', 'url' => 'https://example.test/untrusted.jpg'],
            ['mediaId' => 'rpm_duplicate', 'url' => '/api/report-media/rpm_duplicate'],
        ];

        $this->actingAs($user)->postJson('/api/reports', [
            'display_id' => 'DRL-V2-INVALID',
            'report_type' => 'drill',
            'status' => 'Submitted',
            'payload' => $payload,
        ])->assertUnprocessable()->assertJsonValidationErrors([
            'reportDate',
            'reportTime',
            'postIncidentAnalysis.photos.0.url',
            'postIncidentAnalysis.photos.1.mediaId',
        ]);
    }

    public function test_v2_rejects_more_than_ten_photos_before_media_linking(): void
    {
        $user = $this->userWithDrillPermission();
        $payload = $this->completePayload();
        $payload['postIncidentAnalysis']['photos'] = array_map(
            fn (int $index): array => [
                'mediaId' => 'rpm_limit_'.$index,
                'url' => '/api/report-media/rpm_limit_'.$index,
            ],
            range(1, 11),
        );

        $this->actingAs($user)->postJson('/api/reports', [
            'display_id' => 'DRL-V2-PHOTO-LIMIT',
            'report_type' => 'drill',
            'status' => 'Submitted',
            'payload' => $payload,
        ])->assertUnprocessable()->assertJsonValidationErrors(['postIncidentAnalysis.photos']);

        $this->assertDatabaseMissing('reports', ['display_id' => 'DRL-V2-PHOTO-LIMIT']);
    }

    public function test_legacy_drill_payload_is_read_compatible_but_not_accepted_for_new_submissions(): void
    {
        $user = $this->userWithDrillPermission();

        $this->actingAs($user)->postJson('/api/reports', [
            'display_id' => 'DRL-LEGACY',
            'report_type' => 'drill',
            'status' => 'Submitted',
            'payload' => [
                'incidentType' => 'Legacy Rescue Drill',
                'location' => 'Workshop',
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors(['schemaVersion']);

        $this->assertDatabaseMissing('reports', ['display_id' => 'DRL-LEGACY']);
    }

    public function test_drill_draft_and_report_writes_require_drill_permission(): void
    {
        $user = User::factory()->create(['status' => 'active']);

        $this->actingAs($user)->postJson('/api/reports/drafts', [
            'report_type' => 'drill',
            'payload' => ['schemaVersion' => 2],
        ])->assertForbidden();

        $this->postJson('/api/reports', [
            'display_id' => 'DRL-FORBIDDEN',
            'report_type' => 'drill',
            'status' => 'Submitted',
            'payload' => $this->completePayload(),
        ])->assertForbidden();

        $this->assertDatabaseCount('report_drafts', 0);
        $this->assertDatabaseCount('reports', 0);
    }

    private function completePayload(): array
    {
        return [
            'schemaVersion' => 2,
            'reportDate' => '2026-07-11',
            'reportTime' => '09:00',
            'reportIssuanceDate' => '2026-07-12',
            'weather' => 'Clear',
            'incidentType' => 'Fire Drill',
            'exerciseCategories' => ['Fire', 'Medical Response'],
            'location' => 'Workshop',
            'exerciseTitle' => 'Workshop major fire exercise',
            'details' => 'A simulated workshop fire required evacuation and rescue response.',
            'exerciseObjectives' => [
                ['text' => 'Test evacuation and command readiness'],
            ],
            'erpReferences' => [
                ['annexNumber' => 'ERP-13', 'title' => 'ERP Fire'],
            ],
            'summary' => 'The exercise was completed and the team returned to readiness.',
            'respondingTeam' => [
                'name' => 'A Team',
                'shift' => 'day',
                'attendance' => [
                    [
                        'name' => 'Exercise Commander',
                        'role' => 'Station Commander',
                        'exerciseRole' => 'SC',
                        'teamName' => 'A Team',
                    ],
                ],
            ],
            'chronology' => [
                ['time' => '09:00', 'action' => 'Exercise started'],
                ['time' => '09:05', 'action' => 'Response team mobilised'],
            ],
            'postIncidentAnalysis' => [
                'strengths' => ['Clear command structure'],
                'resourcesMobilised' => ['Ambulance', 'Rescue equipment'],
                'improvementOpportunities' => ['Improve radio checks'],
                'photos' => [],
            ],
        ];
    }

    private function userWithDrillPermission(): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $permission = Permission::query()->firstOrCreate([
            'name' => 'reports.drill.view',
            'guard_name' => 'web',
        ]);
        $role = Role::query()->firstOrCreate([
            'name' => 'Drill payload test reporter',
            'guard_name' => 'web',
        ]);
        if (! $role->hasPermissionTo($permission)) {
            $role->givePermissionTo($permission);
        }
        $user->assignRole($role);

        return $user;
    }
}
