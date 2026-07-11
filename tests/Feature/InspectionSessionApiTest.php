<?php

namespace Tests\Feature;

use App\Models\InspectionExtinguisherOperation;
use App\Models\InspectionExtinguisherResult;
use App\Models\InspectionFireExtinguisher;
use App\Models\InspectionSession;
use App\Models\InspectionSessionLocationProgress;
use App\Models\Report;
use App\Models\ReportMedia;
use App\Models\Roster;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\WorkflowNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class InspectionSessionApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_fire_extinguisher_session_is_reused_for_type_level_context(): void
    {
        $firstUser = $this->inspectionUser('Inspector A');
        $secondUser = $this->inspectionUser('Inspector B');

        $first = $this->actingAs($firstUser)->postJson('/api/inspection/sessions', [
            'inspectionType' => 'Fire Extinguisher Inspection',
        ]);

        $first->assertCreated();
        $sessionUid = $first->json('data.sessionUid');
        $first->assertJsonPath('data.scopeZone', '');
        $first->assertJsonPath('data.scopeMainLocation', '');

        $second = $this->actingAs($secondUser)->postJson('/api/inspection/sessions', [
            'inspectionType' => 'Fire Extinguisher Inspection',
            'zone' => 'Zone 1',
            'mainLocation' => 'Canteen',
        ]);

        $second->assertOk();
        $this->assertFalse($second->json('created'));
        $this->assertSame($sessionUid, $second->json('data.sessionUid'));
        $this->assertSame(1, InspectionSession::query()->count());
    }

    public function test_disabled_v2_scope_does_not_fall_back_to_legacy(): void
    {
        config()->set('inspection.session_scope_v2_enabled', false);
        $user = $this->inspectionUser('V2 Disabled');

        $this->actingAs($user)->postJson('/api/inspection/sessions', [
            'inspectionType' => 'Fire Extinguisher Inspection',
            'scopeVersion' => 'v2',
            'siteKey' => 'manjung',
            'inspectionDate' => '2026-07-11',
            'teamId' => 7,
        ])->assertUnprocessable()->assertJsonValidationErrors('scopeVersion');

        $this->assertSame(0, InspectionSession::query()->count());
    }

    public function test_v2_scope_reuses_only_the_same_explicit_batch_identity(): void
    {
        config()->set('inspection.session_scope_v2_enabled', true);
        $firstUser = $this->inspectionUser('V2 Inspector A');
        $secondUser = $this->inspectionUser('V2 Inspector B');
        $team = Team::query()->create(['name' => 'V2 Alpha', 'status' => 'On Duty']);
        $this->assignTeam($firstUser, $team, true);
        $this->assignTeam($secondUser, $team);
        Roster::query()->create([
            'date' => '2026-07-11',
            'shift' => 'day',
            'team_id' => $team->id,
            'created_by' => $firstUser->id,
        ]);
        $scope = [
            'inspectionType' => 'Fire Extinguisher Inspection',
            'scopeVersion' => 'v2',
            'siteKey' => 'Manjung Site',
            'inspectionDate' => '2026-07-11',
            'batchKey' => 'team-alpha',
        ];

        $first = $this->actingAs($firstUser)->postJson('/api/inspection/sessions', $scope);
        $first->assertCreated()->assertJsonPath('data.scopeVersion', 'v2');
        $first->assertJsonPath('data.permissions.canSubmit', true);
        $first->assertJsonPath('data.scope.shiftKey', 'day');
        $first->assertJsonPath('data.scope.teamId', $team->id);
        $second = $this->actingAs($secondUser)->postJson('/api/inspection/sessions', $scope);
        $second->assertOk()->assertJsonPath('created', false);
        $second->assertJsonPath('data.permissions.canWrite', true);
        $second->assertJsonPath('data.permissions.canSubmit', false);
        $this->assertSame($first->json('data.sessionUid'), $second->json('data.sessionUid'));

        $differentBatch = $this->actingAs($secondUser)->postJson('/api/inspection/sessions', [
            ...$scope,
            'batchKey' => 'team-beta',
        ]);
        $differentBatch->assertCreated();
        $this->assertNotSame($first->json('data.sessionUid'), $differentBatch->json('data.sessionUid'));
        $this->assertDatabaseCount('inspection_session_scope_claims', 2);
    }

    public function test_v2_session_allows_same_team_work_and_rejects_outsiders(): void
    {
        config()->set('inspection.session_scope_v2_enabled', true);
        $starter = $this->inspectionUser('V2 Starter');
        $teammate = $this->inspectionUser('V2 Teammate');
        $outsider = $this->inspectionUser('V2 Outsider');
        $team = Team::query()->create(['name' => 'V2 Operations', 'status' => 'On Duty']);
        $otherTeam = Team::query()->create(['name' => 'V2 Other', 'status' => 'On Duty']);
        $this->assignTeam($starter, $team, true);
        $this->assignTeam($teammate, $team);
        $this->assignTeam($outsider, $otherTeam, true);
        $extinguisher = $this->extinguisher();
        $sessionUid = $this->actingAs($starter)->postJson('/api/inspection/sessions', [
            'inspectionType' => 'Fire Extinguisher Inspection',
            'scopeVersion' => 'v2',
            'inspectionDate' => '2026-07-11',
        ])->assertCreated()->json('data.sessionUid');

        $this->actingAs($teammate)->postJson(
            "/api/inspection/sessions/{$sessionUid}/extinguishers/{$extinguisher->id}/complete",
            ['checkPayload' => $this->checkPayload($extinguisher), 'clientResultId' => 'v2-team-check'],
        )->assertOk();
        $this->actingAs($outsider)->getJson("/api/inspection/sessions/{$sessionUid}")
            ->assertForbidden()
            ->assertJsonPath('code', 'inspection_session_team_forbidden');
        $this->actingAs($outsider)->postJson(
            "/api/inspection/sessions/{$sessionUid}/extinguishers/{$extinguisher->id}/reset",
        )->assertForbidden()->assertJsonPath('code', 'inspection_session_team_forbidden');
    }

    public function test_v2_session_only_starter_or_supervisor_can_submit(): void
    {
        config()->set('inspection.session_scope_v2_enabled', true);
        $starter = $this->inspectionUser('V2 Submit Starter');
        $teammate = $this->inspectionUser('V2 Submit Teammate');
        $supervisor = $this->inspectionUser('V2 Supervisor');
        $team = Team::query()->create(['name' => 'V2 Submit Team', 'status' => 'On Duty']);
        $this->assignTeam($starter, $team, true);
        $this->assignTeam($teammate, $team);
        $this->makeSupervisor($supervisor);
        $extinguisher = $this->extinguisher();
        $sessionUid = $this->actingAs($starter)->postJson('/api/inspection/sessions', [
            'inspectionType' => 'Fire Extinguisher Inspection',
            'scopeVersion' => 'v2',
            'inspectionDate' => '2026-07-11',
        ])->assertCreated()->json('data.sessionUid');
        $this->actingAs($teammate)->postJson(
            "/api/inspection/sessions/{$sessionUid}/extinguishers/{$extinguisher->id}/complete",
            ['checkPayload' => $this->checkPayload($extinguisher), 'clientResultId' => 'v2-submit-check'],
        )->assertOk();

        $this->actingAs($teammate)->postJson("/api/inspection/sessions/{$sessionUid}/submit", [
            'submission_key' => 'v2-teammate-submit',
        ])->assertForbidden()->assertJsonPath('code', 'inspection_session_submit_forbidden');
        $this->actingAs($supervisor)->postJson(
            "/api/inspection/sessions/{$sessionUid}/extinguishers/{$extinguisher->id}/reset",
        )->assertForbidden()->assertJsonPath('code', 'inspection_session_write_forbidden');
        $this->actingAs($supervisor)->getJson("/api/inspection/sessions/{$sessionUid}")
            ->assertOk()
            ->assertJsonPath('data.permissions.canWrite', false)
            ->assertJsonPath('data.permissions.canSubmit', true);
        $this->actingAs($supervisor)->postJson("/api/inspection/sessions/{$sessionUid}/submit", [
            'submission_key' => 'v2-supervisor-submit',
        ])->assertCreated();
        $recovery = $this->actingAs($starter)->postJson('/api/inspection/sessions', [
            'inspectionType' => 'Fire Extinguisher Inspection',
            'scopeVersion' => 'v2',
            'inspectionDate' => '2026-07-11',
        ]);
        $recovery->assertCreated();
        $this->assertNotSame($sessionUid, $recovery->json('data.sessionUid'));
    }

    public function test_one_fire_extinguisher_session_tracks_progress_across_zones_and_areas(): void
    {
        $user = $this->inspectionUser('Inspector A');
        $first = $this->extinguisher([
            'zone' => '1',
            'main_location_name' => 'Global Progress Hub',
            'sub_location_name' => 'Global Reception',
        ]);
        $second = $this->extinguisher([
            'zone' => '2',
            'main_location_name' => 'Global Progress Canteen',
            'sub_location_name' => 'Global Dry Store',
            'id_loc_no' => 'CAN-001',
            'barcode_no' => 'CAN-BAR-001',
        ]);
        $sessionUid = $this->createSession($user);

        foreach ([$first, $second] as $index => $extinguisher) {
            $this->actingAs($user)->postJson(
                "/api/inspection/sessions/{$sessionUid}/extinguishers/{$extinguisher->id}/complete",
                [
                    'checkPayload' => $this->checkPayload($extinguisher),
                    'clientResultId' => "client-global-progress-{$index}",
                ],
            )->assertOk();
        }

        $response = $this->actingAs($user)->getJson("/api/inspection/sessions/{$sessionUid}");

        $response->assertOk();
        $completedAreas = collect($response->json('data.progress.completedLocations'))->pluck('mainLocation');
        $this->assertContains('Global Progress Hub', $completedAreas);
        $this->assertContains('Global Progress Canteen', $completedAreas);
        $this->assertSame(2, $response->json('data.progress.locationsCompleted'));
        $this->assertDatabaseHas('inspection_sessions', [
            'session_uid' => $sessionUid,
            'scope_zone' => '',
            'scope_main_location' => '',
        ]);
    }

    public function test_same_extinguisher_cannot_be_completed_twice_in_same_session_without_recheck(): void
    {
        $firstUser = $this->inspectionUser('Inspector A');
        $secondUser = $this->inspectionUser('Inspector B');
        $extinguisher = $this->extinguisher();
        $sessionUid = $this->createSession($firstUser);

        $this->actingAs($firstUser)->postJson(
            "/api/inspection/sessions/{$sessionUid}/extinguishers/{$extinguisher->id}/complete",
            ['checkPayload' => $this->checkPayload($extinguisher), 'clientResultId' => 'client-a'],
        )->assertOk();

        $conflict = $this->actingAs($secondUser)->postJson(
            "/api/inspection/sessions/{$sessionUid}/extinguishers/{$extinguisher->id}/complete",
            ['checkPayload' => $this->checkPayload($extinguisher), 'clientResultId' => 'client-b'],
        );

        $conflict->assertStatus(409);
        $conflict->assertJsonPath('code', 'inspection_result_completed_by_other_user');
        $conflict->assertJsonPath('data.checkedBy', 'Inspector A');
        $this->assertSame(1, InspectionExtinguisherResult::query()->count());
    }

    public function test_completed_extinguisher_result_requires_all_statuses(): void
    {
        $user = $this->inspectionUser('Inspector A');
        $extinguisher = $this->extinguisher();
        $sessionUid = $this->createSession($user);
        $payload = $this->checkPayload($extinguisher);
        $payload['physicalCondition'] = '';

        $response = $this->actingAs($user)->postJson(
            "/api/inspection/sessions/{$sessionUid}/extinguishers/{$extinguisher->id}/complete",
            ['checkPayload' => $payload, 'clientResultId' => 'client-missing-status'],
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['checkPayload.physicalCondition']);
        $this->assertSame(0, InspectionExtinguisherResult::query()->count());
    }

    public function test_completed_defect_extinguisher_result_requires_remarks_but_photo_is_optional(): void
    {
        $user = $this->inspectionUser('Inspector A');
        $extinguisher = $this->extinguisher();
        $sessionUid = $this->createSession($user);
        $payload = $this->checkPayload($extinguisher);
        $payload['operationalCondition'] = 'Not Good';

        $missingRemarks = $this->actingAs($user)->postJson(
            "/api/inspection/sessions/{$sessionUid}/extinguishers/{$extinguisher->id}/complete",
            ['checkPayload' => $payload, 'clientResultId' => 'client-missing-remarks'],
        );

        $missingRemarks->assertStatus(422);
        $missingRemarks->assertJsonValidationErrors(['checkPayload.operationalConditionRemarks']);

        $payload['operationalConditionRemarks'] = 'Pressure indicator failed.';
        $withoutPhoto = $this->actingAs($user)->postJson(
            "/api/inspection/sessions/{$sessionUid}/extinguishers/{$extinguisher->id}/complete",
            ['checkPayload' => $payload, 'clientResultId' => 'client-optional-photo'],
        );

        $withoutPhoto->assertOk();
        $withoutPhoto->assertJsonPath('data.status', 'completed');
        $withoutPhoto->assertJsonMissingPath('data.checkPayload.operationalConditionPhotos');
        $this->assertSame(1, InspectionExtinguisherResult::query()->count());
    }

    public function test_completed_defect_extinguisher_result_succeeds_with_required_evidence(): void
    {
        $user = $this->inspectionUser('Inspector A');
        $extinguisher = $this->extinguisher();
        $sessionUid = $this->createSession($user);
        $payload = $this->checkPayload($extinguisher);
        $payload['operationalCondition'] = 'Not Good';
        $payload['operationalConditionRemarks'] = 'Pressure indicator failed.';
        $payload['operationalConditionPhotos'] = [['id' => 'photo-1', 'url' => '/photo-1.jpg']];

        $response = $this->actingAs($user)->postJson(
            "/api/inspection/sessions/{$sessionUid}/extinguishers/{$extinguisher->id}/complete",
            ['checkPayload' => $payload, 'clientResultId' => 'client-defect-with-evidence'],
        );

        $response->assertOk();
        $response->assertJsonPath('data.status', 'completed');
        $response->assertJsonPath('data.checkPayload.canonicalAssetKey', 'catalog:'.$extinguisher->id);
    }

    public function test_managed_photo_links_to_session_result_and_submitted_report(): void
    {
        $user = $this->inspectionUser('Inspector Camera');
        $extinguisher = $this->extinguisher();
        $sessionUid = $this->createSession($user);
        $media = $this->managedInspectionPhoto($user, 'rpm_session_camera_photo');
        $payload = $this->checkPayload($extinguisher);
        $payload['operationalCondition'] = 'Not Good';
        $payload['operationalConditionRemarks'] = 'Pressure indicator failed.';
        $payload['operationalConditionPhotos'] = [[
            'id' => 'camera-photo-1',
            'mediaId' => $media->public_id,
            'fileName' => 'camera.jpg',
            'url' => '/api/report-media/'.$media->public_id,
        ]];

        $completed = $this->actingAs($user)->postJson(
            "/api/inspection/sessions/{$sessionUid}/extinguishers/{$extinguisher->id}/complete",
            ['checkPayload' => $payload, 'clientResultId' => 'client-managed-photo'],
        )->assertOk();
        $resultId = (string) $completed->json('data.id');

        $this->assertDatabaseHas('report_media_links', [
            'report_media_id' => $media->id,
            'parent_type' => 'inspection_result',
            'parent_key' => $resultId,
        ]);

        $submitted = $this->actingAs($user)->postJson(
            "/api/inspection/sessions/{$sessionUid}/submit",
            ['display_id' => 'INS-FE-MANAGED-PHOTO'],
        )->assertCreated();

        $this->assertDatabaseHas('report_media_links', [
            'report_media_id' => $media->id,
            'parent_type' => 'report',
            'parent_key' => (string) $submitted->json('data.reportUid'),
        ]);
    }

    public function test_unauthorized_managed_photo_rolls_back_result_and_operation(): void
    {
        $owner = $this->inspectionUser('Photo Owner');
        $user = $this->inspectionUser('Inspector Without Photo Access');
        $extinguisher = $this->extinguisher();
        $sessionUid = $this->createSession($user);
        $media = $this->managedInspectionPhoto($owner, 'rpm_session_unauthorized_photo');
        $payload = $this->checkPayload($extinguisher);
        $payload['physicalConditionPhotos'] = [[
            'id' => 'unauthorized-photo',
            'mediaId' => $media->public_id,
            'url' => '/api/report-media/'.$media->public_id,
        ]];

        $response = $this->actingAs($user)->postJson(
            "/api/inspection/sessions/{$sessionUid}/extinguishers/{$extinguisher->id}/complete",
            [
                'checkPayload' => $payload,
                'operationId' => 'operation-unauthorized-photo',
            ],
        );

        $response->assertUnprocessable();
        $response->assertJsonPath('code', 'inspection_payload_invalid');
        $this->assertDatabaseCount('inspection_extinguisher_results', 0);
        $this->assertDatabaseCount('inspection_extinguisher_operations', 0);
        $this->assertDatabaseCount('report_media_links', 0);
    }

    public function test_closed_session_returns_a_stable_conflict_without_creating_an_operation(): void
    {
        $user = $this->inspectionUser('Inspector Closed Session');
        $extinguisher = $this->extinguisher();
        $sessionUid = $this->createSession($user);
        InspectionSession::query()->where('session_uid', $sessionUid)->update(['status' => 'submitted']);

        $response = $this->actingAs($user)->postJson(
            "/api/inspection/sessions/{$sessionUid}/extinguishers/{$extinguisher->id}/complete",
            [
                'checkPayload' => $this->checkPayload($extinguisher),
                'operationId' => 'operation-closed-session',
            ],
        );

        $response->assertConflict();
        $response->assertJsonPath('code', 'inspection_session_closed');
        $this->assertDatabaseCount('inspection_extinguisher_operations', 0);
    }

    public function test_original_checker_can_update_their_completed_extinguisher_result(): void
    {
        $user = $this->inspectionUser('Inspector A');
        $extinguisher = $this->extinguisher();
        $sessionUid = $this->createSession($user);

        $first = $this->actingAs($user)->postJson(
            "/api/inspection/sessions/{$sessionUid}/extinguishers/{$extinguisher->id}/complete",
            ['checkPayload' => $this->checkPayload($extinguisher), 'clientResultId' => 'client-a'],
        )->assertOk();

        $updatedPayload = $this->checkPayload($extinguisher);
        $updatedPayload['remarks'] = 'Corrected by original checker.';

        $this->actingAs($user)->postJson(
            "/api/inspection/sessions/{$sessionUid}/extinguishers/{$extinguisher->id}/complete",
            [
                'checkPayload' => $updatedPayload,
                'clientResultId' => 'client-a2',
                'baseVersion' => $first->json('data.version'),
            ],
        )->assertOk()->assertJsonPath('data.checkPayload.remarks', 'Corrected by original checker.');

        $this->assertSame(1, InspectionExtinguisherResult::query()->count());
        $this->assertSame(2, InspectionExtinguisherResult::query()->firstOrFail()->version);
    }

    public function test_legacy_client_can_update_with_its_deterministic_id_and_matching_base_version(): void
    {
        $user = $this->inspectionUser('Legacy Inspector');
        $extinguisher = $this->extinguisher();
        $sessionUid = $this->createSession($user);
        $clientResultId = 'legacy-deterministic-result-id';

        $first = $this->actingAs($user)->postJson(
            "/api/inspection/sessions/{$sessionUid}/extinguishers/{$extinguisher->id}/complete",
            [
                'checkPayload' => $this->checkPayload($extinguisher),
                'clientResultId' => $clientResultId,
            ],
        )->assertOk();
        $first->assertJsonPath('data.version', 1);

        $updatedPayload = $this->checkPayload($extinguisher);
        $updatedPayload['remarks'] = 'Correction from the legacy client.';
        $this->actingAs($user)->postJson(
            "/api/inspection/sessions/{$sessionUid}/extinguishers/{$extinguisher->id}/complete",
            [
                'checkPayload' => $updatedPayload,
                'clientResultId' => $clientResultId,
                'baseVersion' => $first->json('data.version'),
            ],
        )->assertOk()->assertJsonPath(
            'data.checkPayload.remarks',
            'Correction from the legacy client.',
        );

        $this->assertSame(2, InspectionExtinguisherResult::query()->firstOrFail()->version);
    }

    public function test_operation_replay_after_a_newer_edit_does_not_overwrite_the_newer_result(): void
    {
        $user = $this->inspectionUser('Inspector Replay');
        $extinguisher = $this->extinguisher();
        $sessionUid = $this->createSession($user);
        $firstPayload = $this->checkPayload($extinguisher);
        $firstPayload['remarks'] = 'First saved value.';

        $first = $this->actingAs($user)->postJson(
            "/api/inspection/sessions/{$sessionUid}/extinguishers/{$extinguisher->id}/complete",
            [
                'checkPayload' => $firstPayload,
                'operationId' => 'operation-replay-a',
                'clientResultId' => 'legacy-replay-a',
            ],
        )->assertOk();
        $first->assertJsonPath('operation.replayed', false);

        $secondPayload = $this->checkPayload($extinguisher);
        $secondPayload['remarks'] = 'Newer corrected value.';
        $this->actingAs($user)->postJson(
            "/api/inspection/sessions/{$sessionUid}/extinguishers/{$extinguisher->id}/complete",
            [
                'checkPayload' => $secondPayload,
                'operationId' => 'operation-replay-b',
                'clientResultId' => 'legacy-replay-b',
                'baseVersion' => 1,
            ],
        )->assertOk()->assertJsonPath('data.version', 2);

        $replay = $this->actingAs($user)->postJson(
            "/api/inspection/sessions/{$sessionUid}/extinguishers/{$extinguisher->id}/complete",
            [
                'checkPayload' => $firstPayload,
                'operationId' => 'operation-replay-a',
                'clientResultId' => 'legacy-replay-a',
            ],
        );

        $replay->assertOk();
        $replay->assertJsonPath('operation.code', 'inspection_operation_replayed');
        $replay->assertJsonPath('operation.replayed', true);
        $this->assertSame(
            'Newer corrected value.',
            InspectionExtinguisherResult::query()->firstOrFail()->check_payload['remarks'],
        );
        $this->assertSame(2, InspectionExtinguisherResult::query()->firstOrFail()->version);
        $this->assertDatabaseCount('inspection_extinguisher_operations', 2);
    }

    public function test_operation_id_cannot_be_reused_for_different_work(): void
    {
        $user = $this->inspectionUser('Inspector Operation Guard');
        $extinguisher = $this->extinguisher();
        $sessionUid = $this->createSession($user);
        $payload = $this->checkPayload($extinguisher);

        $this->actingAs($user)->postJson(
            "/api/inspection/sessions/{$sessionUid}/extinguishers/{$extinguisher->id}/complete",
            ['checkPayload' => $payload, 'operationId' => 'operation-reused'],
        )->assertOk();

        $payload['remarks'] = 'Different content under the same operation ID.';
        $response = $this->actingAs($user)->postJson(
            "/api/inspection/sessions/{$sessionUid}/extinguishers/{$extinguisher->id}/complete",
            ['checkPayload' => $payload, 'operationId' => 'operation-reused'],
        );

        $response->assertConflict();
        $response->assertJsonPath('code', 'inspection_operation_id_reused');
        $this->assertSame(
            '',
            InspectionExtinguisherResult::query()->firstOrFail()->check_payload['remarks'] ?? '',
        );
    }

    public function test_version_conflict_has_a_stable_code_and_is_idempotently_replayed(): void
    {
        $user = $this->inspectionUser('Inspector Version Guard');
        $extinguisher = $this->extinguisher();
        $sessionUid = $this->createSession($user);
        $payload = $this->checkPayload($extinguisher);

        $this->actingAs($user)->postJson(
            "/api/inspection/sessions/{$sessionUid}/extinguishers/{$extinguisher->id}/complete",
            ['checkPayload' => $payload, 'operationId' => 'operation-version-base'],
        )->assertOk();

        $request = [
            'checkPayload' => array_merge($payload, ['remarks' => 'Stale update.']),
            'operationId' => 'operation-version-stale',
            'baseVersion' => 99,
        ];
        $firstConflict = $this->actingAs($user)->postJson(
            "/api/inspection/sessions/{$sessionUid}/extinguishers/{$extinguisher->id}/complete",
            $request,
        );
        $firstConflict->assertConflict();
        $firstConflict->assertJsonPath('code', 'inspection_result_version_conflict');

        $replayedConflict = $this->actingAs($user)->postJson(
            "/api/inspection/sessions/{$sessionUid}/extinguishers/{$extinguisher->id}/complete",
            $request,
        );
        $replayedConflict->assertConflict();
        $replayedConflict->assertJsonPath('code', 'inspection_result_version_conflict');
        $this->assertSame(1, InspectionExtinguisherResult::query()->firstOrFail()->version);
    }

    public function test_reset_operation_replay_does_not_reset_a_later_completion(): void
    {
        $user = $this->inspectionUser('Inspector Reset Replay');
        $extinguisher = $this->extinguisher();
        $sessionUid = $this->createSession($user);
        $payload = $this->checkPayload($extinguisher);

        $this->actingAs($user)->postJson(
            "/api/inspection/sessions/{$sessionUid}/extinguishers/{$extinguisher->id}/complete",
            ['checkPayload' => $payload, 'operationId' => 'operation-before-reset'],
        )->assertOk();

        $resetPayload = $payload;
        foreach (['physicalCondition', 'signageCondition', 'boxKeyAvailability', 'boxGlassAvailability', 'operationalCondition'] as $field) {
            $resetPayload[$field] = '';
        }
        $resetRequest = [
            'checkPayload' => $resetPayload,
            'operationId' => 'operation-reset-replay',
            'baseVersion' => 1,
        ];
        $this->actingAs($user)->postJson(
            "/api/inspection/sessions/{$sessionUid}/extinguishers/{$extinguisher->id}/reset",
            $resetRequest,
        )->assertOk()->assertJsonPath('data.status', 'in_progress');

        $this->actingAs($user)->postJson(
            "/api/inspection/sessions/{$sessionUid}/extinguishers/{$extinguisher->id}/complete",
            [
                'checkPayload' => array_merge($payload, ['remarks' => 'Completed after reset.']),
                'operationId' => 'operation-after-reset',
                'baseVersion' => 2,
            ],
        )->assertOk()->assertJsonPath('data.version', 3);

        $replay = $this->actingAs($user)->postJson(
            "/api/inspection/sessions/{$sessionUid}/extinguishers/{$extinguisher->id}/reset",
            $resetRequest,
        );
        $replay->assertOk();
        $replay->assertJsonPath('operation.replayed', true);
        $this->assertSame('completed', InspectionExtinguisherResult::query()->firstOrFail()->status);
        $this->assertSame(3, InspectionExtinguisherResult::query()->firstOrFail()->version);
    }

    public function test_same_extinguisher_can_be_completed_in_separate_sessions(): void
    {
        $user = $this->inspectionUser();
        $extinguisher = $this->extinguisher();
        $firstSessionUid = $this->createSession($user);
        $secondSessionUid = $this->actingAs($user)->postJson('/api/inspection/sessions', [
            'inspectionType' => 'Fire Extinguisher Inspection',
            'zone' => '1',
            'mainLocation' => 'Manjung Hub',
            'forceNew' => true,
        ])->assertCreated()->json('data.sessionUid');

        $this->actingAs($user)->postJson(
            "/api/inspection/sessions/{$firstSessionUid}/extinguishers/{$extinguisher->id}/complete",
            ['checkPayload' => $this->checkPayload($extinguisher), 'clientResultId' => 'client-a'],
        )->assertOk();

        $this->actingAs($user)->postJson(
            "/api/inspection/sessions/{$secondSessionUid}/extinguishers/{$extinguisher->id}/complete",
            ['checkPayload' => $this->checkPayload($extinguisher), 'clientResultId' => 'client-b'],
        )->assertOk();

        $this->assertSame(2, InspectionExtinguisherResult::query()->count());
    }

    public function test_location_completion_is_persisted_in_session_progress(): void
    {
        $user = $this->inspectionUser('Inspector A');
        $scope = ['zone' => 'Test Zone', 'mainLocation' => 'Session Progress Hub'];
        $reception = $this->extinguisher([
            'zone' => $scope['zone'],
            'main_location_name' => $scope['mainLocation'],
            'sub_location_name' => 'Reception',
        ]);
        $auditorium = $this->extinguisher([
            'zone' => $scope['zone'],
            'main_location_name' => $scope['mainLocation'],
            'sub_location_name' => 'Infront Auditorium',
            'id_loc_no' => 'ADO-002',
            'barcode_no' => 'BAR-002',
        ]);
        $sessionUid = $this->createSession($user, $scope);

        $this->actingAs($user)->postJson(
            "/api/inspection/sessions/{$sessionUid}/extinguishers/{$reception->id}/complete",
            ['checkPayload' => $this->checkPayload($reception), 'clientResultId' => 'client-reception'],
        )->assertOk();

        $progress = InspectionSessionLocationProgress::query()
            ->where('main_location', $scope['mainLocation'])
            ->where('sub_location', 'Reception')
            ->firstOrFail();
        $this->assertSame('Reception', $progress->sub_location);
        $this->assertSame('completed', $progress->status);

        $response = $this->actingAs($user)->getJson(
            "/api/inspection/sessions/{$sessionUid}/extinguishers?zone=Test%20Zone&mainLocation=Session%20Progress%20Hub",
        );
        $response->assertOk();
        $this->assertSame('Reception', $response->json('meta.completedLocations.0.subLocation'));
        $this->assertSame(1, $response->json('meta.completedLocations.0.completedCount'));
        $this->assertSame(1, $response->json('meta.completedLocations.0.expectedCount'));
        $this->assertDatabaseHas('inspection_session_location_progress', [
            'sub_location' => $auditorium->sub_location_name,
            'status' => 'in_progress',
            'expected_count' => 1,
            'completed_count' => 0,
        ]);
    }

    public function test_idempotent_completion_replay_repairs_missing_location_progress(): void
    {
        $user = $this->inspectionUser('Inspector A');
        $scope = ['zone' => 'Replay Zone', 'mainLocation' => 'Replay Progress Hub'];
        $extinguisher = $this->extinguisher([
            'zone' => $scope['zone'],
            'main_location_name' => $scope['mainLocation'],
            'sub_location_name' => 'Reception',
        ]);
        $sessionUid = $this->createSession($user, $scope);
        $payload = [
            'checkPayload' => $this->checkPayload($extinguisher),
            'clientResultId' => 'client-replay-reception',
        ];

        $this->actingAs($user)->postJson(
            "/api/inspection/sessions/{$sessionUid}/extinguishers/{$extinguisher->id}/complete",
            $payload,
        )->assertOk();
        InspectionSessionLocationProgress::query()->delete();

        $replay = $this->actingAs($user)->postJson(
            "/api/inspection/sessions/{$sessionUid}/extinguishers/{$extinguisher->id}/complete",
            $payload,
        );

        $replay->assertOk();
        $replay->assertJsonPath('meta.completedLocations.0.subLocation', 'Reception');
        $this->assertDatabaseHas('inspection_session_location_progress', [
            'sub_location' => 'Reception',
            'status' => 'completed',
            'expected_count' => 1,
            'completed_count' => 1,
        ]);
    }

    public function test_completed_extinguisher_result_can_be_reset_by_original_checker(): void
    {
        $user = $this->inspectionUser('Inspector A');
        $scope = ['zone' => 'Reset Zone', 'mainLocation' => 'Reset Progress Hub'];
        $extinguisher = $this->extinguisher([
            'zone' => $scope['zone'],
            'main_location_name' => $scope['mainLocation'],
            'sub_location_name' => 'Reception',
        ]);
        $sessionUid = $this->createSession($user, $scope);

        $this->actingAs($user)->postJson(
            "/api/inspection/sessions/{$sessionUid}/extinguishers/{$extinguisher->id}/complete",
            ['checkPayload' => $this->checkPayload($extinguisher), 'clientResultId' => 'client-reset'],
        )->assertOk();

        $resetPayload = $this->checkPayload($extinguisher);
        foreach ([
            'physicalCondition',
            'signageCondition',
            'boxKeyAvailability',
            'boxGlassAvailability',
            'operationalCondition',
        ] as $field) {
            $resetPayload[$field] = '';
        }

        $response = $this->actingAs($user)->postJson(
            "/api/inspection/sessions/{$sessionUid}/extinguishers/{$extinguisher->id}/reset",
            ['checkPayload' => $resetPayload],
        );

        $response->assertOk();
        $response->assertJsonPath('data.status', 'in_progress');
        $response->assertJsonPath('data.checkedBy', '');
        $response->assertJsonPath('data.checkedAt', null);
        $response->assertJsonPath('data.checkPayload.physicalCondition', null);
        $this->assertSame([], $response->json('meta.completedLocations'));
        $this->assertDatabaseHas('inspection_session_location_progress', [
            'sub_location' => 'Reception',
            'status' => 'in_progress',
            'expected_count' => 1,
            'completed_count' => 0,
        ]);
    }

    public function test_completed_extinguisher_result_can_be_reset_by_another_checker(): void
    {
        $firstUser = $this->inspectionUser('Inspector A');
        $secondUser = $this->inspectionUser('Inspector B');
        $extinguisher = $this->extinguisher();
        $sessionUid = $this->createSession($firstUser);

        $this->actingAs($firstUser)->postJson(
            "/api/inspection/sessions/{$sessionUid}/extinguishers/{$extinguisher->id}/complete",
            ['checkPayload' => $this->checkPayload($extinguisher), 'clientResultId' => 'client-reset-conflict'],
        )->assertOk();

        $response = $this->actingAs($secondUser)->postJson(
            "/api/inspection/sessions/{$sessionUid}/extinguishers/{$extinguisher->id}/reset",
            ['checkPayload' => $this->checkPayload($extinguisher)],
        );

        $response->assertOk();
        $response->assertJsonPath('data.status', 'in_progress');
        $response->assertJsonPath('data.checkedBy', '');
        $response->assertJsonPath('data.checkedAt', null);
        $this->assertDatabaseHas('inspection_extinguisher_results', [
            'inspection_session_id' => InspectionSession::query()
                ->where('session_uid', $sessionUid)
                ->value('id'),
            'fire_extinguisher_id' => $extinguisher->id,
            'status' => 'in_progress',
            'checked_by_user_id' => null,
        ]);
    }

    public function test_location_results_repair_progress_and_match_display_zone_labels(): void
    {
        $user = $this->inspectionUser('Inspector A');
        $extinguisher = $this->extinguisher([
            'zone' => '1',
            'main_location_name' => 'Display Progress Hub',
            'sub_location_name' => 'Reception',
        ]);
        $sessionUid = $this->createSession($user, [
            'zone' => 'Zone 1',
            'mainLocation' => 'Display Progress Hub',
        ]);
        $payload = $this->checkPayload($extinguisher);
        $payload['zone'] = 'Zone 1';

        $this->actingAs($user)->postJson(
            "/api/inspection/sessions/{$sessionUid}/extinguishers/{$extinguisher->id}/complete",
            ['checkPayload' => $payload, 'clientResultId' => 'client-zone-display'],
        )->assertOk();
        InspectionSessionLocationProgress::query()->delete();

        $response = $this->actingAs($user)->getJson(
            "/api/inspection/sessions/{$sessionUid}/extinguishers?zone=Zone%201&mainLocation=Display%20Progress%20Hub",
        );

        $response->assertOk();
        $response->assertJsonPath('data.0.zone', '1');
        $response->assertJsonPath('data.0.subLocation', 'Reception');
        $response->assertJsonPath('meta.completedLocations.0.zone', '1');
        $response->assertJsonPath('meta.completedLocations.0.subLocation', 'Reception');
        $this->assertDatabaseHas('inspection_session_location_progress', [
            'zone' => '1',
            'main_location' => 'Display Progress Hub',
            'sub_location' => 'Reception',
            'status' => 'completed',
        ]);
    }

    public function test_resumed_session_response_is_hydrated_with_results_and_progress(): void
    {
        $firstUser = $this->inspectionUser('Inspector A');
        $secondUser = $this->inspectionUser('Inspector B');
        $extinguisher = $this->extinguisher([
            'zone' => '1',
            'main_location_name' => 'Hydrated Startup Hub',
            'sub_location_name' => 'Reception',
        ]);
        $auditorium = $this->extinguisher([
            'zone' => '1',
            'main_location_name' => 'Hydrated Startup Hub',
            'sub_location_name' => 'Infront Auditorium',
            'id_loc_no' => 'ADO-002',
            'barcode_no' => 'BAR-002',
        ]);
        $sessionUid = $this->createSession($firstUser, [
            'zone' => 'Zone 1',
            'mainLocation' => 'Hydrated Startup Hub',
        ]);

        $this->actingAs($firstUser)->postJson(
            "/api/inspection/sessions/{$sessionUid}/extinguishers/{$extinguisher->id}/complete",
            ['checkPayload' => $this->checkPayload($extinguisher), 'clientResultId' => 'client-hydrated-startup'],
        )->assertOk();
        $this->actingAs($firstUser)->postJson(
            "/api/inspection/sessions/{$sessionUid}/extinguishers/{$auditorium->id}/complete",
            ['checkPayload' => $this->checkPayload($auditorium), 'clientResultId' => 'client-hydrated-area'],
        )->assertOk();

        $response = $this->actingAs($secondUser)->postJson('/api/inspection/sessions', [
            'inspectionType' => 'Fire Extinguisher Inspection',
            'zone' => 'Zone 1',
            'mainLocation' => 'Hydrated Startup Hub',
            'subLocation' => 'Reception',
        ]);

        $response->assertOk();
        $response->assertJsonPath('created', false);
        $response->assertJsonPath('data.sessionUid', $sessionUid);
        $response->assertJsonPath('data.results.0.status', 'completed');
        $response->assertJsonPath('data.results.0.checkedBy', 'Inspector A');
        $this->assertContains('Infront Auditorium', collect($response->json('data.results'))->pluck('subLocation'));
        $this->assertContains('Reception', collect($response->json('data.progress.completedLocations'))->pluck('subLocation'));
    }

    public function test_submitting_session_creates_one_compiled_inspection_report(): void
    {
        $user = $this->inspectionUser('Inspector Submitter');
        $first = $this->extinguisher(['id_loc_no' => 'ADO-001', 'barcode_no' => 'BAR-001']);
        $second = $this->extinguisher(['id_loc_no' => 'ADO-002', 'barcode_no' => 'BAR-002']);
        $sessionUid = $this->createSession($user);

        foreach ([$first, $second] as $index => $extinguisher) {
            $this->actingAs($user)->postJson(
                "/api/inspection/sessions/{$sessionUid}/extinguishers/{$extinguisher->id}/complete",
                [
                    'checkPayload' => $this->checkPayload($extinguisher),
                    'clientResultId' => "client-{$index}",
                ],
            )->assertOk();
        }

        $clientSubmittedAt = Carbon::parse('2026-07-08T21:07:00+08:00');
        $submit = $this->actingAs($user)->postJson("/api/inspection/sessions/{$sessionUid}/submit", [
            'display_id' => 'INS-FE-SESSION-001',
            'submitted_at' => $clientSubmittedAt->toIso8601String(),
        ]);

        $submit->assertCreated();
        $this->assertSame(1, Report::query()->where('report_type', 'inspection')->count());
        $report = Report::query()->where('display_id', 'INS-FE-SESSION-001')->firstOrFail();
        $this->assertSame($sessionUid, $report->payload['inspectionSessionUid']);
        $this->assertSame('', $report->payload['reportRemarks'] ?? null);
        $this->assertCount(2, $report->payload['fireExtinguisherChecks']);
        $this->assertTrue($report->submitted_at->equalTo($clientSubmittedAt));
        $this->assertTrue(Carbon::parse($report->payload['compiledAt'])->equalTo($clientSubmittedAt));
        $this->assertTrue(Carbon::parse($report->payload['inspectedAt'])->equalTo($clientSubmittedAt));
        $this->assertSame('2026-07-08', $report->payload['fireExtinguisherInspectionDate']);
        $this->assertSame('submitted', InspectionSession::query()->where('session_uid', $sessionUid)->value('status'));
        $this->assertTrue(
            InspectionSession::query()
                ->where('session_uid', $sessionUid)
                ->firstOrFail()
                ->submitted_at
                ->equalTo($clientSubmittedAt)
        );
        $this->assertDatabaseHas('workflow_notifications', [
            'module' => 'report',
            'event_type' => 'submitted',
            'record_id' => $report->id,
        ]);

        $notification = WorkflowNotification::query()
            ->where('module', 'report')
            ->where('event_type', 'submitted')
            ->where('record_id', $report->id)
            ->first();
        $this->assertNotNull($notification);
        $this->assertSame('inspection', data_get($notification->metadata, 'reportType'));
    }

    public function test_session_submit_is_idempotent_with_submission_key(): void
    {
        $user = $this->inspectionUser('Inspector Submitter');
        $extinguisher = $this->extinguisher();
        $sessionUid = $this->createSession($user);

        $this->actingAs($user)->postJson(
            "/api/inspection/sessions/{$sessionUid}/extinguishers/{$extinguisher->id}/complete",
            ['checkPayload' => $this->checkPayload($extinguisher), 'clientResultId' => 'client-a'],
        )->assertOk();

        $payload = [
            'display_id' => 'INS-FE-SESSION-REPLAY',
            'submission_key' => 'session-submit-replay-key',
        ];

        $first = $this->actingAs($user)->postJson("/api/inspection/sessions/{$sessionUid}/submit", $payload);
        $first->assertCreated();

        $second = $this->actingAs($user)->postJson("/api/inspection/sessions/{$sessionUid}/submit", $payload);
        $second->assertOk()->assertJsonPath('data.idempotentReplay', true);
        $this->assertSame($first->json('data.reportUid'), $second->json('data.reportUid'));
        $this->assertSame(1, Report::query()->where('submission_key', 'session-submit-replay-key')->count());
    }

    public function test_session_submit_rejects_a_stale_session_version(): void
    {
        $user = $this->inspectionUser('Inspector Version');
        $extinguisher = $this->extinguisher();
        $sessionUid = $this->createSession($user);
        $initialVersion = (int) InspectionSession::query()->where('session_uid', $sessionUid)->value('version');

        $this->actingAs($user)->postJson(
            "/api/inspection/sessions/{$sessionUid}/extinguishers/{$extinguisher->id}/complete",
            ['checkPayload' => $this->checkPayload($extinguisher), 'clientResultId' => 'client-version'],
        )->assertOk();

        $response = $this->actingAs($user)->postJson("/api/inspection/sessions/{$sessionUid}/submit", [
            'submission_key' => 'session-submit-stale-version',
            'session_version' => $initialVersion,
        ]);

        $response->assertConflict()->assertJsonPath('code', 'inspection_session_version_conflict');
        $this->assertSame(0, Report::query()->where('submission_key', 'session-submit-stale-version')->count());
    }

    public function test_session_submit_rejects_server_known_pending_operations(): void
    {
        $user = $this->inspectionUser('Inspector Pending');
        $extinguisher = $this->extinguisher();
        $sessionUid = $this->createSession($user);
        $this->actingAs($user)->postJson(
            "/api/inspection/sessions/{$sessionUid}/extinguishers/{$extinguisher->id}/complete",
            ['checkPayload' => $this->checkPayload($extinguisher), 'clientResultId' => 'client-pending'],
        )->assertOk();
        $session = InspectionSession::query()->where('session_uid', $sessionUid)->firstOrFail();
        InspectionExtinguisherOperation::query()->create([
            'operation_uid' => 'pending-operation-for-submit',
            'inspection_session_id' => $session->id,
            'canonical_asset_key' => 'catalog:'.$extinguisher->id,
            'operation_type' => 'complete',
            'actor_user_id' => $user->id,
            'base_version' => 1,
            'payload_hash' => hash('sha256', 'pending'),
            'status' => 'pending',
        ]);

        $response = $this->actingAs($user)->postJson("/api/inspection/sessions/{$sessionUid}/submit", [
            'submission_key' => 'session-submit-pending-operation',
            'session_version' => $session->version,
        ]);

        $response->assertConflict()->assertJsonPath('code', 'inspection_session_operations_pending');
        $this->assertSame(0, Report::query()->where('submission_key', 'session-submit-pending-operation')->count());
    }

    private function createSession(User $user, array $scope = []): string
    {
        return $this->actingAs($user)->postJson('/api/inspection/sessions', [
            'inspectionType' => 'Fire Extinguisher Inspection',
            'zone' => $scope['zone'] ?? '1',
            'mainLocation' => $scope['mainLocation'] ?? 'Manjung Hub',
        ])->assertCreated()->json('data.sessionUid');
    }

    private function extinguisher(array $overrides = []): InspectionFireExtinguisher
    {
        $attributes = array_merge([
            'zone' => '1',
            'main_location_name' => 'Manjung Hub',
            'sub_location_name' => 'Reception',
            'id_loc_no' => 'ADO-001',
            'barcode_no' => 'BAR-001',
            'active_identity_key' => hash('sha256', '1|manjung hub|reception|ADO-001|BAR-001'.random_int(1, PHP_INT_MAX)),
            'fe_type' => 'DP 6KG',
            'source' => 'custom',
            'is_active' => true,
        ], $overrides);

        return InspectionFireExtinguisher::query()->create($attributes);
    }

    private function checkPayload(InspectionFireExtinguisher $extinguisher): array
    {
        return [
            'id' => 'fe:'.$extinguisher->id,
            'catalogId' => $extinguisher->id,
            'equipmentSource' => $extinguisher->source,
            'zone' => $extinguisher->zone,
            'mainLocation' => $extinguisher->main_location_name,
            'subLocation' => $extinguisher->sub_location_name,
            'idLocNo' => $extinguisher->id_loc_no,
            'barcodeNo' => $extinguisher->barcode_no,
            'feType' => $extinguisher->fe_type,
            'physicalCondition' => 'Good',
            'signageCondition' => 'Good',
            'boxKeyAvailability' => 'N/A',
            'boxGlassAvailability' => 'N/A',
            'operationalCondition' => 'Good',
            'remarks' => '',
            'photos' => [],
        ];
    }

    private function managedInspectionPhoto(User $user, string $publicId): ReportMedia
    {
        return ReportMedia::query()->create([
            'public_id' => $publicId,
            'client_upload_id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'module' => 'inspection',
            'disk' => 'local',
            'storage_path' => 'report-media/'.$publicId.'.jpg',
            'thumbnail_path' => 'report-media/'.$publicId.'-thumb.jpg',
            'original_name' => 'camera.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 128 * 1024,
            'thumbnail_size_bytes' => 16 * 1024,
            'width' => 1200,
            'height' => 900,
            'thumbnail_width' => 320,
            'thumbnail_height' => 240,
            'checksum_sha256' => hash('sha256', $publicId),
            'thumbnail_checksum_sha256' => hash('sha256', $publicId.'-thumb'),
        ]);
    }

    private function inspectionUser(string $name = 'Inspection User'): User
    {
        $user = User::factory()->create(['name' => $name, 'status' => 'active']);
        $permission = Permission::query()->firstOrCreate([
            'name' => 'reports.inspection.view',
            'guard_name' => 'web',
        ]);
        $role = Role::query()->firstOrCreate([
            'name' => 'Inspection Session Tester',
            'guard_name' => 'web',
        ]);
        if (! $role->hasPermissionTo($permission)) {
            $role->givePermissionTo($permission);
        }
        $user->assignRole($role);

        return $user;
    }

    private function assignTeam(User $user, Team $team, bool $primary = false): void
    {
        TeamMember::query()->create([
            'team_id' => $team->id,
            'user_id' => $user->id,
            'name' => $user->name,
            'role' => 'Inspector',
            'is_primary' => $primary,
            'started_at' => '2026-01-01',
        ]);
    }

    private function makeSupervisor(User $user): void
    {
        $role = Role::query()->firstOrCreate([
            'name' => 'Incident Commander',
            'guard_name' => 'web',
        ]);
        $permission = Permission::query()->firstOrCreate([
            'name' => 'reports.inspection.view',
            'guard_name' => 'web',
        ]);
        if (! $role->hasPermissionTo($permission)) {
            $role->givePermissionTo($permission);
        }
        $user->syncRoles([$role]);
    }
}
