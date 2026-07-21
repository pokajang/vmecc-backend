<?php

namespace Tests\Feature;

use App\Models\InspectionCheckRow;
use App\Models\Report;
use App\Models\ReportDraft;
use App\Models\ReportMedia;
use App\Models\ReportMediaLink;
use App\Models\Team;
use App\Models\User;
use App\Models\UserRoleAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Smalot\PdfParser\Parser;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class InspectionHseInspectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_hse_v2_requires_one_observation_description_and_photo(): void
    {
        $this->actingAsInspectionUser();
        $payload = $this->version2Payload();
        $payload['hseSelections'] = ['unsafeAct', 'unsafeCondition'];
        $payload['photos'] = [];

        $response = $this->postJson('/api/reports', [
            'display_id' => 'INS-HSE-V2-INVALID',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => $payload,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['payload.hseSelections']);

        $payload['hseSelections'] = ['unsafeCondition'];
        $payload['hseUnsafeConditionDetails'] = '';
        $response = $this->postJson('/api/reports', [
            'display_id' => 'INS-HSE-V2-MISSING-DESCRIPTION',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => $payload,
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['payload.hseUnsafeConditionDetails']);

        $payload['hseUnsafeConditionDetails'] = 'Missing edge protection.';
        $response = $this->postJson('/api/reports', [
            'display_id' => 'INS-HSE-V2-MISSING-PHOTO',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => $payload,
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['payload.photos']);
    }

    public function test_hse_v2_rejects_cross_type_malformed_version_and_invalid_timestamp(): void
    {
        $this->actingAsInspectionUser();

        $crossType = $this->version2Payload();
        $crossType['incidentType'] = 'General Inspection';
        $this->postJson('/api/reports', [
            'display_id' => 'INS-HSE-V2-CROSS-TYPE',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => $crossType,
        ])->assertUnprocessable()->assertJsonValidationErrors(['payload.incidentType']);

        $malformedVersion = $this->version2Payload();
        $malformedVersion['hsePayloadVersion'] = '2-invalid';
        $this->postJson('/api/reports', [
            'display_id' => 'INS-HSE-V2-BAD-VERSION',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => $malformedVersion,
        ])->assertUnprocessable()->assertJsonValidationErrors(['payload.hsePayloadVersion']);

        $invalidTimestamp = $this->version2Payload();
        $invalidTimestamp['inspectedAt'] = '2026-02-31T25:90';
        $this->postJson('/api/reports', [
            'display_id' => 'INS-HSE-V2-BAD-TIMESTAMP',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => $invalidTimestamp,
        ])->assertUnprocessable()->assertJsonValidationErrors(['payload.inspectedAt']);
    }

    public function test_hse_v2_create_normalizes_stale_fields_and_syncs_one_analytics_row(): void
    {
        $this->actingAsInspectionUser();

        $response = $this->postJson('/api/reports', [
            'display_id' => 'INS-HSE-V2-001',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => $this->version2Payload(),
        ]);

        $response->assertCreated();
        $report = Report::query()->where('display_id', 'INS-HSE-V2-001')->firstOrFail();
        $this->assertSame(2, $report->payload['hsePayloadVersion'] ?? null);
        $this->assertSame(['unsafeCondition'], $report->payload['hseSelections'] ?? null);
        $this->assertSame('', $report->payload['hseUnsafeActDetails'] ?? null);
        $this->assertSame('', $report->payload['hseSeverity'] ?? null);
        $this->assertSame(1, InspectionCheckRow::query()->where('report_id', $report->id)->count());
        $this->assertDatabaseHas('inspection_check_rows', [
            'report_id' => $report->id,
            'check_key' => 'unsafe-condition',
            'check_value' => 'Unsafe Condition',
            'has_defect' => true,
            'evidence_count' => 1,
            'source_payload_key' => 'hseSelections',
        ]);
    }

    public function test_hse_v2_create_is_idempotent_listed_and_included_in_summary(): void
    {
        $user = $this->actingAsInspectionUser();
        $user->forceFill(['name' => 'Verified HSE Inspector'])->save();
        $payload = $this->version2Payload();
        $payload['hseInspectedBy'] = 'Spoofed Inspector';
        $payload['checklist'] = [[
            'id' => 'hse-v2:observation',
            'label' => 'HSE v2 observation',
            'inspectionType' => 'Health Safety Environment Inspection',
            'selected' => true,
        ]];
        $request = [
            'display_id' => 'INS-HSE-V2-IDEMPOTENT',
            'submission_key' => 'hse-v2-idempotent-key',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => $payload,
        ];

        $created = $this->postJson('/api/reports', $request)
            ->assertCreated()
            ->assertJsonPath('data.idempotent_replay', false)
            ->assertJsonPath('data.hseInspectedBy', 'Verified HSE Inspector');
        $reportUid = (string) $created->json('data.id');

        $this->postJson('/api/reports', $request)
            ->assertOk()
            ->assertJsonPath('data.id', $reportUid)
            ->assertJsonPath('data.idempotent_replay', true);
        $this->assertDatabaseCount('reports', 1);
        $this->assertDatabaseCount('inspection_check_rows', 1);

        $this->getJson('/api/reports?reportType=inspection')
            ->assertOk()
            ->assertJsonFragment(['id' => $reportUid, 'displayId' => 'INS-HSE-V2-IDEMPOTENT']);

        $this->getJson('/api/reports/inspection/checklist-summary?'.http_build_query([
            'inspection_type' => 'Health Safety Environment Inspection',
            'checklist_item' => 'HSE v2 observation',
        ]))->assertOk()
            ->assertJsonPath('data.totalReports', 1)
            ->assertJsonPath('data.withChecklist', 1)
            ->assertJsonPath('data.items.0.label', 'HSE v2 observation')
            ->assertJsonPath('data.items.0.count', 1);
    }

    public function test_hse_version_zero_does_not_apply_hse_rules_to_another_inspection_type(): void
    {
        $this->actingAsInspectionUser();

        $this->postJson('/api/reports', [
            'display_id' => 'INS-GENERAL-HSE-VERSION-ZERO',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => [
                'incidentType' => 'General Inspection',
                'hsePayloadVersion' => 0,
                'location' => 'Zone A',
                'selectedLocation' => 'Zone A',
                'description' => 'General inspection finding.',
                'photos' => [],
                'inspectionIssues' => [[
                    'id' => 'general-issue-1',
                    'description' => 'Blocked access route.',
                    'actionRequired' => 'Remove the obstruction.',
                    'photos' => [[
                        'id' => 'general-photo-1',
                        'fileName' => 'blocked-route.png',
                        'url' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScLx1QAAAABJRU5ErkJggg==',
                    ]],
                ]],
            ],
        ])->assertCreated()
            ->assertJsonMissingPath('data.hsePayloadVersion');
    }

    public function test_hse_v2_draft_accepts_partial_observation_and_preserves_version(): void
    {
        $this->actingAsInspectionUser();

        $response = $this->postJson('/api/reports/draft', [
            'report_type' => 'inspection',
            'payload' => [
                'incidentType' => 'Health Safety Environment Inspection',
                'hsePayloadVersion' => 2,
                'hseSelections' => [],
                'photos' => [],
            ],
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.payload.hsePayloadVersion', 2);
        $response->assertJsonPath('data.payload.hseSelections', []);

        $this->getJson('/api/reports/draft?report_type=inspection')
            ->assertOk()
            ->assertJsonPath('data.draft_id', $response->json('data.draft_id'))
            ->assertJsonPath('data.payload.hsePayloadVersion', 2);

        $this->deleteJson('/api/reports/draft?report_type=inspection')->assertOk();
        $this->getJson('/api/reports/draft?report_type=inspection')
            ->assertOk()
            ->assertJsonPath('data', null);
        $this->assertDatabaseCount('report_drafts', 0);
    }

    public function test_hse_v2_draft_full_lifecycle_enforces_versions_and_releases_media(): void
    {
        $user = $this->actingAsInspectionUser();
        [$media, $photo] = $this->managedPhoto($user, 'hse-v2-draft-lifecycle');
        $payload = $this->version2Payload();
        $payload['photos'] = [$photo];

        $created = $this->postJson('/api/reports/drafts', [
            'report_type' => 'inspection',
            'create_new' => true,
            'payload' => $payload,
        ])->assertCreated();
        $draftId = (string) $created->json('data.draft_id');

        $this->getJson('/api/reports/drafts?report_type=inspection')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.draft_id', $draftId);

        $this->getJson('/api/reports/drafts/'.$draftId)
            ->assertOk()
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.payload.hsePayloadVersion', 2)
            ->assertJsonPath('data.payload.photos.0.mediaId', $media->public_id);
        $this->assertDatabaseHas('report_media_links', [
            'report_media_id' => $media->id,
            'parent_type' => 'report_draft',
            'parent_key' => $draftId,
        ]);

        $payload['hseSelections'] = ['unsafeAct'];
        $payload['hseUnsafeActDetails'] = 'Worker stepped across the barricade.';
        $payload['hseUnsafeConditionDetails'] = 'Stale condition details.';
        $this->putJson('/api/reports/drafts/'.$draftId, [
            'base_version' => 1,
            'payload' => $payload,
        ])->assertOk()
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.payload.hseSelections.0', 'unsafeAct')
            ->assertJsonPath('data.payload.hseUnsafeConditionDetails', '');

        $this->putJson('/api/reports/drafts/'.$draftId, [
            'base_version' => 1,
            'payload' => $this->version2Payload(),
        ])->assertConflict()
            ->assertJsonPath('code', 'report_draft_version_conflict')
            ->assertJsonPath('currentDraft.version', 2)
            ->assertJsonPath('currentDraft.payload.hseSelections.0', 'unsafeAct');

        $this->deleteJson('/api/reports/drafts/'.$draftId)->assertOk();
        $this->getJson('/api/reports/drafts/'.$draftId)->assertNotFound();
        $this->assertDatabaseMissing('report_drafts', ['draft_id' => $draftId]);
        $this->assertDatabaseMissing('report_media_links', [
            'report_media_id' => $media->id,
            'parent_type' => 'report_draft',
            'parent_key' => $draftId,
        ]);
    }

    public function test_hse_submission_consumes_resumed_draft_after_relinking_its_media(): void
    {
        $user = $this->actingAsInspectionUser();
        [$media, $photo] = $this->managedPhoto($user, 'hse-resumed-draft-submit');
        $payload = $this->version2Payload();
        $payload['photos'] = [$photo];

        $draft = $this->postJson('/api/reports/drafts', [
            'report_type' => 'inspection',
            'create_new' => true,
            'payload' => $payload,
        ])->assertCreated();
        $draftId = (string) $draft->json('data.draft_id');

        $submitted = $this->postJson('/api/reports', [
            'display_id' => 'INS-HSE-RESUMED-001',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'source_draft_id' => $draftId,
            'payload' => $payload,
        ])->assertCreated();
        $reportUid = (string) $submitted->json('data.id');

        $this->assertDatabaseMissing('report_drafts', ['draft_id' => $draftId]);
        $this->assertDatabaseMissing('report_media_links', [
            'report_media_id' => $media->id,
            'parent_type' => 'report_draft',
            'parent_key' => $draftId,
        ]);
        $this->assertDatabaseHas('report_media_links', [
            'report_media_id' => $media->id,
            'parent_type' => 'report',
            'parent_key' => $reportUid,
        ]);
    }

    public function test_hse_v2_report_can_be_read_updated_and_soft_deleted_with_analytics(): void
    {
        $user = $this->actingAsInspectionUser();
        [$media, $photo] = $this->managedPhoto($user, 'hse-v2-report-lifecycle');
        $createPayload = $this->version2Payload();
        $createPayload['photos'] = [$photo];
        $created = $this->postJson('/api/reports', [
            'display_id' => 'INS-HSE-V2-LIFECYCLE',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => $createPayload,
        ])->assertCreated();

        $reportUid = (string) $created->json('data.id');
        $version = (int) $created->json('data.version');
        $this->getJson('/api/reports/'.$reportUid)
            ->assertOk()
            ->assertJsonPath('data.hsePayloadVersion', 2)
            ->assertJsonPath('data.hseSelections.0', 'unsafeCondition');
        $this->assertDatabaseHas('report_media_links', [
            'report_media_id' => $media->id,
            'parent_type' => 'report',
            'parent_key' => $reportUid,
        ]);

        $updatedPayload = $this->version2Payload();
        $updatedPayload['photos'] = [$photo];
        $updatedPayload['hseSelections'] = ['unsafeAct'];
        $updatedPayload['hseUnsafeActDetails'] = 'Worker crossed an active barricade.';
        $updatedPayload['hseUnsafeConditionDetails'] = 'Stale condition description.';
        $this->putJson('/api/reports/'.$reportUid, [
            'version' => $version,
            'status' => 'Submitted',
            'payload' => $updatedPayload,
        ])->assertOk()
            ->assertJsonPath('data.version', $version + 1)
            ->assertJsonPath('data.hseSelections.0', 'unsafeAct')
            ->assertJsonPath('data.hseUnsafeConditionDetails', '');

        $this->putJson('/api/reports/'.$reportUid, [
            'version' => $version,
            'status' => 'Submitted',
            'payload' => $updatedPayload,
        ])->assertConflict()
            ->assertJsonPath('code', 'REPORT_VERSION_CONFLICT')
            ->assertJsonPath('currentVersion', $version + 1);

        $report = Report::query()->where('report_uid', $reportUid)->firstOrFail();
        $this->assertDatabaseHas('inspection_check_rows', [
            'report_id' => $report->id,
            'check_key' => 'unsafe-act',
            'check_value' => 'Unsafe Act',
        ]);
        $this->assertDatabaseMissing('inspection_check_rows', [
            'report_id' => $report->id,
            'check_key' => 'unsafe-condition',
            'deleted_at' => null,
        ]);

        $this->deleteJson('/api/reports/'.$reportUid)->assertNoContent();
        $this->assertSoftDeleted('reports', ['id' => $report->id]);
        $this->assertSame(0, InspectionCheckRow::query()->where('report_id', $report->id)->count());
        $this->assertSame(1, InspectionCheckRow::onlyTrashed()->where('report_id', $report->id)->count());
        $this->assertSame(0, ReportMediaLink::query()
            ->where('parent_type', 'report')
            ->where('parent_key', $reportUid)
            ->count());
    }

    public function test_hse_v2_cross_user_reads_follow_module_permissions_without_granting_media_reuse(): void
    {
        $owner = $this->actingAsInspectionUser();
        [$media, $photo] = $this->managedPhoto($owner, 'hse-v2-owner-only');
        $payload = $this->version2Payload();
        $payload['photos'] = [$photo];
        $created = $this->postJson('/api/reports', [
            'display_id' => 'INS-HSE-V2-OWNER-ONLY',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => $payload,
        ])->assertCreated();
        $reportUid = (string) $created->json('data.id');

        $moduleViewer = User::factory()->create(['status' => 'active']);
        $this->grantPermission($moduleViewer, 'reports.inspection.view');
        $this->actingAs($moduleViewer);

        $this->getJson('/api/reports/'.$reportUid)
            ->assertOk()
            ->assertJsonPath('data.id', $reportUid);
        $this->postJson('/api/reports/inspection/pdf', [
            'report_uid' => $reportUid,
            'version' => 1,
        ])->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->get('/api/report-media/'.$media->public_id)
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg');

        $this->postJson('/api/reports', [
            'display_id' => 'INS-HSE-V2-UNAUTHORIZED-MEDIA',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => $payload,
        ])->assertUnprocessable()->assertJsonValidationErrors(['photos']);
        $this->assertDatabaseMissing('reports', ['display_id' => 'INS-HSE-V2-UNAUTHORIZED-MEDIA']);
        $this->assertSame(1, ReportMediaLink::query()
            ->where('report_media_id', $media->id)
            ->where('parent_type', 'report')
            ->count());

        $unauthorizedUser = User::factory()->create(['status' => 'active']);
        $this->actingAs($unauthorizedUser);
        $this->getJson('/api/reports/'.$reportUid)->assertForbidden();
        $this->postJson('/api/reports/inspection/pdf', [
            'report_uid' => $reportUid,
            'version' => 1,
        ])->assertForbidden();
        $this->get('/api/report-media/'.$media->public_id)->assertNotFound();
    }

    public function test_hse_v2_workflow_and_real_pdf_endpoint_preserve_the_lean_report(): void
    {
        $team = Team::factory()->create(['name' => 'HSE Alpha Team']);
        $otherTeam = Team::factory()->create(['name' => 'HSE Bravo Team']);
        $submitter = User::factory()->create(['status' => 'active', 'name' => 'HSE Submitter']);
        $reviewer = User::factory()->create(['status' => 'active', 'name' => 'HSE AIC Reviewer']);
        $otherReviewer = User::factory()->create(['status' => 'active', 'name' => 'Other HSE AIC']);
        $approver = User::factory()->create(['status' => 'active', 'name' => 'HSE Incident Commander']);
        $this->assignWorkflowRole($submitter, 'Tactical Response Team', $team->id, true);
        $this->assignWorkflowRole($reviewer, 'Assistant Incident Commander', $team->id);
        $this->assignWorkflowRole($otherReviewer, 'Assistant Incident Commander', $otherTeam->id);
        $this->assignWorkflowRole($approver, 'Incident Commander');

        $this->actingAs($submitter);
        $created = $this->postJson('/api/reports', [
            'display_id' => 'INS-HSE-V2-WORKFLOW',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => $this->version2Payload(),
        ])->assertCreated()
            ->assertJsonPath('data.workflowStage', 'review')
            ->assertJsonPath('data.nextActionRole', 'Assistant Incident Commander');
        $reportUid = (string) $created->json('data.id');

        $this->actingAs($otherReviewer);
        $this->postJson('/api/reports/'.$reportUid.'/review', ['version' => 1])
            ->assertForbidden();

        $this->actingAs($reviewer);
        $this->postJson('/api/reports/'.$reportUid.'/review', [
            'version' => 1,
            'remarks' => 'HSE observation verified.',
        ])->assertOk()
            ->assertJsonPath('data.status', 'Reviewed')
            ->assertJsonPath('data.version', 2);

        $this->actingAs($approver);
        $approved = $this->postJson('/api/reports/'.$reportUid.'/approve', [
            'version' => 2,
            'remarks' => 'HSE report approved.',
        ])->assertOk()
            ->assertJsonPath('data.status', 'Approved')
            ->assertJsonPath('data.version', 3);

        $this->actingAs($submitter);
        $pdf = $this->postJson('/api/reports/inspection/pdf', [
            'report_uid' => $reportUid,
            'version' => $approved->json('data.version'),
        ])->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());
        $text = (new Parser)->parseContent($pdf->getContent())->getText();
        $this->assertStringContainsString('Unsafe Condition', $text);
        $this->assertStringContainsString('Open edge is missing protection.', $text);
        $this->assertStringContainsString('Stopped access and installed a temporary barrier.', $text);
        $this->assertStringContainsString('HSE AIC Reviewer', $text);
        $this->assertStringContainsString('HSE Incident Commander', $text);
        $this->assertStringNotContainsString('Critical', $text);
        $this->assertStringNotContainsString('Stale act description.', $text);
    }

    public function test_hse_area_satisfactory_payload_is_accepted_without_photos(): void
    {
        $this->actingAsInspectionUser();

        $response = $this->postJson('/api/reports', [
            'display_id' => 'INS-HSE-AREA-001',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => $this->areaSatisfactoryPayload(),
        ]);

        $response->assertCreated();

        $report = Report::query()->where('display_id', 'INS-HSE-AREA-001')->firstOrFail();
        $this->assertSame(['areaSatisfactory'], $report->payload['hseSelections'] ?? null);
        $this->assertSame('Area housekeeping is satisfactory.', $report->payload['hseAreaConditionRemarks'] ?? null);
        $this->assertDatabaseHas('inspection_check_rows', [
            'report_id' => $report->id,
            'inspection_type_key' => 'health-safety-environment-inspection',
            'check_key' => 'area-satisfactory',
            'check_value' => 'Area Satisfactory',
            'has_defect' => false,
            'source_payload_key' => 'hseSelections',
        ]);
    }

    public function test_hse_finding_payload_requires_selected_details_and_severity(): void
    {
        $this->actingAsInspectionUser();
        $payload = $this->findingPayload();
        unset($payload['hseSeverity'], $payload['hseUnsafeConditionDetails']);

        $response = $this->postJson('/api/reports', [
            'display_id' => 'INS-HSE-FINDING-INVALID',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => $payload,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['payload.hseSeverity']);

        $payload['hseSeverity'] = 'Critical';
        $response = $this->postJson('/api/reports', [
            'display_id' => 'INS-HSE-FINDING-INVALID-DETAIL',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => $payload,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['payload.hseUnsafeConditionDetails']);
    }

    public function test_hse_finding_payload_creates_analytics_rows_for_selected_findings(): void
    {
        $this->actingAsInspectionUser();

        $response = $this->postJson('/api/reports', [
            'display_id' => 'INS-HSE-FINDING-001',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => $this->findingPayload(),
        ]);

        $response->assertCreated();
        $report = Report::query()->where('display_id', 'INS-HSE-FINDING-001')->firstOrFail();
        $this->assertSame(2, InspectionCheckRow::query()->where('report_id', $report->id)->count());
        $this->assertDatabaseHas('inspection_check_rows', [
            'report_id' => $report->id,
            'check_key' => 'unsafe-act',
            'check_value' => 'Critical',
            'has_defect' => true,
            'equipment_source' => 'report',
        ]);
        $this->assertDatabaseHas('inspection_check_rows', [
            'report_id' => $report->id,
            'check_key' => 'unsafe-condition',
            'check_value' => 'Critical',
            'has_defect' => true,
            'equipment_source' => 'report',
        ]);
    }

    public function test_hse_draft_persists_incomplete_payload_safely(): void
    {
        $this->actingAsInspectionUser();

        $response = $this->postJson('/api/reports/draft', [
            'report_type' => 'inspection',
            'payload' => [
                'incidentType' => 'Health Safety Environment Inspection',
                'location' => 'Zone A',
                'mainLocation' => 'Zone A',
                'hse_selections' => ['Unsafe Act'],
                'hse_unsafe_act_details' => 'Draft unsafe act note.',
                'hse_severity' => '',
                'photos' => [],
            ],
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.payload.hseSelections.0', 'unsafeAct');
        $response->assertJsonPath('data.payload.hseUnsafeActDetails', 'Draft unsafe act note.');
        $response->assertJsonPath('data.payload.hseSeverity', '');
        $this->assertSame(0, InspectionCheckRow::query()->count());

        $draft = ReportDraft::query()->where('report_type', 'inspection')->firstOrFail();
        $this->assertSame(['unsafeAct'], $draft->payload['hseSelections'] ?? null);
        $this->assertArrayNotHasKey('hse_selections', $draft->payload);
    }

    private function areaSatisfactoryPayload(): array
    {
        return [
            'incidentType' => 'Health Safety Environment Inspection',
            'location' => 'Zone A',
            'selectedLocation' => 'Zone A',
            'mainLocation' => 'Zone A',
            'description' => 'HSE inspection for Zone A: Area Satisfactory.',
            'photos' => [],
            'hseInspectedBy' => 'Inspector HSE',
            'hseInspectionDate' => '2026-06-29',
            'hseSelections' => ['areaSatisfactory'],
            'hseAreaConditionRemarks' => 'Area housekeeping is satisfactory.',
        ];
    }

    private function findingPayload(): array
    {
        return [
            'incidentType' => 'Health Safety Environment Inspection',
            'location' => 'Zone A > Dock',
            'selectedLocation' => 'Zone A > Dock',
            'mainLocation' => 'Zone A',
            'subLocation' => 'Dock',
            'description' => 'HSE inspection found unsafe act and unsafe condition.',
            'photos' => [],
            'hseInspectedBy' => 'Inspector HSE',
            'hseInspectionDate' => '2026-06-29',
            'hseSelections' => ['unsafeAct', 'unsafeCondition'],
            'hseUnsafeActDetails' => 'Worker crossed active barricade.',
            'hseUnsafeConditionDetails' => 'Open trench missing edge protection.',
            'hseSeverity' => 'Critical',
            'hseImmediateAction' => 'Stopped work and reinstated barricade.',
            'hseCorrectiveAction' => 'Brief contractor team before restart.',
            'hseResponsiblePerson' => 'Area Supervisor',
            'hseTargetDate' => '2026-06-30',
        ];
    }

    private function version2Payload(): array
    {
        return [
            'incidentType' => 'Health Safety Environment Inspection',
            'hsePayloadVersion' => 2,
            'location' => 'Zone A > Dock',
            'selectedLocation' => 'Zone A > Dock',
            'mainLocation' => 'Zone A',
            'subLocation' => 'Dock',
            'inspectedAt' => '2026-07-14T09:30:00+08:00',
            'hseInspectionDate' => '2026-07-14',
            'hseSelections' => ['unsafeCondition'],
            'hseUnsafeActDetails' => 'Stale act description.',
            'hseUnsafeConditionDetails' => 'Open edge is missing protection.',
            'hseSeverity' => 'Critical',
            'hseImmediateAction' => 'Stopped access and installed a temporary barrier.',
            'photos' => [[
                'fileName' => 'observation.png',
                'description' => 'Missing edge protection',
                'url' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScLx1QAAAABJRU5ErkJggg==',
            ]],
        ];
    }

    /**
     * @return array{0: ReportMedia, 1: array<string, mixed>}
     */
    private function managedPhoto(User $user, string $publicId): array
    {
        $media = ReportMedia::query()->create([
            'public_id' => $publicId,
            'client_upload_id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'module' => 'inspection',
            'disk' => 'local',
            'storage_path' => 'report-media/'.$publicId.'.jpg',
            'thumbnail_path' => 'report-media/'.$publicId.'-thumb.jpg',
            'original_name' => 'observation.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'thumbnail_size_bytes' => 256,
            'width' => 1200,
            'height' => 900,
            'thumbnail_width' => 320,
            'thumbnail_height' => 240,
            'checksum_sha256' => hash('sha256', $publicId),
            'thumbnail_checksum_sha256' => hash('sha256', $publicId.'-thumb'),
        ]);
        Storage::disk('local')->put($media->storage_path, 'hse-observation-image');
        Storage::disk('local')->put($media->thumbnail_path, 'hse-observation-thumbnail');

        return [$media, [
            'id' => 'photo-'.$publicId,
            'mediaId' => $publicId,
            'fileName' => 'observation.jpg',
            'description' => 'HSE observation evidence.',
            'url' => '/api/report-media/'.$publicId,
            'thumbnailUrl' => '/api/report-media/'.$publicId.'?variant=thumbnail',
            'mimeType' => 'image/jpeg',
            'sizeBytes' => 1024,
            'width' => 1200,
            'height' => 900,
        ]];
    }

    private function actingAsInspectionUser(): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->grantPermission($user, 'reports.inspection.view');
        $this->grantPermission($user, 'reports.inspection.conduct');
        $this->actingAs($user);

        return $user;
    }

    private function assignWorkflowRole(
        User $user,
        string $roleName,
        ?int $teamId = null,
        bool $primary = false,
    ): void {
        $role = Role::query()->firstOrCreate([
            'name' => $roleName,
            'guard_name' => 'web',
        ]);
        foreach (['reports.inspection.view', 'reports.inspection.conduct'] as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName, 'guard_name' => 'web']);
            if (! $role->hasPermissionTo($permission)) {
                $role->givePermissionTo($permission);
            }
        }

        UserRoleAssignment::query()->create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'scope_type' => $teamId ? 'site' : 'global',
            'team_id' => $teamId,
            'is_primary' => $primary,
        ]);
    }

    private function grantPermission(User $user, string $permissionName): void
    {
        $permission = Permission::query()->firstOrCreate([
            'name' => $permissionName,
            'guard_name' => 'web',
        ]);
        $role = Role::query()->firstOrCreate([
            'name' => 'HSE Inspection Tester',
            'guard_name' => 'web',
        ]);
        if (! $role->hasPermissionTo($permission)) {
            $role->givePermissionTo($permission);
        }
        $user->assignRole($role);
    }
}
