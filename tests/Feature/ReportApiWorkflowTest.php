<?php

namespace Tests\Feature;

use App\Models\Report;
use App\Models\ReportDraft;
use App\Models\User;
use App\Models\UserRoleAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ReportApiWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_crud_and_transition_workflow(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $ic = User::factory()->create(['status' => 'active']);
        $this->assignWorkflowRole($user, 'ERCO Reporter', 'reports.erco.view');
        $this->assignWorkflowRole($ic, 'Incident Commander', 'reports.erco.view');
        $this->actingAs($user);

        $create = $this->postJson('/api/reports', [
            'display_id' => 'ERCO-01-28042026',
            'report_type' => 'erco',
            'status' => 'Submitted',
            'payload' => array_replace($this->ercoPayload('Zone 1'), [
                'recordActionsVersion' => 999,
                'recordActions' => ['delete' => ['applicable' => true, 'allowed' => true]],
                'canReview' => true,
            ]),
        ]);
        $create->assertCreated();
        $create->assertJsonPath('data.status', 'Submitted');
        $create->assertJsonPath('data.version', 1);
        $create->assertJsonPath('data.recordActionsVersion', 1);
        $create->assertJsonPath('data.recordActions.edit.allowed', true);
        $create->assertJsonPath('data.recordActions.delete.allowed', true);
        $create->assertJsonPath('data.recordActions.download.format', 'pdf');
        $create->assertJsonPath('data.recordActions.download.allowed', true);
        $create->assertJsonPath('data.recordActions.review.applicable', true);
        $create->assertJsonPath('data.recordActions.review.allowed', false);
        $reportUid = (string) $create->json('data.id');
        $storedPayload = Report::query()->where('report_uid', $reportUid)->firstOrFail()->payload;
        $this->assertArrayNotHasKey('recordActionsVersion', $storedPayload);
        $this->assertArrayNotHasKey('recordActions', $storedPayload);
        $this->assertArrayNotHasKey('canReview', $storedPayload);

        $get = $this->getJson("/api/reports/{$reportUid}");
        $get->assertOk();
        $get->assertJsonPath('data.id', $reportUid);

        $update = $this->putJson("/api/reports/{$reportUid}", [
            'version' => 1,
            'status' => 'Submitted',
            'payload' => array_replace($this->ercoPayload('Zone 2'), [
                'incidentType' => 'Fire Updated',
            ]),
        ]);
        $update->assertOk();
        $update->assertJsonPath('data.version', 2);
        $update->assertJsonPath('data.revision', 2);

        $this->actingAs($ic);
        $reviewerView = $this->getJson("/api/reports/{$reportUid}");
        $reviewerView->assertOk();
        $reviewerView->assertJsonPath('data.recordActions.review.allowed', true);
        $reviewerView->assertJsonPath('data.recordActions.reject.allowed', true);
        $reviewerView->assertJsonPath('data.recordActions.edit.allowed', false);
        $reviewerView->assertJsonPath('data.recordActions.delete.allowed', false);

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
        $ic = User::factory()->create(['status' => 'active']);
        $this->assignWorkflowRole($user, 'Drill Reporter', 'reports.drill.view');
        $this->assignWorkflowRole($ic, 'Incident Commander', 'reports.drill.view');
        $this->actingAs($user);

        $create = $this->postJson('/api/reports', [
            'display_id' => 'DRL-01-28042026',
            'report_type' => 'drill',
            'status' => 'Submitted',
            'payload' => $this->drillPayload('Zone A'),
        ]);
        $create->assertCreated();
        $reportUid = (string) $create->json('data.id');

        $this->actingAs($ic);
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

        $this->actingAs($user);
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
        $this->assignWorkflowRole($user, 'Fitness Reporter', 'reports.fitness.view');
        $this->actingAs($user);

        $payload = [
            'display_id' => 'FIT-01-28042026',
            'report_type' => 'fitness-test',
            'status' => 'Submitted',
            'submission_key' => 'report-submit-abc123',
            'payload' => $this->fitnessPayload('Zone T'),
        ];

        $first = $this->postJson('/api/reports', $payload);
        $first->assertCreated();
        $first->assertJsonPath('data.idempotent_replay', false);
        $first->assertJsonPath('data.canDownloadPdf', false);
        $first->assertJsonPath('data.recordActions.download.applicable', true);
        $first->assertJsonPath('data.recordActions.download.allowed', true);
        $first->assertJsonPath('data.recordActions.download.format', 'json');
        $reportUid = (string) $first->json('data.id');
        $draft = ReportDraft::query()->create([
            'user_id' => $user->id,
            'draft_id' => 'drf_fitness_replay_cleanup',
            'report_type' => 'fitness-test',
            'payload' => $payload['payload'],
            'saved_at' => now(),
            'version' => 1,
        ]);

        $second = $this->postJson('/api/reports', [
            ...$payload,
            'source_draft_id' => $draft->draft_id,
        ]);
        $second->assertOk();
        $second->assertJsonPath('data.id', $reportUid);
        $second->assertJsonPath('data.idempotent_replay', true);

        $this->assertDatabaseCount('reports', 1);
        $this->assertDatabaseMissing('report_drafts', ['id' => $draft->id]);
    }

    public function test_submitting_a_resumed_draft_consumes_it_for_each_standard_report_module(): void
    {
        $modules = [
            ['erco', 'ERCO Reporter', 'reports.erco.view', 'ERCO-DRAFT-001', $this->ercoPayload('Zone E')],
            ['drill', 'Drill Reporter', 'reports.drill.view', 'DRILL-DRAFT-001', $this->drillPayload('Zone D')],
            ['fitness-test', 'Fitness Reporter', 'reports.fitness.view', 'FIT-DRAFT-001', $this->fitnessPayload('Zone F')],
        ];

        foreach ($modules as [$reportType, $role, $permission, $displayId, $payload]) {
            $user = User::factory()->create(['status' => 'active']);
            $this->assignWorkflowRole($user, $role, $permission);
            $draft = ReportDraft::query()->create([
                'user_id' => $user->id,
                'draft_id' => 'drf_'.str_replace('-', '_', $reportType),
                'report_type' => $reportType,
                'payload' => $payload,
                'saved_at' => now(),
                'version' => 1,
            ]);

            $this->actingAs($user)->postJson('/api/reports', [
                'display_id' => $displayId,
                'report_type' => $reportType,
                'status' => 'Submitted',
                'source_draft_id' => $draft->draft_id,
                'payload' => $payload,
            ])->assertCreated();

            $this->assertDatabaseMissing('report_drafts', ['id' => $draft->id]);
        }
    }

    public function test_draft_save_does_not_consume_its_source_draft(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->assignWorkflowRole($user, 'Drill Reporter', 'reports.drill.view');
        $draft = ReportDraft::query()->create([
            'user_id' => $user->id,
            'draft_id' => 'drf_drill_still_editing',
            'report_type' => 'drill',
            'payload' => $this->drillPayload('Zone Draft'),
            'saved_at' => now(),
            'version' => 1,
        ]);

        $this->actingAs($user)->postJson('/api/reports', [
            'display_id' => 'DRILL-DRAFT-SAVE-001',
            'report_type' => 'drill',
            'status' => 'Draft',
            'source_draft_id' => $draft->draft_id,
            'payload' => $this->drillPayload('Zone Draft'),
        ])->assertCreated();

        $this->assertDatabaseHas('report_drafts', ['id' => $draft->id]);
    }

    public function test_submission_cannot_consume_another_users_or_another_modules_draft(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $otherUser = User::factory()->create(['status' => 'active']);
        $this->assignWorkflowRole($user, 'Drill Reporter', 'reports.drill.view');
        $foreignDraft = ReportDraft::query()->create([
            'user_id' => $otherUser->id,
            'draft_id' => 'drf_foreign_drill',
            'report_type' => 'drill',
            'payload' => [],
            'saved_at' => now(),
            'version' => 1,
        ]);
        $otherModuleDraft = ReportDraft::query()->create([
            'user_id' => $user->id,
            'draft_id' => 'drf_owned_fitness',
            'report_type' => 'fitness-test',
            'payload' => [],
            'saved_at' => now(),
            'version' => 1,
        ]);

        foreach ([$foreignDraft, $otherModuleDraft] as $index => $sourceDraft) {
            $this->actingAs($user)->postJson('/api/reports', [
                'display_id' => 'DRILL-SCOPED-00'.($index + 1),
                'report_type' => 'drill',
                'status' => 'Submitted',
                'source_draft_id' => $sourceDraft->draft_id,
                'payload' => $this->drillPayload('Zone Scoped'),
            ])->assertCreated();
        }

        $this->assertDatabaseHas('report_drafts', ['id' => $foreignDraft->id]);
        $this->assertDatabaseHas('report_drafts', ['id' => $otherModuleDraft->id]);
    }

    private function assignWorkflowRole(User $user, string $roleName, string $permissionName): void
    {
        $permission = Permission::query()->firstOrCreate([
            'name' => $permissionName,
            'guard_name' => 'web',
        ]);
        $role = Role::query()->firstOrCreate([
            'name' => $roleName,
            'guard_name' => 'web',
        ]);
        if (! $role->hasPermissionTo($permission)) {
            $role->givePermissionTo($permission);
        }

        UserRoleAssignment::query()->create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'scope_type' => 'global',
            'team_id' => null,
            'is_primary' => true,
        ]);
    }

    private function ercoPayload(string $location): array
    {
        return [
            'schemaVersion' => 1,
            'incidentDate' => '2026-04-28',
            'incidentTime' => '09:00',
            'weather' => 'Clear',
            'incidentType' => 'Fire',
            'location' => $location,
            'details' => 'Emergency response details.',
            'summary' => 'Emergency response summary.',
            'respondingTeam' => [
                'name' => 'Alpha',
                'shift' => 'Day',
                'attendance' => [['memberId' => 'member-1', 'name' => 'Responder One', 'role' => 'TRT']],
            ],
            'chronology' => [['time' => '09:00', 'action' => 'Response started.']],
            'postIncidentAnalysis' => [
                'strengths' => ['Rapid mobilisation'],
                'resourcesMobilised' => [],
                'improvementOpportunities' => [],
                'photos' => [[
                    'id' => 'required-photo-1',
                    'url' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
                ]],
            ],
        ];
    }

    private function drillPayload(string $location): array
    {
        return [
            'schemaVersion' => 2,
            'reportDate' => '2026-04-28',
            'reportTime' => '09:00',
            'weather' => 'Clear',
            'incidentType' => 'Fire Drill',
            'location' => $location,
            'details' => 'Controlled drill scenario.',
            'summary' => 'Drill completed safely.',
            'chronology' => [['time' => '09:00', 'action' => 'Exercise started.']],
            'postIncidentAnalysis' => ['photos' => []],
        ];
    }

    private function fitnessPayload(string $location): array
    {
        return [
            'schemaVersion' => 1,
            'reportDate' => '2026-04-28',
            'reportTime' => '09:00',
            'weather' => 'Routine',
            'incidentType' => 'Endurance Test',
            'location' => $location,
            'details' => 'Fitness test session details.',
            'summary' => 'Fitness test completed safely.',
            'chronology' => [['time' => '09:00', 'action' => 'Fitness test started.']],
        ];
    }
}
