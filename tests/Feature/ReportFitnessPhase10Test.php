<?php

namespace Tests\Feature;

use App\Models\FitnessTestReport;
use App\Models\Report;
use App\Models\ReportTimelineEntry;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\UserRoleAssignment;
use App\Models\WorkflowNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ReportFitnessPhase10Test extends TestCase
{
    use RefreshDatabase;

    public function test_phase10_create_blank_draft_and_restore_from_draft_for_fitness_reports(): void
    {
        $owner = User::factory()->create(['status' => 'active']);
        $this->assignFitnessPermissionRole($owner, 'Fitness Draft Reporter');

        $blankDraft = $this->actingAs($owner)->postJson('/api/reports', [
            'display_id' => 'FIT-P10-DRAFT-001',
            'report_type' => 'fitness-test',
            'status' => 'Draft',
            'payload' => $this->canonicalFitnessPayload([
                'documentReference' => 'DOC-P10-DRAFT',
                'summary' => 'Draft created for restoration flow.',
            ]),
        ]);
        $blankDraft->assertCreated()
            ->assertJsonPath('data.status', 'Draft')
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.revision', 1);

        $draft = $this->actingAs($owner)->postJson('/api/reports/drafts', [
            'report_type' => 'fitness-test',
            'payload' => [
                'schemaVersion' => 1,
                'reportDate' => '2026-07-23',
                'shiftGroups' => [
                    [
                        'id' => 'group-draft',
                        'shiftName' => 'Draft Shift',
                        'participants' => [
                            [
                                'id' => 'participant-draft',
                                'name' => 'Draft User',
                                'source' => 'manual',
                                'role' => 'SC',
                                'ageSnapshot' => 24,
                                'fitness' => ['sitUps' => 1, 'jumpingJacks' => 2, 'pushUps' => 3, 'testedOn' => '2026-07-23'],
                                'proficiency' => ['durationSeconds' => 12, 'testedOn' => '2026-07-23', 'result' => 'passed'],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
        $draft->assertCreated();
        $draftId = (string) $draft->json('data.draft_id');

        $restored = $this->actingAs($owner)->postJson('/api/reports', [
            'report_uid' => 'FIT-P10-RESTORE-001',
            'display_id' => 'FIT-P10-RESTORE-001',
            'report_type' => 'fitness-test',
            'status' => 'Submitted',
            'source_draft_id' => $draftId,
            'payload' => $this->canonicalFitnessPayload([
                'documentReference' => 'DOC-P10-RESTORE',
                'protocolRevision' => 'v1',
                'summary' => 'Restored from fitness draft.',
            ]),
        ]);
        $restored->assertCreated()
            ->assertJsonPath('data.id', 'FIT-P10-RESTORE-001')
            ->assertJsonPath('data.idempotent_replay', false)
            ->assertJsonPath('data.status', 'Submitted');

        $this->assertDatabaseMissing('report_drafts', ['draft_id' => $draftId]);
        $this->assertDatabaseCount('reports', 2);
    }

    public function test_phase10_create_handles_manual_and_roster_participants_with_external_and_staff_assessor_roles(): void
    {
        $owner = User::factory()->create(['status' => 'active']);
        $rosterUser = User::factory()->create(['status' => 'active', 'name' => 'Roster User']);
        $externalAssessor = User::factory()->create(['status' => 'active', 'name' => 'External Assessor']);
        $staffAssessor = User::factory()->create(['status' => 'active', 'name' => 'Staff Assessor']);
        Team::query()->create(['name' => 'P10-Fitness Team', 'status' => 'On Duty']);
        $this->assignFitnessPermissionRole($owner, 'Fitness Source Reporter');

        $created = $this->actingAs($owner)->postJson('/api/reports', [
            'display_id' => 'FIT-P10-SOURCE-001',
            'report_type' => 'fitness-test',
            'status' => 'Submitted',
            'payload' => $this->canonicalFitnessPayload([
                'reportingMonth' => '2026-07',
                'documentReference' => 'DOC-P10-SOURCE',
                'shiftGroups' => [
                    [
                        'id' => 'group-staff',
                        'shiftName' => 'Morning',
                        'assessor' => [
                            'userId' => $staffAssessor->id,
                            'name' => $staffAssessor->name,
                        ],
                        'participants' => [
                            [
                                'id' => 'participant-manual',
                                'name' => 'Manual Entry',
                                'source' => 'manual',
                                'role' => 'SC',
                                'ageSnapshot' => 28,
                                'fitness' => ['sitUps' => 12, 'jumpingJacks' => 14, 'pushUps' => 11, 'testedOn' => '2026-07-23', 'result' => 'passed'],
                                'proficiency' => ['durationSeconds' => 72, 'testedOn' => '2026-07-23', 'result' => 'passed'],
                            ],
                        ],
                    ],
                    [
                        'id' => 'group-external',
                        'shiftName' => 'Afternoon',
                        'assessor' => ['name' => $externalAssessor->name],
                        'participants' => [
                            [
                                'id' => 'participant-roster',
                                'userId' => $rosterUser->id,
                                'name' => $rosterUser->name,
                                'source' => 'roster',
                                'role' => 'TRT',
                                'ageSnapshot' => 31,
                                'fitness' => ['sitUps' => 10, 'jumpingJacks' => 12, 'pushUps' => 8, 'testedOn' => '2026-07-23', 'result' => 'failed'],
                                'proficiency' => ['durationSeconds' => 62, 'testedOn' => '2026-07-23', 'result' => 'failed'],
                            ],
                        ],
                    ],
                ],
            ]),
        ]);
        $created->assertCreated()
            ->assertJsonPath('data.shiftGroups.0.assessor.userId', (int) $staffAssessor->id)
            ->assertJsonPath('data.shiftGroups.1.assessor.userId', null)
            ->assertJsonPath('data.shiftGroups.0.participants.0.source', 'manual')
            ->assertJsonPath('data.shiftGroups.1.participants.0.source', 'roster');
    }

    public function test_phase10_read_record_list_detail_review_history_and_permission_scoped_access(): void
    {
        $owner = User::factory()->create(['status' => 'active']);
        $reviewer = User::factory()->create(['status' => 'active']);
        $unauthorized = User::factory()->create(['status' => 'active']);

        $this->assignFitnessPermissionRole($owner, 'Fitness Reader Owner');
        $this->assignFitnessPermissionRole($reviewer, 'Incident Commander');

        $created = $this->actingAs($owner)->postJson('/api/reports', [
            'report_uid' => 'FIT-P10-READ-001',
            'display_id' => 'FIT-P10-READ-001',
            'report_type' => 'fitness-test',
            'status' => 'Submitted',
            'payload' => $this->canonicalFitnessPayload(),
        ]);
        $created->assertCreated();
        $recordUid = (string) $created->json('data.id');

        $updated = $this->actingAs($owner)->putJson("/api/reports/{$recordUid}", [
            'version' => 1,
            'status' => 'Submitted',
            'payload' => $this->canonicalFitnessPayload([
                'summary' => 'Revised payload before read assertions.',
            ]),
        ]);
        $updated->assertOk();

        $ownerList = $this->actingAs($owner)->getJson('/api/reports?reportType=fitness-test');
        $ownerList->assertOk();
        $ownerList->assertJsonCount(1, 'data');

        $reviewerQueue = $this->actingAs($reviewer)->getJson('/api/reports?reportType=fitness-test&scope=actionable&action=review');
        $reviewerQueue->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.displayId', 'FIT-P10-READ-001')
            ->assertJsonPath('data.0.canReview', true);

        $allRecords = $this->actingAs($reviewer)->getJson('/api/reports?reportType=fitness-test&scope=all');
        $allRecords->assertOk()
            ->assertJsonPath('data.0.displayId', 'FIT-P10-READ-001');

        $this->actingAs($owner)->getJson("/api/reports/{$recordUid}")
            ->assertOk()
            ->assertJsonPath('data.displayId', 'FIT-P10-READ-001');

        $revisions = $this->actingAs($owner)->getJson("/api/reports/{$recordUid}/revisions");
        $revisions->assertOk()
            ->assertJsonPath('currentRevision', 2)
            ->assertJsonPath('currentVersion', 2);
        $this->assertCount(2, $revisions->json('data'));

        $revision = $this->actingAs($owner)->getJson("/api/reports/{$recordUid}/revisions/1");
        $revision->assertOk()
            ->assertJsonPath('data.revision', 1)
            ->assertJsonPath('data.payload.reportingMonth', '2026-07');

        $this->actingAs($unauthorized)->getJson('/api/reports?reportType=fitness-test')
            ->assertForbidden();
        $this->actingAs($unauthorized)->getJson("/api/reports/{$recordUid}")
            ->assertForbidden();
    }

    public function test_phase10_update_adds_and_removes_participants_and_assessor_and_correction_result(): void
    {
        $owner = User::factory()->create(['status' => 'active']);
        $newAssessor = User::factory()->create(['status' => 'active']);
        $rosterMember = User::factory()->create(['status' => 'active']);
        $this->assignFitnessPermissionRole($owner, 'Fitness Update Owner');

        $created = $this->actingAs($owner)->postJson('/api/reports', [
            'display_id' => 'FIT-P10-UPDATE-001',
            'report_type' => 'fitness-test',
            'status' => 'Submitted',
            'payload' => $this->canonicalFitnessPayload([
                'shiftGroups' => [
                    [
                        'id' => 'group-upd',
                        'shiftName' => 'Morning',
                        'assessor' => ['name' => 'Original Assessor'],
                        'participants' => [
                            [
                                'id' => 'participant-keep',
                                'name' => 'Existing Participant',
                                'source' => 'manual',
                                'role' => 'SC',
                                'ageSnapshot' => 28,
                                'fitness' => ['sitUps' => 10, 'jumpingJacks' => 11, 'pushUps' => 12, 'testedOn' => '2026-07-23', 'result' => 'failed'],
                                'proficiency' => ['durationSeconds' => 88, 'testedOn' => '2026-07-23', 'result' => 'failed'],
                            ],
                        ],
                    ],
                ],
            ]),
        ]);
        $created->assertCreated();
        $recordUid = (string) $created->json('data.id');

        $updated = $this->actingAs($owner)->putJson("/api/reports/{$recordUid}", [
            'version' => 1,
            'status' => 'Submitted',
            'payload' => $this->canonicalFitnessPayload([
                'shiftGroups' => [
                    [
                        'id' => 'group-upd',
                        'shiftName' => 'Morning',
                        'assessor' => [
                            'userId' => $newAssessor->id,
                            'name' => $newAssessor->name,
                        ],
                        'participants' => [
                            [
                                'id' => 'participant-added',
                                'name' => 'Added Manual',
                                'source' => 'manual',
                                'role' => 'SC',
                                'ageSnapshot' => 30,
                                'fitness' => ['sitUps' => 14, 'jumpingJacks' => 16, 'pushUps' => 12, 'testedOn' => '2026-07-23', 'result' => 'passed'],
                                'proficiency' => ['durationSeconds' => 95, 'testedOn' => '2026-07-23', 'result' => 'passed'],
                            ],
                            [
                                'id' => 'participant-roster-updated',
                                'userId' => $rosterMember->id,
                                'name' => $rosterMember->name,
                                'source' => 'roster',
                                'role' => 'TRT',
                                'ageSnapshot' => 35,
                                'fitness' => ['sitUps' => 9, 'jumpingJacks' => 10, 'pushUps' => 8, 'testedOn' => '2026-07-23', 'result' => 'passed'],
                                'proficiency' => ['durationSeconds' => 78, 'testedOn' => '2026-07-23', 'result' => 'passed'],
                            ],
                        ],
                    ],
                ],
            ]),
        ]);

        $updated->assertOk()
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.revision', 2)
            ->assertJsonPath('data.shiftGroups.0.assessor.userId', (int) $newAssessor->id)
            ->assertJsonPath('data.shiftGroups.0.participants.0.id', 'participant-added')
            ->assertJsonPath('data.shiftGroups.0.participants.0.assessmentStatus', 'passed')
            ->assertJsonPath('data.shiftGroups.0.participants.1.source', 'roster')
            ->assertJsonPath('data.participantCount', 2);
    }

    public function test_phase10_block_reviewed_updates_allows_rejected_resubmits_and_reports_conflict_on_stale_version(): void
    {
        $owner = User::factory()->create(['status' => 'active']);
        $reviewer = User::factory()->create(['status' => 'active']);
        $this->assignFitnessPermissionRole($owner, 'Fitness Policy Owner');
        $this->assignFitnessPermissionRole($reviewer, 'Incident Commander');

        $reviewed = $this->actingAs($owner)->postJson('/api/reports', [
            'display_id' => 'FIT-P10-BLOCK-001',
            'report_type' => 'fitness-test',
            'status' => 'Submitted',
            'payload' => $this->canonicalFitnessPayload(),
        ]);
        $reviewed->assertCreated();
        $reviewedUid = (string) $reviewed->json('data.id');

        $this->actingAs($reviewer)->postJson("/api/reports/{$reviewedUid}/review", [
            'version' => 1,
            'remarks' => 'Reviewed to lock edits.',
        ])->assertOk();
        $this->actingAs($owner)->putJson("/api/reports/{$reviewedUid}", [
            'version' => 2,
            'status' => 'Submitted',
            'payload' => $this->canonicalFitnessPayload([
                'summary' => 'Attempted update after review should be blocked.',
            ]),
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['status']);

        $rejected = $this->actingAs($owner)->postJson('/api/reports', [
            'display_id' => 'FIT-P10-REJECT-001',
            'report_type' => 'fitness-test',
            'status' => 'Submitted',
            'payload' => $this->canonicalFitnessPayload(),
        ]);
        $rejected->assertCreated();
        $rejectedUid = (string) $rejected->json('data.id');
        $this->actingAs($reviewer)->postJson("/api/reports/{$rejectedUid}/reject", [
            'version' => 1,
            'remarks' => 'Rejecting to validate resubmit policy.',
        ])->assertOk()
            ->assertJsonPath('data.status', 'Rejected')
            ->assertJsonPath('data.version', 2);

        $resubmit = $this->actingAs($owner)->putJson("/api/reports/{$rejectedUid}", [
            'version' => 2,
            'status' => 'Submitted',
            'payload' => $this->canonicalFitnessPayload([
                'summary' => 'Corrected and resubmitted after reject.',
            ]),
        ]);
        $resubmit->assertOk()
            ->assertJsonPath('data.version', 3)
            ->assertJsonPath('data.status', 'Submitted');

        $this->actingAs($owner)->putJson("/api/reports/{$rejectedUid}", [
            'version' => 1,
            'status' => 'Submitted',
            'payload' => $this->canonicalFitnessPayload(),
        ])->assertStatus(409)
            ->assertJsonPath('code', 'REPORT_VERSION_CONFLICT');
    }

    public function test_phase10_keeps_roster_member_projection_when_assessor_or_member_becomes_inactive(): void
    {
        $owner = User::factory()->create(['status' => 'active']);
        $rosterMember = User::factory()->create(['status' => 'active', 'name' => 'Roster Member']);
        $team = Team::query()->create(['name' => 'P10-Team', 'status' => 'On Duty']);
        $membership = TeamMember::query()->create([
            'team_id' => $team->id,
            'user_id' => $rosterMember->id,
            'name' => $rosterMember->name,
            'started_at' => now()->toDateString(),
        ]);
        $this->assignFitnessPermissionRole($owner, 'Fitness Roster Owner');

        $created = $this->actingAs($owner)->postJson('/api/reports', [
            'display_id' => 'FIT-P10-INACTIVE-001',
            'report_type' => 'fitness-test',
            'status' => 'Submitted',
            'payload' => $this->canonicalFitnessPayload([
                'shiftGroups' => [
                    [
                        'id' => 'group-inactive',
                        'shiftName' => 'Evening',
                        'assessor' => ['name' => 'Inactive Watch Assessor'],
                        'participants' => [
                            [
                                'id' => 'participant-roster-member',
                                'userId' => $rosterMember->id,
                                'name' => $rosterMember->name,
                                'source' => 'roster',
                                'role' => 'TRT',
                                'ageSnapshot' => 29,
                                'fitness' => ['sitUps' => 8, 'jumpingJacks' => 6, 'pushUps' => 7, 'testedOn' => '2026-07-23', 'result' => 'failed'],
                                'proficiency' => ['durationSeconds' => 70, 'testedOn' => '2026-07-23', 'result' => 'failed'],
                            ],
                        ],
                    ],
                ],
            ]),
        ]);
        $created->assertCreated();
        $recordUid = (string) $created->json('data.id');

        $membership->update(['ended_at' => now()->subDay()->toDateString()]);
        $rosterMember->update(['status' => 'inactive']);

        $updated = $this->actingAs($owner)->putJson("/api/reports/{$recordUid}", [
            'version' => 1,
            'status' => 'Submitted',
            'payload' => $this->canonicalFitnessPayload([
                'shiftGroups' => [
                    [
                        'id' => 'group-inactive',
                        'shiftName' => 'Evening',
                        'assessor' => ['name' => 'Inactive Watch Assessor'],
                        'participants' => [
                            [
                                'id' => 'participant-roster-member',
                                'userId' => $rosterMember->id,
                                'name' => $rosterMember->name,
                                'source' => 'roster',
                                'role' => 'TRT',
                                'ageSnapshot' => 29,
                                'fitness' => ['sitUps' => 9, 'jumpingJacks' => 7, 'pushUps' => 8, 'testedOn' => '2026-07-23', 'result' => 'passed'],
                                'proficiency' => ['durationSeconds' => 75, 'testedOn' => '2026-07-23', 'result' => 'passed'],
                            ],
                        ],
                    ],
                ],
            ]),
        ]);
        $updated->assertOk()
            ->assertJsonPath('data.shiftGroups.0.participants.0.userId', (int) $rosterMember->id)
            ->assertJsonPath('data.shiftGroups.0.participants.0.assessmentStatus', 'passed');
    }

    public function test_phase10_delete_restore_are_soft_with_projection_timeline_preserved(): void
    {
        $owner = User::factory()->create(['status' => 'active']);
        $this->assignFitnessPermissionRole($owner, 'Fitness Delete Owner');

        $created = $this->actingAs($owner)->postJson('/api/reports', [
            'report_uid' => 'FIT-P10-LIFECYCLE-001',
            'display_id' => 'FIT-P10-LIFECYCLE-001',
            'report_type' => 'fitness-test',
            'status' => 'Submitted',
            'payload' => $this->canonicalFitnessPayload(),
        ]);
        $created->assertCreated();
        $recordUid = (string) $created->json('data.id');
        $report = Report::query()->where('report_uid', $recordUid)->firstOrFail();
        $fitnessReportId = FitnessTestReport::query()->where('report_id', $report->id)->value('report_id');
        $this->assertNotNull($fitnessReportId);

        $deleted = $this->actingAs($owner)->deleteJson("/api/reports/{$recordUid}");
        $deleted->assertNoContent();

        $trashed = Report::query()->withTrashed()->where('report_uid', $recordUid)->firstOrFail();
        $this->assertTrue((bool) $trashed->deleted_at);
        $actions = ReportTimelineEntry::query()->where('report_id', $report->id)->pluck('action')->all();
        $this->assertContains('Deleted', $actions);
        $this->assertDatabaseHas('fitness_test_reports', ['report_id' => $fitnessReportId]);

        $restored = $this->actingAs($owner)->postJson("/api/reports/{$recordUid}/restore");
        $restored->assertOk()
            ->assertJsonPath('data.status', 'Submitted')
            ->assertJsonPath('data.displayId', 'FIT-P10-LIFECYCLE-001');
        $fresh = Report::query()->where('report_uid', $recordUid)->firstOrFail();
        $freshActions = ReportTimelineEntry::query()->where('report_id', $fresh->id)->pluck('action')->all();
        $this->assertContains('Restored', $freshActions);
        $this->assertContains('Deleted', $freshActions);
    }

    public function test_phase10_deleted_fitness_reports_do_not_appear_in_reporting_stats_until_restore(): void
    {
        $owner = User::factory()->create(['status' => 'active']);
        $statsViewer = User::factory()->create(['status' => 'active']);
        $this->createDashboardUser($statsViewer, ['self.dashboard', 'dashboard.reports.view']);
        $this->assignFitnessPermissionRole($owner, 'Fitness Stats Owner');

        $created = $this->actingAs($owner)->postJson('/api/reports', [
            'display_id' => 'FIT-P10-STAT-001',
            'report_type' => 'fitness-test',
            'status' => 'Submitted',
            'payload' => $this->canonicalFitnessPayload([
                'documentReference' => 'DOC-P10-STAT',
            ]),
        ]);
        $created->assertCreated();
        $recordUid = (string) $created->json('data.id');

        $beforeDelete = $this->actingAs($statsViewer)->getJson('/api/stats/reports');
        $beforeDelete->assertOk()
            ->assertJsonPath('submittedThisPeriod', 1)
            ->assertJsonPath('byType.fitnessTest', 1);

        $this->actingAs($owner)->deleteJson("/api/reports/{$recordUid}");

        $duringDelete = $this->actingAs($statsViewer)->getJson('/api/stats/reports');
        $duringDelete->assertOk()
            ->assertJsonPath('submittedThisPeriod', 0)
            ->assertJsonPath('byType.fitnessTest', 0);

        $this->actingAs($owner)->postJson("/api/reports/{$recordUid}/restore")->assertOk();
        $afterRestore = $this->actingAs($statsViewer)->getJson('/api/stats/reports');
        $afterRestore->assertOk()
            ->assertJsonPath('submittedThisPeriod', 1)
            ->assertJsonPath('byType.fitnessTest', 1);
    }

    public function test_phase10_workflow_submit_review_reject_resubmit_approve_and_notifications_and_action_queue(): void
    {
        $owner = User::factory()->create(['status' => 'active']);
        $reviewer = User::factory()->create(['status' => 'active']);
        $approver = User::factory()->create(['status' => 'active']);

        $this->assignFitnessPermissionRole($owner, 'Fitness Submitter', ['self.dashboard']);
        $this->assignFitnessPermissionRole($reviewer, 'Incident Commander', ['self.dashboard']);
        $this->assignFitnessPermissionRole($approver, 'Incident Commander', ['self.dashboard']);

        $created = $this->actingAs($owner)->postJson('/api/reports', [
            'display_id' => 'FIT-P10-WF-001',
            'report_type' => 'fitness-test',
            'status' => 'Submitted',
            'payload' => $this->canonicalFitnessPayload(),
        ]);
        $created->assertCreated();
        $recordUid = (string) $created->json('data.id');

        $reviewQueue = $this->actingAs($reviewer)->getJson('/api/dashboard/action-queue');
        $reviewQueue->assertOk();
        $reviewItem = collect($reviewQueue->json('items'))->firstWhere('key', 'reports.fitness-test.review');
        $this->assertNotNull($reviewItem);
        $this->assertSame(1, $reviewItem['count']);
        $this->assertSame('/report/fitness-test?scope=actionable&action=review', $reviewItem['to']);

        $this->assertNotificationTargets('FIT-P10-WF-001', 'submitted', [(int) $reviewer->id, (int) $approver->id]);

        $this->actingAs($reviewer)->postJson("/api/reports/{$recordUid}/review", [
            'version' => 1,
            'remarks' => 'Reviewed before rejection.',
        ])->assertOk()
            ->assertJsonPath('data.status', 'Reviewed');
        $this->assertNotificationTargets('FIT-P10-WF-001', 'reviewed', [(int) $reviewer->id, (int) $approver->id]);

        $this->actingAs($reviewer)->postJson("/api/reports/{$recordUid}/reject", [
            'version' => 2,
            'remarks' => 'Rejecting to validate correction loop.',
        ])->assertOk()
            ->assertJsonPath('data.status', 'Rejected')
            ->assertJsonPath('data.version', 3);
        $this->assertNotificationTargets('FIT-P10-WF-001', 'rejected', [(int) $owner->id]);

        $ownerCorrection = $this->actingAs($owner)->getJson('/api/dashboard/action-queue');
        $ownerCorrection->assertOk();
        $correctionItem = collect($ownerCorrection->json('items'))->firstWhere('key', 'reports.fitness-test.correction');
        $this->assertNotNull($correctionItem);
        $this->assertSame(1, $correctionItem['count']);

        $resubmit = $this->actingAs($owner)->putJson("/api/reports/{$recordUid}", [
            'version' => 3,
            'status' => 'Submitted',
            'payload' => $this->canonicalFitnessPayload([
                'summary' => 'Resubmitted for approval.',
            ]),
        ]);
        $resubmit->assertOk()
            ->assertJsonPath('data.status', 'Submitted')
            ->assertJsonPath('data.version', 4);
        $this->assertNotificationTargets('FIT-P10-WF-001', 'submitted', [(int) $reviewer->id, (int) $approver->id]);

        $reviewQueueAfterResubmit = $this->actingAs($reviewer)->getJson('/api/dashboard/action-queue');
        $reviewQueueAfterResubmit->assertOk();
        $reviewItemAfterResubmit = collect($reviewQueueAfterResubmit->json('items'))->firstWhere(
            'key',
            'reports.fitness-test.review',
        );
        $this->assertNotNull($reviewItemAfterResubmit);
        $this->assertSame(1, $reviewItemAfterResubmit['count']);

        $this->actingAs($reviewer)->postJson("/api/reports/{$recordUid}/review", [
            'version' => 4,
            'remarks' => 'Reviewed after correction.',
        ])->assertOk()
            ->assertJsonPath('data.status', 'Reviewed');

        $this->actingAs($approver)->postJson("/api/reports/{$recordUid}/approve", [
            'version' => 5,
            'remarks' => 'Approved after correction.',
        ])->assertOk()
            ->assertJsonPath('data.status', 'Approved')
            ->assertJsonPath('data.workflowStage', 'done');

        $this->assertNotificationTargets('FIT-P10-WF-001', 'approved', [(int) $owner->id]);
    }

    public function test_phase10_prevents_self_review_and_self_approval_in_fitness_workflow(): void
    {
        $owner = User::factory()->create(['status' => 'active']);
        $independentReviewer = User::factory()->create(['status' => 'active']);
        $this->assignFitnessPermissionRole($owner, 'Incident Commander');
        $this->assignFitnessPermissionRole($independentReviewer, 'Incident Commander');

        $created = $this->actingAs($owner)->postJson('/api/reports', [
            'display_id' => 'FIT-P10-SELF-001',
            'report_type' => 'fitness-test',
            'status' => 'Submitted',
            'payload' => $this->canonicalFitnessPayload(),
        ]);
        $created->assertCreated();
        $recordUid = (string) $created->json('data.id');

        $this->actingAs($owner)->postJson("/api/reports/{$recordUid}/review", [
            'version' => 1,
            'remarks' => 'Owner trying self-review.',
        ])->assertForbidden();

        $this->actingAs($independentReviewer)->postJson("/api/reports/{$recordUid}/review", [
            'version' => 1,
            'remarks' => 'Independent reviewer.',
        ])->assertOk();
        $this->actingAs($owner)->postJson("/api/reports/{$recordUid}/approve", [
            'version' => 2,
            'remarks' => 'Owner trying self-approval.',
        ])->assertForbidden();
    }

    private function assignFitnessPermissionRole(User $user, string $roleName, string|array $extraPermissions = []): void
    {
        $permissionNames = array_values(array_unique(array_merge([
            'reports.fitness.view',
        ], is_array($extraPermissions) ? $extraPermissions : [$extraPermissions])));

        $permissions = Permission::query()->whereIn('name', $permissionNames)->get()->keyBy('name');
        foreach ($permissionNames as $permissionName) {
            if (! isset($permissions[$permissionName])) {
                $permissions[$permissionName] = Permission::query()->firstOrCreate([
                    'name' => $permissionName,
                    'guard_name' => 'web',
                ]);
            }
        }
        $role = Role::query()->firstOrCreate([
            'name' => $roleName,
            'guard_name' => 'web',
        ]);
        foreach ($permissions as $permission) {
            if (! $role->hasPermissionTo($permission)) {
                $role->givePermissionTo($permission);
            }
        }

        UserRoleAssignment::query()->create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'scope_type' => 'global',
            'team_id' => null,
            'is_primary' => true,
        ]);
    }

    private function createDashboardUser(User $user, array $permissions): void
    {
        $this->actingAs($user);
        foreach ($permissions as $permissionName) {
            Permission::query()->firstOrCreate([
                'name' => $permissionName,
                'guard_name' => 'web',
            ]);
        }
        $role = Role::query()->firstOrCreate([
            'name' => 'Dashboard Stats Phase 10',
            'guard_name' => 'web',
        ]);
        $role->syncPermissions($permissions);
        $user->assignRole($role);
    }

    private function canonicalFitnessPayload(array $overrides = []): array
    {
        $shiftGroups = $overrides['shiftGroups'] ?? [[
            'id' => 'group-default',
            'shiftName' => 'Default Shift',
            'assessor' => ['name' => 'Default Assessor'],
            'participants' => [
                [
                    'id' => 'participant-default',
                    'name' => 'Default Participant',
                    'source' => 'manual',
                    'role' => 'SC',
                    'ageSnapshot' => 27,
                    'fitness' => ['sitUps' => 12, 'jumpingJacks' => 14, 'pushUps' => 11, 'testedOn' => '2026-07-23', 'result' => 'passed'],
                    'proficiency' => ['durationSeconds' => 90, 'testedOn' => '2026-07-23', 'result' => 'passed'],
                ],
            ],
        ]];
        unset($overrides['shiftGroups']);

        return array_merge([
            'schemaVersion' => 1,
            'reportingMonth' => '2026-07',
            'documentReference' => 'DOC-P10-GENERAL',
            'protocolRevision' => 'v1',
            'reportDate' => '2026-07-23',
            'reportTime' => '09:00',
            'weather' => 'Routine',
            'incidentType' => 'Endurance Test',
            'location' => 'Training yard',
            'details' => 'Fitness test session details.',
            'summary' => 'Fitness test completed.',
            'chronology' => [['time' => '09:00', 'action' => 'Fitness test started.']],
            'shiftGroups' => $shiftGroups,
        ], $overrides);
    }

    private function assertNotificationTargets(string $displayId, string $eventType, array $expectedUserIds): void
    {
        $notification = WorkflowNotification::query()
            ->where('record_display_id', $displayId)
            ->where('event_type', $eventType)
            ->latest('id')
            ->first();

        $this->assertNotNull($notification, "Missing {$eventType} notification for {$displayId}.");
        $recipients = array_map('intval', $notification->recipient_user_ids ?? []);
        foreach ($expectedUserIds as $expectedUserId) {
            $this->assertContains((int) $expectedUserId, $recipients);
        }
    }
}
