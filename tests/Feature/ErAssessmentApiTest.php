<?php

namespace Tests\Feature;

use App\Models\Report;
use App\Models\ReportMedia;
use App\Models\ReportTimelineEntry;
use App\Models\Team;
use App\Models\User;
use App\Models\UserRoleAssignment;
use App\Services\RoleCatalog;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdfWrapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ErAssessmentApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_template_draft_submission_revision_and_pdf_use_shared_report_contracts(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $reviewer = User::factory()->create(['status' => 'active']);
        $team = Team::factory()->create(['name' => 'ER Assessment Workflow Team']);
        $this->assignWorkflowRole($user, 'Tactical Response Team', $team);
        $this->assignWorkflowRole($reviewer, 'Incident Commander', $team);
        $this->actingAs($user);

        $this->getJson('/api/reports/er-assessment/template')
            ->assertOk()
            ->assertJsonPath('data.templateVersion', 'VMECC-OPS-016-R0')
            ->assertJsonPath('data.assessmentTypes.0.id', 'working-at-height')
            ->assertJsonPath('data.assessmentTypes.0.requirements.0.id', 'wah.scaffold-tagged');

        $draft = $this->postJson('/api/reports/drafts', [
            'report_type' => 'er-assessment',
            'payload' => [
                'workflowStep' => 'requirements',
                'assessmentType' => 'working-at-height',
                'company' => 'VMECC',
            ],
        ])->assertCreated();
        $draft->assertJsonPath('data.payload.schemaVersion', 1)
            ->assertJsonPath('data.payload.responses.0.requirementId', 'wah.scaffold-tagged');

        $media = ReportMedia::query()->create([
            'public_id' => 'rpm_er_assessment_layout',
            'user_id' => $user->id,
            'module' => 'er-assessment',
            'disk' => 'local',
            'storage_path' => 'report-media/er-assessment-layout.jpg',
            'original_name' => 'layout.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 100,
            'width' => 100,
            'height' => 100,
        ]);

        $create = $this->postJson('/api/reports', [
            'display_id' => 'ERA-20260827-001',
            'report_type' => 'er-assessment',
            'status' => 'Submitted',
            'source_draft_id' => $draft->json('data.draft_id'),
            'payload' => $this->validPayload($media->public_id),
        ])->assertCreated();
        $create->assertJsonPath('data.reportType', 'er-assessment')
            ->assertJsonPath('data.canDownloadPdf', true)
            ->assertJsonPath('data.recordActions.download.format', 'pdf')
            ->assertJsonPath('data.responses.0.requirementId', 'wah.scaffold-tagged');

        $reportUid = (string) $create->json('data.id');
        $this->assertDatabaseHas('report_revisions', ['revision' => 1]);
        $this->assertDatabaseHas('report_media_links', [
            'report_media_id' => $media->id,
            'parent_type' => 'report',
            'parent_key' => $reportUid,
        ]);
        $this->assertDatabaseMissing('report_drafts', ['draft_id' => $draft->json('data.draft_id')]);

        $this->actingAs($reviewer);
        $notifications = $this->getJson('/api/workflow/notifications')->assertOk();
        $notification = collect($notifications->json('data'))->first(
            fn (array $row): bool => data_get($row, 'metadata.reportType') === 'er-assessment'
        );
        $this->assertNotNull($notification);
        $this->assertTrue((bool) data_get($notification, 'actionRequiredForViewer'));
        $this->assertSame('/report/er-assessment/'.$reportUid, data_get($notification, 'deepLink'));
        $this->getJson('/api/stats/reports?period=this_month')
            ->assertOk()
            ->assertJsonPath('byType.erAssessment', 1)
            ->assertJsonPath('families.er-assessment.label', 'ER Assessment')
            ->assertJsonPath('families.er-assessment.route', '/report/er-assessment');

        $queue = $this->getJson('/api/dashboard/action-queue')->assertOk();
        $queueItem = collect($queue->json('items'))->firstWhere('key', 'reports.er-assessment.review');
        $this->assertNotNull($queueItem);
        $this->assertStringStartsWith('/report/er-assessment?', (string) data_get($queueItem, 'to'));
        $this->getJson('/api/reports/'.$reportUid)
            ->assertOk()
            ->assertJsonPath('data.canReview', true);
        $this->postJson('/api/reports/'.$reportUid.'/review', [
            'version' => 1,
            'remarks' => 'Reviewed by Incident Commander',
        ])->assertOk()->assertJsonPath('data.status', 'Reviewed');
        $this->postJson('/api/reports/'.$reportUid.'/approve', [
            'version' => 2,
            'remarks' => 'Approved for record',
        ])->assertOk()->assertJsonPath('data.status', 'Approved');

        $document = Mockery::mock(DomPdfWrapper::class);
        $document->shouldReceive('setPaper')->once()->andReturnSelf();
        $document->shouldReceive('setOption')->once()->andReturnSelf();
        $document->shouldReceive('output')->once()->andReturn('%PDF-1.4 mocked');
        Pdf::shouldReceive('loadView')
            ->once()
            ->withArgs(fn (string $view, array $data): bool => $view === 'pdf.er_assessment_report'
                && data_get($data, 'record.assessmentType') === 'working-at-height')
            ->andReturn($document);

        $this->postJson('/api/reports/er-assessment/pdf', ['report_uid' => $reportUid])
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('X-Report-Version', '3');
    }

    public function test_submission_requires_no_response_remarks_and_unknown_types_are_rejected(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->grantPermission($user);
        $this->actingAs($user);

        $payload = $this->validPayload('rpm_missing_for_validation');
        $payload['responses'][0]['response'] = 'No';
        $payload['responses'][0]['remarks'] = '';
        $this->postJson('/api/reports', [
            'display_id' => 'ERA-INVALID-001',
            'report_type' => 'er-assessment',
            'status' => 'Submitted',
            'payload' => $payload,
        ])->assertUnprocessable()->assertJsonValidationErrors(['responses.0.remarks']);

        $duplicate = $this->validPayload('rpm_missing_for_validation');
        $duplicate['responses'][1] = $duplicate['responses'][0];
        $this->postJson('/api/reports', [
            'display_id' => 'ERA-DUPLICATE-001',
            'report_type' => 'er-assessment',
            'status' => 'Submitted',
            'payload' => $duplicate,
        ])->assertUnprocessable()->assertJsonValidationErrors(['responses']);

        $this->postJson('/api/reports', [
            'display_id' => 'UNKNOWN-001',
            'report_type' => 'unregistered-module',
            'status' => 'Draft',
            'payload' => ['probe' => true],
        ])->assertUnprocessable()->assertJsonValidationErrors(['report_type']);
    }

    public function test_optional_no_response_evidence_is_linked_and_rejected_for_compliant_or_spoofed_rows(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->grantPermission($user);
        $this->actingAs($user);

        $layout = ReportMedia::query()->create([
            'public_id' => 'rpm_er_assessment_evidence_layout',
            'user_id' => $user->id,
            'module' => 'er-assessment',
            'disk' => 'local',
            'storage_path' => 'report-media/er-assessment-evidence-layout.jpg',
            'original_name' => 'layout.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 100,
            'width' => 100,
            'height' => 100,
        ]);
        $evidence = ReportMedia::query()->create([
            'public_id' => 'rpm_er_assessment_evidence_photo',
            'user_id' => $user->id,
            'module' => 'er-assessment',
            'disk' => 'local',
            'storage_path' => 'report-media/er-assessment-evidence-photo.jpg',
            'original_name' => 'device-photo.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 100,
            'width' => 100,
            'height' => 100,
        ]);

        $payload = $this->validPayload($layout->public_id);
        $payload['responses'][0]['response'] = 'No';
        $payload['responses'][0]['remarks'] = 'Scaffold tag is expired; isolate access.';
        $payload['responses'][0]['photos'] = [[
            'mediaId' => $evidence->public_id,
            'url' => '/api/report-media/'.$evidence->public_id,
            'thumbnailUrl' => '/api/report-media/'.$evidence->public_id.'?variant=thumbnail',
            'description' => 'Expired scaffold tag.',
            'mimeType' => 'image/jpeg',
            'sizeBytes' => 100,
            'width' => 100,
            'height' => 100,
            'thumbnailSizeBytes' => 100,
            'thumbnailWidth' => 100,
            'thumbnailHeight' => 100,
        ]];

        $created = $this->postJson('/api/reports', [
            'display_id' => 'ERA-EVIDENCE-001',
            'report_type' => 'er-assessment',
            'status' => 'Submitted',
            'payload' => $payload,
        ])->assertCreated();
        $this->assertDatabaseHas('report_media_links', [
            'report_media_id' => $evidence->id,
            'parent_type' => 'report',
            'parent_key' => $created->json('data.id'),
        ]);

        $nonNoPayload = $this->validPayload($layout->public_id);
        $nonNoPayload['responses'][0]['photos'] = $payload['responses'][0]['photos'];
        $this->postJson('/api/reports', [
            'display_id' => 'ERA-EVIDENCE-YES-001',
            'report_type' => 'er-assessment',
            'status' => 'Submitted',
            'payload' => $nonNoPayload,
        ])->assertUnprocessable()->assertJsonValidationErrors(['responses.0.photos']);

        $spoofedPayload = $payload;
        $spoofedPayload['responses'][0]['photos'][0]['url'] = '/api/report-media/'.$layout->public_id;
        $this->postJson('/api/reports', [
            'display_id' => 'ERA-EVIDENCE-SPOOF-001',
            'report_type' => 'er-assessment',
            'status' => 'Submitted',
            'payload' => $spoofedPayload,
        ])->assertUnprocessable()->assertJsonValidationErrors(['responses.0.photos']);
    }

    public function test_every_canonical_assessment_type_can_be_submitted(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->grantPermission($user);
        $this->actingAs($user);

        $types = collect($this->getJson('/api/reports/er-assessment/template')
            ->assertOk()
            ->json('data.assessmentTypes'));
        $this->assertSame([
            'working-at-height',
            'confined-space',
            'hot-work',
            'lifting-operations',
            'electrical-work',
        ], $types->pluck('id')->all());

        $types->values()->each(function (array $type, int $index) use ($user): void {
            $media = ReportMedia::query()->create([
                'public_id' => 'rpm_er_assessment_type_'.($index + 1),
                'user_id' => $user->id,
                'module' => 'er-assessment',
                'disk' => 'local',
                'storage_path' => 'report-media/er-assessment-type-'.($index + 1).'.jpg',
                'original_name' => 'layout-'.($index + 1).'.jpg',
                'mime_type' => 'image/jpeg',
                'size_bytes' => 100,
                'width' => 100,
                'height' => 100,
            ]);
            $payload = $this->validPayload($media->public_id);
            $payload['assessmentType'] = $type['id'];
            $payload['assessmentTypeLabel'] = $type['label'];
            $payload['worstCaseScenario'] = $type['worstCaseScenario'];
            $payload['responses'] = collect($type['requirements'])->map(fn (array $requirement): array => [
                'requirementId' => $requirement['id'],
                'requirement' => $requirement['label'],
                'response' => 'Yes',
                'remarks' => '',
            ])->all();

            $this->postJson('/api/reports', [
                'display_id' => 'ERA-TYPE-'.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT),
                'submission_key' => 'era-canonical-type-'.$type['id'],
                'report_type' => 'er-assessment',
                'status' => 'Submitted',
                'payload' => $payload,
            ])->assertCreated()
                ->assertJsonPath('data.status', 'Submitted')
                ->assertJsonPath('data.assessmentType', $type['id'])
                ->assertJsonCount(count($type['requirements']), 'data.responses');
        });

        $this->assertSame(5, Report::query()->where('report_type', 'er-assessment')->count());
    }

    public function test_submission_key_replay_rejects_status_mismatch_without_consuming_the_source_draft(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $reviewer = User::factory()->create(['status' => 'active']);
        $team = Team::factory()->create(['name' => 'ER Replay Workflow Team']);
        $this->assignWorkflowRole($user, 'Tactical Response Team', $team);
        $this->assignWorkflowRole($reviewer, 'Incident Commander', $team);
        $this->actingAs($user);

        $media = ReportMedia::query()->create([
            'public_id' => 'rpm_er_assessment_replay_layout',
            'user_id' => $user->id,
            'module' => 'er-assessment',
            'disk' => 'local',
            'storage_path' => 'report-media/er-assessment-replay-layout.jpg',
            'original_name' => 'layout.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 100,
            'width' => 100,
            'height' => 100,
        ]);
        $draft = $this->postJson('/api/reports/drafts', [
            'report_type' => 'er-assessment',
            'payload' => ['company' => 'VMECC', 'assessmentType' => 'working-at-height'],
        ])->assertCreated();
        $draftId = (string) $draft->json('data.draft_id');
        $submissionKey = 'era-replay-status-mismatch';

        $this->postJson('/api/reports', [
            'display_id' => 'ERA-REPLAY-DRAFT',
            'submission_key' => $submissionKey,
            'report_type' => 'er-assessment',
            'status' => 'Draft',
            'payload' => ['company' => 'VMECC', 'assessmentType' => 'working-at-height'],
        ])->assertCreated()->assertJsonPath('data.status', 'Draft');

        $this->postJson('/api/reports', [
            'display_id' => 'ERA-REPLAY-SUBMITTED',
            'submission_key' => $submissionKey,
            'source_draft_id' => $draftId,
            'report_type' => 'er-assessment',
            'status' => 'Submitted',
            'payload' => $this->validPayload($media->public_id),
        ])->assertConflict()
            ->assertJsonPath('code', 'REPORT_SUBMISSION_STATUS_CONFLICT')
            ->assertJsonPath('data.currentReport.status', 'Draft')
            ->assertJsonPath('data.requestedStatus', 'Submitted');

        $this->assertDatabaseHas('report_drafts', ['draft_id' => $draftId]);
        $this->assertSame(1, Report::query()->where('submission_key', $submissionKey)->count());
    }

    public function test_same_status_submission_replay_is_idempotent_and_consumes_a_late_source_draft(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $reviewer = User::factory()->create(['status' => 'active']);
        $team = Team::factory()->create(['name' => 'ER Idempotent Workflow Team']);
        $this->assignWorkflowRole($user, 'Tactical Response Team', $team);
        $this->assignWorkflowRole($reviewer, 'Incident Commander', $team);
        $this->actingAs($user);

        $media = ReportMedia::query()->create([
            'public_id' => 'rpm_er_assessment_idempotent_layout',
            'user_id' => $user->id,
            'module' => 'er-assessment',
            'disk' => 'local',
            'storage_path' => 'report-media/er-assessment-idempotent-layout.jpg',
            'original_name' => 'layout.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 100,
            'width' => 100,
            'height' => 100,
        ]);
        $submissionKey = 'era-replay-same-status';
        $create = $this->postJson('/api/reports', [
            'display_id' => 'ERA-IDEMPOTENT-001',
            'submission_key' => $submissionKey,
            'report_type' => 'er-assessment',
            'status' => 'Submitted',
            'payload' => $this->validPayload($media->public_id),
        ])->assertCreated();

        $lateDraft = $this->postJson('/api/reports/drafts', [
            'report_type' => 'er-assessment',
            'payload' => ['company' => 'VMECC', 'assessmentType' => 'working-at-height'],
        ])->assertCreated();
        $lateDraftId = (string) $lateDraft->json('data.draft_id');

        $replay = $this->postJson('/api/reports', [
            'display_id' => 'ERA-IDEMPOTENT-RETRY',
            'submission_key' => $submissionKey,
            'source_draft_id' => $lateDraftId,
            'report_type' => 'er-assessment',
            'status' => 'Submitted',
            'payload' => $this->validPayload($media->public_id),
        ])->assertOk()
            ->assertJsonPath('data.idempotent_replay', true)
            ->assertJsonPath('data.status', 'Submitted');

        $this->assertSame($create->json('data.id'), $replay->json('data.id'));
        $this->assertDatabaseMissing('report_drafts', ['draft_id' => $lateDraftId]);
        $this->assertSame(1, Report::query()->where('submission_key', $submissionKey)->count());
        $reportId = Report::query()->where('submission_key', $submissionKey)->value('id');
        $this->assertSame(1, ReportTimelineEntry::query()
            ->where('report_id', $reportId)
            ->where('action', 'Submitted')
            ->count());

        $this->actingAs($reviewer);
        $notifications = $this->getJson('/api/workflow/notifications')->assertOk();
        $submittedNotifications = collect($notifications->json('data'))->filter(
            fn (array $row): bool => data_get($row, 'metadata.reportType') === 'er-assessment'
                && data_get($row, 'eventType') === 'submitted'
                && data_get($row, 'recordDisplayId') === 'ERA-IDEMPOTENT-001'
        );
        $this->assertCount(1, $submittedNotifications);
        $this->assertTrue((bool) data_get($submittedNotifications->first(), 'actionRequiredForViewer'));
    }

    public function test_reject_resubmit_review_and_approve_complete_one_authoritative_workflow(): void
    {
        $owner = User::factory()->create(['status' => 'active']);
        $reviewer = User::factory()->create(['status' => 'active']);
        $team = Team::factory()->create(['name' => 'ER Resubmission Workflow Team']);
        $this->assignWorkflowRole($owner, 'Tactical Response Team', $team);
        $this->assignWorkflowRole($reviewer, 'Incident Commander', $team);
        $this->actingAs($owner);

        $media = ReportMedia::query()->create([
            'public_id' => 'rpm_er_assessment_resubmit_layout',
            'user_id' => $owner->id,
            'module' => 'er-assessment',
            'disk' => 'local',
            'storage_path' => 'report-media/er-assessment-resubmit-layout.jpg',
            'original_name' => 'layout.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 100,
            'width' => 100,
            'height' => 100,
        ]);
        $created = $this->postJson('/api/reports', [
            'display_id' => 'ERA-RESUBMIT-001',
            'submission_key' => 'era-resubmit-stable-key',
            'report_type' => 'er-assessment',
            'status' => 'Submitted',
            'payload' => $this->validPayload($media->public_id),
        ])->assertCreated()->assertJsonPath('data.status', 'Submitted');
        $reportUid = (string) $created->json('data.id');

        $this->actingAs($reviewer);
        $this->postJson("/api/reports/{$reportUid}/reject", [
            'version' => 1,
            'remarks' => 'Clarify the casualty route.',
        ])->assertOk()
            ->assertJsonPath('data.status', 'Rejected')
            ->assertJsonPath('data.version', 2);

        $this->actingAs($owner);
        $ownerNotifications = $this->getJson('/api/workflow/notifications')->assertOk();
        $rejected = collect($ownerNotifications->json('data'))->first(
            fn (array $row): bool => data_get($row, 'eventType') === 'rejected'
                && data_get($row, 'recordDisplayId') === 'ERA-RESUBMIT-001'
        );
        $this->assertNotNull($rejected);
        $this->assertSame('/report/er-assessment/'.$reportUid, data_get($rejected, 'deepLink'));

        $revisedPayload = $this->validPayload($media->public_id);
        $revisedPayload['rescuePlan'] .= ' Use the east access route requested by the reviewer.';
        $this->putJson("/api/reports/{$reportUid}", [
            'version' => 2,
            'status' => 'Submitted',
            'remarks' => 'Casualty route clarified.',
            'payload' => $revisedPayload,
        ])->assertOk()
            ->assertJsonPath('data.status', 'Submitted')
            ->assertJsonPath('data.version', 3)
            ->assertJsonPath('data.revision', 2);

        $this->actingAs($reviewer);
        $reviewerNotifications = $this->getJson('/api/workflow/notifications')->assertOk();
        $actionable = collect($reviewerNotifications->json('data'))->filter(
            fn (array $row): bool => data_get($row, 'metadata.reportType') === 'er-assessment'
                && data_get($row, 'recordDisplayId') === 'ERA-RESUBMIT-001'
                && (bool) data_get($row, 'actionRequiredForViewer')
        );
        $this->assertCount(1, $actionable);

        $this->postJson("/api/reports/{$reportUid}/review", [
            'version' => 3,
            'remarks' => 'Correction verified.',
        ])->assertOk()->assertJsonPath('data.status', 'Reviewed');
        $this->postJson("/api/reports/{$reportUid}/approve", [
            'version' => 4,
            'remarks' => 'Approved after correction.',
        ])->assertOk()
            ->assertJsonPath('data.status', 'Approved')
            ->assertJsonPath('data.version', 5);

        $report = Report::query()->where('report_uid', $reportUid)->firstOrFail();
        $this->assertSame('Approved', $report->status);
        $this->assertSame(2, $report->revision);
        $this->assertSame(1, $report->timelineEntries()->where('action', 'Submitted')->count());
        $this->assertSame(1, $report->timelineEntries()->where('action', 'Resubmitted')->count());
        $this->assertSame(1, $report->timelineEntries()->where('action', 'Rejected')->count());
        $this->assertSame(1, $report->timelineEntries()->where('action', 'Reviewed')->count());
        $this->assertSame(1, $report->timelineEntries()->where('action', 'Approved')->count());
    }

    private function validPayload(string $mediaId): array
    {
        $requirements = [
            ['wah.scaffold-tagged', 'Scaffold tagged & inspected (Green/Yellow/Red)'],
            ['wah.fall-protection', 'Fall protection system'],
            ['wah.anchor-body-connector', 'Anchor – Body - Connector'],
            ['wah.tool-fall-protection', 'Tool fall protection system (Lanyard / Netting / Toe board)'],
            ['wah.escape-routes', 'Escape Routes to AA'],
            ['wah.exclusion-zone', 'Barricade & exclusion zone established'],
        ];

        return [
            'assessmentType' => 'working-at-height',
            'company' => 'VMECC',
            'assessmentDate' => '2026-08-27',
            'location' => 'Process Area A',
            'scopeOfWork' => 'Elevated maintenance work',
            'responses' => collect($requirements)->map(fn (array $row): array => [
                'requirementId' => $row[0],
                'requirement' => $row[1],
                'response' => 'Yes',
                'remarks' => '',
            ])->all(),
            'rescuePlan' => 'Raise the alarm, isolate the area, and recover the casualty.',
            'rescueAccessLayout' => [
                'mediaId' => $mediaId,
                'url' => '/api/report-media/'.$mediaId,
                'name' => 'layout.jpg',
            ],
            'rescueEquipment' => ['Full-body harness', 'Rescue rope'],
            'inspectedBy' => ['name' => 'Inspector', 'company' => 'VMECC', 'signature' => 'Inspector'],
            'jobLeader' => ['name' => 'Job Leader', 'company' => 'Contractor', 'signature' => 'Job Leader'],
        ];
    }

    private function grantPermission(User $user): void
    {
        $permission = Permission::query()->firstOrCreate([
            'name' => 'reports.er_assessment.view',
            'guard_name' => 'web',
        ]);
        $role = Role::query()->firstOrCreate([
            'name' => 'ER Assessment Reporter',
            'guard_name' => 'web',
        ]);
        $role->givePermissionTo($permission);
        $user->assignRole($role);
    }

    private function assignWorkflowRole(User $user, string $roleName, Team $team): void
    {
        $permission = Permission::query()->firstOrCreate([
            'name' => 'reports.er_assessment.view',
            'guard_name' => 'web',
        ]);
        $dashboardPermission = Permission::query()->firstOrCreate([
            'name' => 'dashboard.reports.view',
            'guard_name' => 'web',
        ]);
        $role = Role::query()->firstOrCreate([
            'name' => $roleName,
            'guard_name' => 'web',
        ]);
        if (! $role->hasPermissionTo($permission)) {
            $role->givePermissionTo($permission);
        }
        if (! $role->hasPermissionTo($dashboardPermission)) {
            $role->givePermissionTo($dashboardPermission);
        }
        UserRoleAssignment::query()->create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'scope_type' => RoleCatalog::SITE,
            'team_id' => $team->id,
            'is_primary' => true,
        ]);
    }
}
