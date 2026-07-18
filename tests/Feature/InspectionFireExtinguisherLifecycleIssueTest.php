<?php

namespace Tests\Feature;

use App\Models\InspectionFireExtinguisher;
use App\Models\InspectionFireExtinguisherIssue;
use App\Models\InspectionFireExtinguisherIssueOccurrence;
use App\Models\Report;
use App\Models\ReportMedia;
use App\Models\ReportMediaLink;
use App\Models\User;
use App\Services\InspectionCheckRowSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class InspectionFireExtinguisherLifecycleIssueTest extends TestCase
{
    use RefreshDatabase;

    public function test_view_permission_cannot_mutate_catalogue(): void
    {
        $user = $this->userWithPermissions(['reports.inspection.view']);
        $asset = $this->asset();

        $this->actingAs($user)->getJson('/api/inspection/fire-extinguishers')->assertOk();
        $this->patchJson("/api/inspection/fire-extinguishers/{$asset->id}", [
            'mainLocation' => 'Changed',
        ])->assertForbidden();
        $this->postJson("/api/inspection/fire-extinguishers/{$asset->id}/retire", [
            'reason' => 'Unauthorized retirement',
        ])->assertForbidden();
        $this->postJson('/api/inspection/sessions', [
            'inspectionType' => 'Fire Extinguisher Inspection',
        ])->assertForbidden();
        $this->postJson('/api/reports', [
            'display_id' => 'INS-READ-ONLY',
            'report_type' => 'inspection',
            'payload' => ['incidentType' => 'General Inspection'],
        ])->assertForbidden();
    }

    public function test_catalogue_manager_can_change_and_restore_lifecycle_with_audit_history(): void
    {
        $user = $this->userWithPermissions(['reports.inspection.view', 'reports.inspection.extinguishers.manage']);
        $asset = $this->asset();
        $this->actingAs($user);

        $out = $this->postJson("/api/inspection/fire-extinguishers/{$asset->id}/out-of-service", [
            'reason' => 'Awaiting service', 'lockVersion' => 1,
        ])->assertOk()->assertJsonPath('data.lifecycleStatus', 'out_of_service');

        $returned = $this->postJson("/api/inspection/fire-extinguishers/{$asset->id}/return-to-service", [
            'lockVersion' => $out->json('data.lockVersion'),
        ])->assertOk()->assertJsonPath('data.lifecycleStatus', 'active');

        $retired = $this->postJson("/api/inspection/fire-extinguishers/{$asset->id}/retire", [
            'reason' => 'End of service life', 'lockVersion' => $returned->json('data.lockVersion'),
        ])->assertOk()->assertJsonPath('data.lifecycleStatus', 'retired');

        $this->postJson("/api/inspection/fire-extinguishers/{$asset->id}/restore", [
            'lockVersion' => $retired->json('data.lockVersion'),
        ])->assertOk()->assertJsonPath('data.lifecycleStatus', 'active');

        $this->assertDatabaseHas('audit_logs', ['action' => 'fire_extinguisher_retired']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'fire_extinguisher_restored']);
    }

    public function test_out_of_service_asset_is_not_available_for_inspection_and_rejects_stale_transitions(): void
    {
        $user = $this->userWithPermissions(['reports.inspection.view', 'reports.inspection.extinguishers.manage']);
        $asset = $this->asset();
        $this->actingAs($user);

        $this->postJson("/api/inspection/fire-extinguishers/{$asset->id}/out-of-service", [
            'reason' => 'Discharged', 'lockVersion' => 1,
        ])->assertOk()->assertJsonPath('data.lifecycleStatus', 'out_of_service');

        $this->getJson('/api/inspection/fire-extinguishers/lookup?locator=LIFE-BAR-001')->assertNotFound();
        $coverage = $this->getJson('/api/inspection/fire-extinguishers/coverage')->assertOk();
        $this->assertNull(collect($coverage->json('data'))->firstWhere('catalogId', $asset->id));
        $this->assertNotContains($asset->zone, $coverage->json('meta.options.zones'));
        $this->postJson("/api/inspection/fire-extinguishers/{$asset->id}/out-of-service", [
            'reason' => 'Duplicate transition', 'lockVersion' => 1,
        ])->assertStatus(409);
    }

    public function test_restoring_an_out_of_service_retirement_clears_all_service_state(): void
    {
        $user = $this->userWithPermissions(['reports.inspection.view', 'reports.inspection.extinguishers.manage']);
        $asset = $this->asset();
        $this->actingAs($user);

        $out = $this->postJson("/api/inspection/fire-extinguishers/{$asset->id}/out-of-service", [
            'reason' => 'Cylinder damage', 'lockVersion' => 1,
        ])->assertOk();
        $retired = $this->postJson("/api/inspection/fire-extinguishers/{$asset->id}/retire", [
            'reason' => 'Replacement approved', 'lockVersion' => $out->json('data.lockVersion'),
        ])->assertOk();
        $this->postJson("/api/inspection/fire-extinguishers/{$asset->id}/restore", [
            'lockVersion' => $retired->json('data.lockVersion'),
        ])->assertOk()->assertJsonPath('data.lifecycleStatus', 'active');

        $restored = $asset->fresh();
        $this->assertNull($restored->out_of_service_at);
        $this->assertNull($restored->out_of_service_by);
        $this->assertNull($restored->out_of_service_reason);
    }

    public function test_defect_sync_opens_one_issue_and_good_inspection_does_not_silently_close_it(): void
    {
        $user = $this->userWithPermissions(['reports.inspection.view']);
        $asset = $this->asset();
        $report = $this->report($user, $asset, 'Not Good', 'Gauge failed');

        app(InspectionCheckRowSyncService::class)->syncForReport($report, $user->id);

        $issue = InspectionFireExtinguisherIssue::query()->firstOrFail();
        $this->assertSame('open', $issue->status);
        $this->assertSame('operational-condition', $issue->check_key);
        $this->assertSame(1, InspectionFireExtinguisherIssueOccurrence::query()->count());

        app(InspectionCheckRowSyncService::class)->syncForReport($report, $user->id);
        $this->assertSame(1, InspectionFireExtinguisherIssue::query()->count());
        $this->assertSame(1, InspectionFireExtinguisherIssueOccurrence::query()->count());

        $payload = $report->payload;
        $payload['fireExtinguisherChecks'][0]['operationalCondition'] = 'Good';
        $payload['fireExtinguisherChecks'][0]['operationalConditionRemarks'] = '';
        $report->update(['payload' => $payload]);
        app(InspectionCheckRowSyncService::class)->syncForReport($report->fresh(), $user->id);

        $this->assertSame('open', $issue->fresh()->status);
        $this->assertDatabaseHas('inspection_fire_extinguisher_issue_events', [
            'issue_id' => $issue->id, 'event_type' => 'condition_now_good',
        ]);
    }

    public function test_defect_sync_does_not_recreate_issues_for_a_retired_asset(): void
    {
        $user = $this->userWithPermissions(['reports.inspection.view', 'reports.inspection.extinguishers.manage']);
        $asset = $this->asset();
        $report = $this->report($user, $asset, 'Not Good', 'Historic defect');

        $this->actingAs($user)->postJson("/api/inspection/fire-extinguishers/{$asset->id}/retire", [
            'reason' => 'Removed from service', 'lockVersion' => 1,
        ])->assertOk();
        app(InspectionCheckRowSyncService::class)->syncForReport($report, $user->id);

        $this->assertDatabaseCount('inspection_fire_extinguisher_issues', 0);
        $this->assertDatabaseCount('inspection_fire_extinguisher_issue_occurrences', 0);
    }

    public function test_issue_can_be_assigned_resolved_verified_and_reopened(): void
    {
        $user = $this->userWithPermissions([
            'reports.inspection.view', 'reports.inspection.issues.manage',
        ]);
        $verifier = $this->userWithPermissions([
            'reports.inspection.view', 'reports.inspection.issues.verify',
        ]);
        $asset = $this->asset();
        $report = $this->report($user, $asset, 'Not Good', 'Gauge failed');
        app(InspectionCheckRowSyncService::class)->syncForReport($report, $user->id);
        $issue = InspectionFireExtinguisherIssue::query()->firstOrFail();
        $this->actingAs($user);

        $assigned = $this->postJson("/api/inspection/fire-extinguisher-issues/{$issue->id}/assign", [
            'assignedToUserId' => $user->id, 'lockVersion' => $issue->lock_version,
        ])->assertOk();
        $started = $this->postJson("/api/inspection/fire-extinguisher-issues/{$issue->id}/start", [
            'lockVersion' => $assigned->json('data.lockVersion'),
        ])->assertOk()->assertJsonPath('data.status', 'in_progress');
        $media = ReportMedia::query()->create([
            'public_id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'module' => 'inspection',
            'disk' => 'local',
            'storage_path' => 'test/evidence.jpg',
            'original_name' => 'evidence.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 100,
            'width' => 10,
            'height' => 10,
        ]);
        $resolved = $this->postJson("/api/inspection/fire-extinguisher-issues/{$issue->id}/resolve", [
            'correctiveAction' => 'Replaced pressure gauge',
            'resolutionNotes' => 'Pressure test passed',
            'resolutionPhotos' => [['mediaId' => $media->public_id]],
            'lockVersion' => $started->json('data.lockVersion'),
        ])->assertOk()->assertJsonPath('data.status', 'pending_verification')
            ->assertJsonPath('data.resolutionEvidence.0.mediaId', $media->public_id);
        $this->assertTrue(ReportMediaLink::query()
            ->where('report_media_id', $media->id)
            ->where('parent_type', 'fire_extinguisher_issue_resolution')
            ->where('parent_key', $issue->public_id)
            ->exists());
        $this->actingAs($verifier);
        $verified = $this->postJson("/api/inspection/fire-extinguisher-issues/{$issue->id}/verify", [
            'note' => 'Verified on site', 'lockVersion' => $resolved->json('data.lockVersion'),
        ])->assertOk()->assertJsonPath('data.status', 'closed');
        $this->actingAs($user)->postJson("/api/inspection/fire-extinguisher-issues/{$issue->id}/reopen", [
            'note' => 'Defect returned', 'lockVersion' => $verified->json('data.lockVersion'),
        ])->assertOk()->assertJsonPath('data.status', 'open');
    }

    public function test_resolver_cannot_verify_their_own_corrective_work(): void
    {
        $user = $this->userWithPermissions([
            'reports.inspection.view', 'reports.inspection.issues.manage', 'reports.inspection.issues.verify',
        ]);
        $asset = $this->asset();
        $report = $this->report($user, $asset, 'Not Good', 'Gauge failed');
        app(InspectionCheckRowSyncService::class)->syncForReport($report, $user->id);
        $issue = InspectionFireExtinguisherIssue::query()->firstOrFail();
        $this->actingAs($user);

        $resolved = $this->postJson("/api/inspection/fire-extinguisher-issues/{$issue->id}/resolve", [
            'correctiveAction' => 'Replaced pressure gauge',
            'resolutionNotes' => 'Pressure test passed',
            'resolutionPhotos' => [],
            'lockVersion' => $issue->lock_version,
        ])->assertOk();

        $this->postJson("/api/inspection/fire-extinguisher-issues/{$issue->id}/verify", [
            'note' => 'Self verified',
            'lockVersion' => $resolved->json('data.lockVersion'),
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('verifier');
        $this->assertSame('pending_verification', $issue->fresh()->status);
    }

    public function test_issue_actions_reject_a_stale_client_version(): void
    {
        $user = $this->userWithPermissions([
            'reports.inspection.view', 'reports.inspection.issues.manage',
        ]);
        $asset = $this->asset();
        $report = $this->report($user, $asset, 'Not Good', 'Gauge failed');
        app(InspectionCheckRowSyncService::class)->syncForReport($report, $user->id);
        $issue = InspectionFireExtinguisherIssue::query()->firstOrFail();

        $this->actingAs($user)->postJson("/api/inspection/fire-extinguisher-issues/{$issue->id}/assign", [
            'assignedToUserId' => $user->id,
            'lockVersion' => $issue->lock_version + 10,
        ])->assertStatus(409);

        $this->assertNull($issue->fresh()->assigned_to_user_id);
    }

    public function test_issue_metadata_update_is_versioned_and_recorded_as_an_event(): void
    {
        $user = $this->userWithPermissions(['reports.inspection.view', 'reports.inspection.issues.manage']);
        $asset = $this->asset();
        $report = $this->report($user, $asset, 'Not Good', 'Gauge failed');
        app(InspectionCheckRowSyncService::class)->syncForReport($report, $user->id);
        $issue = InspectionFireExtinguisherIssue::query()->firstOrFail();

        $updated = $this->actingAs($user)->patchJson("/api/inspection/fire-extinguisher-issues/{$issue->id}", [
            'title' => 'Replace failed pressure gauge',
            'lockVersion' => $issue->lock_version,
        ])->assertOk()->assertJsonPath('data.title', 'Replace failed pressure gauge');

        $this->assertDatabaseHas('inspection_fire_extinguisher_issue_events', [
            'issue_id' => $issue->id, 'event_type' => 'updated',
        ]);
        $this->patchJson("/api/inspection/fire-extinguisher-issues/{$issue->id}", [
            'title' => 'Stale overwrite',
            'lockVersion' => $issue->lock_version,
        ])->assertStatus(409);
        $this->assertGreaterThan($issue->lock_version, $updated->json('data.lockVersion'));
    }

    public function test_issue_assignment_rejects_inactive_users_and_closed_issues(): void
    {
        $user = $this->userWithPermissions(['reports.inspection.view', 'reports.inspection.issues.manage']);
        $inactiveUser = User::factory()->create(['status' => 'inactive']);
        $unauthorizedUser = User::factory()->create(['status' => 'active']);
        $deletedUser = User::factory()->create(['status' => 'active']);
        $deletedUser->delete();
        $asset = $this->asset();
        $report = $this->report($user, $asset, 'Not Good', 'Gauge failed');
        app(InspectionCheckRowSyncService::class)->syncForReport($report, $user->id);
        $issue = InspectionFireExtinguisherIssue::query()->firstOrFail();
        $this->actingAs($user);

        $this->postJson("/api/inspection/fire-extinguisher-issues/{$issue->id}/assign", [
            'assignedToUserId' => $inactiveUser->id, 'lockVersion' => $issue->lock_version,
        ])->assertUnprocessable();
        $this->postJson("/api/inspection/fire-extinguisher-issues/{$issue->id}/assign", [
            'assignedToUserId' => $deletedUser->id, 'lockVersion' => $issue->lock_version,
        ])->assertUnprocessable();
        $this->postJson("/api/inspection/fire-extinguisher-issues/{$issue->id}/assign", [
            'assignedToUserId' => $unauthorizedUser->id, 'lockVersion' => $issue->lock_version,
        ])->assertUnprocessable();

        $cancelled = $this->postJson("/api/inspection/fire-extinguisher-issues/{$issue->id}/cancel", [
            'note' => 'Duplicate issue', 'lockVersion' => $issue->lock_version,
        ])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->postJson("/api/inspection/fire-extinguisher-issues/{$issue->id}/assign", [
            'assignedToUserId' => $user->id, 'lockVersion' => $cancelled->json('data.lockVersion'),
        ])->assertUnprocessable();
    }

    public function test_issue_can_be_reassigned_and_unassigned_with_an_audit_event(): void
    {
        $manager = $this->userWithPermissions(['reports.inspection.view', 'reports.inspection.issues.manage']);
        $secondManager = $this->userWithPermissions(['reports.inspection.view', 'reports.inspection.issues.manage']);
        $asset = $this->asset();
        $report = $this->report($manager, $asset, 'Not Good', 'Gauge failed');
        app(InspectionCheckRowSyncService::class)->syncForReport($report, $manager->id);
        $issue = InspectionFireExtinguisherIssue::query()->firstOrFail();
        $this->actingAs($manager);

        $this->getJson('/api/inspection/fire-extinguisher-issues/assignees')
            ->assertOk()
            ->assertJsonFragment(['id' => $manager->id])
            ->assertJsonFragment(['id' => $secondManager->id]);
        $assigned = $this->postJson("/api/inspection/fire-extinguisher-issues/{$issue->id}/assign", [
            'assignedToUserId' => $secondManager->id,
            'lockVersion' => $issue->lock_version,
        ])->assertOk()->assertJsonPath('data.assignee.id', $secondManager->id);
        $started = $this->postJson("/api/inspection/fire-extinguisher-issues/{$issue->id}/start", [
            'note' => 'Maintenance started',
            'lockVersion' => $assigned->json('data.lockVersion'),
        ])->assertOk()->assertJsonPath('data.status', 'in_progress');
        $this->postJson("/api/inspection/fire-extinguisher-issues/{$issue->id}/unassign", [
            'note' => 'Returned to the shared queue',
            'lockVersion' => $started->json('data.lockVersion'),
        ])->assertOk()
            ->assertJsonPath('data.assignee', null)
            ->assertJsonPath('data.status', 'open');

        $this->assertDatabaseHas('inspection_fire_extinguisher_issue_events', [
            'issue_id' => $issue->id,
            'event_type' => 'unassigned',
            'actor_user_id' => $manager->id,
            'from_status' => 'in_progress',
            'to_status' => 'open',
        ]);
    }

    public function test_retirement_cancels_active_issues_and_blocks_reopening_until_restore(): void
    {
        $user = $this->userWithPermissions([
            'reports.inspection.view', 'reports.inspection.extinguishers.manage', 'reports.inspection.issues.manage',
        ]);
        $asset = $this->asset();
        $report = $this->report($user, $asset, 'Not Good', 'Gauge failed');
        app(InspectionCheckRowSyncService::class)->syncForReport($report, $user->id);
        $issue = InspectionFireExtinguisherIssue::query()->firstOrFail();
        $this->actingAs($user);

        $retired = $this->postJson("/api/inspection/fire-extinguishers/{$asset->id}/retire", [
            'reason' => 'Removed from inventory', 'lockVersion' => $asset->fresh()->lock_version,
        ])->assertOk();
        $issue->refresh();
        $this->assertSame('cancelled', $issue->status);

        $this->postJson("/api/inspection/fire-extinguisher-issues/{$issue->id}/reopen", [
            'note' => 'Attempt before restore', 'lockVersion' => $issue->lock_version,
        ])->assertUnprocessable();

        $this->postJson("/api/inspection/fire-extinguishers/{$asset->id}/restore", [
            'lockVersion' => $retired->json('data.lockVersion'),
        ])->assertOk();
        $this->postJson("/api/inspection/fire-extinguisher-issues/{$issue->id}/reopen", [
            'note' => 'Asset returned to inventory', 'lockVersion' => $issue->lock_version,
        ])->assertOk()->assertJsonPath('data.status', 'open');
    }

    public function test_history_endpoint_returns_real_criteria_and_linked_issue(): void
    {
        $user = $this->userWithPermissions(['reports.inspection.view']);
        $asset = $this->asset();
        $report = $this->report($user, $asset, 'Not Good', 'Gauge failed');
        app(InspectionCheckRowSyncService::class)->syncForReport($report, $user->id);

        $response = $this->actingAs($user)->getJson("/api/inspection/fire-extinguishers/{$asset->id}/inspection-history");
        $response->assertOk()->assertJsonPath('data.0.displayId', $report->display_id)
            ->assertJsonPath('data.0.issueCount', 1)
            ->assertJsonPath('data.0.checks.4.issue.status', 'open');
    }

    private function asset(): InspectionFireExtinguisher
    {
        return InspectionFireExtinguisher::query()->create([
            'zone' => 'Lifecycle Test Zone', 'main_location_name' => 'Lifecycle Yard', 'sub_location_name' => 'Bay 1',
            'id_loc_no' => 'LIFE-001', 'barcode_no' => 'LIFE-BAR-001', 'fe_type' => 'DP 6KG',
            'certification_validity' => '2027-01-01', 'source' => 'custom', 'is_active' => true,
            'lifecycle_status' => 'active', 'sort_order' => 1,
        ]);
    }

    private function report(User $user, InspectionFireExtinguisher $asset, string $operational, string $remarks): Report
    {
        return Report::query()->create([
            'report_uid' => (string) Str::uuid(),
            'display_id' => 'INS-LIFECYCLE-'.Str::upper(Str::random(6)),
            'owner_user_id' => $user->id,
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => [
                'incidentType' => 'Fire Extinguisher Inspection',
                'fireExtinguisherChecks' => [[
                    'id' => 'asset-'.$asset->id, 'catalogId' => $asset->id,
                    'zone' => $asset->zone, 'mainLocation' => $asset->main_location_name,
                    'subLocation' => $asset->sub_location_name, 'idLocNo' => $asset->id_loc_no,
                    'barcodeNo' => $asset->barcode_no, 'feType' => $asset->fe_type,
                    'physicalCondition' => 'Good', 'physicalConditionRemarks' => '', 'physicalConditionPhotos' => [],
                    'signageCondition' => 'Good', 'signageConditionRemarks' => '', 'signageConditionPhotos' => [],
                    'boxKeyAvailability' => 'Good', 'boxKeyAvailabilityRemarks' => '', 'boxKeyAvailabilityPhotos' => [],
                    'boxGlassAvailability' => 'Good', 'boxGlassAvailabilityRemarks' => '', 'boxGlassAvailabilityPhotos' => [],
                    'operationalCondition' => $operational, 'operationalConditionRemarks' => $remarks, 'operationalConditionPhotos' => [],
                ]],
            ],
            'submitted_at' => now(),
        ]);
    }

    private function userWithPermissions(array $permissions): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $role = Role::query()->firstOrCreate(['name' => 'Lifecycle Test Role', 'guard_name' => 'web']);
        foreach ($permissions as $name) {
            $permission = Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
            if (! $role->hasPermissionTo($permission)) {
                $role->givePermissionTo($permission);
            }
        }
        $user->assignRole($role);

        return $user;
    }
}
