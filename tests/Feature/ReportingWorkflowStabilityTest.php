<?php

namespace Tests\Feature;

use App\Models\Team;
use App\Models\User;
use App\Models\UserRoleAssignment;
use App\Models\WorkflowNotification;
use App\Models\WorkflowNotificationRecipientState;
use App\Services\RoleCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ReportingWorkflowStabilityTest extends TestCase
{
    use RefreshDatabase;

    public static function managedReportModules(): array
    {
        return [
            'erco' => ['erco', 'reports.erco.view', 'ERCO-STABLE-001'],
            'drill' => ['drill', 'reports.drill.view', 'DRILL-STABLE-001'],
            'fitness-test' => ['fitness-test', 'reports.fitness.view', 'FIT-STABLE-001'],
        ];
    }

    #[DataProvider('managedReportModules')]
    public function test_managed_report_modules_complete_submit_review_approve_with_flags_and_notifications(
        string $reportType,
        string $permissionName,
        string $displayId,
    ): void {
        $submitter = User::factory()->create(['status' => 'active']);
        $reviewer = User::factory()->create(['status' => 'active']);
        $approver = User::factory()->create(['status' => 'active']);
        $unrelated = User::factory()->create(['status' => 'active']);
        $team = Team::factory()->create();

        $this->assignWorkflowRole($submitter, 'Tactical Response Team', $permissionName, $team);
        $this->assignWorkflowRole($reviewer, 'Incident Commander', $permissionName, $team);
        $this->assignWorkflowRole($approver, 'Incident Commander', $permissionName, $team);

        $this->actingAs($submitter);
        $draft = $this->postJson('/api/reports', [
            'display_id' => "{$displayId}-DRAFT",
            'report_type' => $reportType,
            'status' => 'Draft',
            'payload' => $this->payloadFor($reportType, 'Draft location'),
        ]);
        $draft->assertCreated()
            ->assertJsonPath('data.status', 'Draft')
            ->assertJsonPath('data.workflowStage', null)
            ->assertJsonPath('data.nextActionRole', null)
            ->assertJsonPath('data.canReview', false)
            ->assertJsonPath('data.canApprove', false)
            ->assertJsonPath('data.canReject', false);

        $submitted = $this->postJson('/api/reports', [
            'display_id' => $displayId,
            'report_type' => $reportType,
            'status' => 'Submitted',
            'payload' => $this->payloadFor($reportType, 'Submit location'),
        ]);
        $submitted->assertCreated()
            ->assertJsonPath('data.status', 'Submitted')
            ->assertJsonPath('data.workflowStage', 'review')
            ->assertJsonPath('data.nextActionRole', 'Incident Commander')
            ->assertJsonPath('data.workflowSnapshot.moduleKey', $reportType)
            ->assertJsonPath('data.canReview', false)
            ->assertJsonPath('data.canApprove', false)
            ->assertJsonPath('data.canReject', false);

        $reportUid = (string) $submitted->json('data.id');
        $this->assertGreaterThanOrEqual(1, count($submitted->json('data.approvalHistory') ?? []));
        $this->assertNotificationTargets($displayId, 'submitted', [$reviewer->id]);

        $this->actingAs($reviewer);
        $reviewerView = $this->getJson("/api/reports/{$reportUid}");
        $reviewerView->assertOk()
            ->assertJsonPath('data.canReview', true)
            ->assertJsonPath('data.canApprove', false)
            ->assertJsonPath('data.canReject', true);

        $reviewerList = $this->getJson("/api/reports?reportType={$reportType}&scope=all");
        $reviewerList->assertOk();
        $this->assertContains($displayId, collect($reviewerList->json('data'))->pluck('displayId')->all());

        $this->actingAs($unrelated);
        $this->postJson("/api/reports/{$reportUid}/review", [
            'version' => 1,
            'remarks' => 'Unauthorized review attempt.',
        ])->assertForbidden();

        $this->actingAs($reviewer);
        $reviewed = $this->postJson("/api/reports/{$reportUid}/review", [
            'version' => 1,
            'remarks' => 'Reviewed for stability validation.',
        ]);
        $reviewed->assertOk()
            ->assertJsonPath('data.status', 'Reviewed')
            ->assertJsonPath('data.workflowStage', 'approve')
            ->assertJsonPath('data.nextActionRole', 'Incident Commander')
            ->assertJsonPath('data.canReview', false)
            ->assertJsonPath('data.canApprove', true)
            ->assertJsonPath('data.canReject', true);

        $this->assertNotificationTargets($displayId, 'reviewed', [$reviewer->id, $approver->id]);

        $this->actingAs($approver);
        $approverView = $this->getJson("/api/reports/{$reportUid}");
        $approverView->assertOk()
            ->assertJsonPath('data.canReview', false)
            ->assertJsonPath('data.canApprove', true)
            ->assertJsonPath('data.canReject', true);

        $approved = $this->postJson("/api/reports/{$reportUid}/approve", [
            'version' => 2,
            'remarks' => 'Approved for stability validation.',
        ]);
        $approved->assertOk()
            ->assertJsonPath('data.status', 'Approved')
            ->assertJsonPath('data.workflowStage', 'done')
            ->assertJsonPath('data.nextActionRole', null)
            ->assertJsonPath('data.canReview', false)
            ->assertJsonPath('data.canApprove', false)
            ->assertJsonPath('data.canReject', false);

        $this->assertNotificationTargets($displayId, 'approved', [$submitter->id]);
    }

    public function test_self_review_and_self_approval_are_blocked_for_managed_reports(): void
    {
        $owner = User::factory()->create(['status' => 'active']);
        $approver = User::factory()->create(['status' => 'active']);
        $team = Team::factory()->create();
        $this->assignWorkflowRole($owner, 'Incident Commander', 'reports.erco.view', $team);
        $this->assignWorkflowRole($approver, 'Incident Commander', 'reports.erco.view', $team);

        $this->actingAs($owner);
        $created = $this->postJson('/api/reports', [
            'display_id' => 'ERCO-SELF-BLOCK-001',
            'report_type' => 'erco',
            'status' => 'Submitted',
            'payload' => $this->payloadFor('erco', 'Self review location'),
        ]);
        $created->assertCreated()
            ->assertJsonPath('data.canReview', false)
            ->assertJsonPath('data.canApprove', false);

        $reportUid = (string) $created->json('data.id');
        $this->postJson("/api/reports/{$reportUid}/review", [
            'version' => 1,
            'remarks' => 'Owner self-review attempt.',
        ])->assertForbidden();

        $this->actingAs($approver);
        $this->postJson("/api/reports/{$reportUid}/review", [
            'version' => 1,
            'remarks' => 'Independent review.',
        ])->assertOk();

        $this->actingAs($owner);
        $this->getJson("/api/reports/{$reportUid}")
            ->assertOk()
            ->assertJsonPath('data.canApprove', false);
        $this->postJson("/api/reports/{$reportUid}/approve", [
            'version' => 2,
            'remarks' => 'Owner self-approval attempt.',
        ])->assertForbidden();
    }

    public function test_reject_notifies_owner_for_managed_reports(): void
    {
        $submitter = User::factory()->create(['status' => 'active']);
        $reviewer = User::factory()->create(['status' => 'active']);
        $team = Team::factory()->create();
        $this->assignWorkflowRole($submitter, 'Tactical Response Team', 'reports.erco.view', $team);
        $this->assignWorkflowRole($reviewer, 'Incident Commander', 'reports.erco.view', $team);

        $this->actingAs($submitter);
        $created = $this->postJson('/api/reports', [
            'display_id' => 'ERCO-REJECT-STABLE-001',
            'report_type' => 'erco',
            'status' => 'Submitted',
            'payload' => $this->payloadFor('erco', 'Reject location'),
        ]);
        $created->assertCreated();
        $reportUid = (string) $created->json('data.id');

        $this->actingAs($reviewer);
        $rejected = $this->postJson("/api/reports/{$reportUid}/reject", [
            'version' => 1,
            'remarks' => 'Need more detail before approval.',
        ]);
        $rejected->assertOk()
            ->assertJsonPath('data.status', 'Rejected')
            ->assertJsonPath('data.workflowStage', 'done')
            ->assertJsonPath('data.nextActionRole', null);

        $this->assertNotificationTargets('ERCO-REJECT-STABLE-001', 'rejected', [$submitter->id]);
    }

    private function payloadFor(string $reportType, string $location): array
    {
        if ($reportType === 'erco') {
            return [
                'schemaVersion' => 1,
                'incidentDate' => '2026-07-13',
                'incidentTime' => '09:00',
                'weather' => 'Clear',
                'incidentType' => 'Fire',
                'location' => $location,
                'details' => 'Reporting workflow stability validation report.',
                'summary' => 'Reporting workflow stability validation summary.',
                'respondingTeam' => [
                    'attendance' => [['memberId' => 'member-1', 'name' => 'Responder One']],
                ],
                'chronology' => [['time' => '09:00', 'action' => 'Response started.']],
                'postIncidentAnalysis' => [
                    'strengths' => ['Prompt mobilisation'],
                    'photos' => [[
                        'id' => 'required-photo-1',
                        'url' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
                    ]],
                ],
            ];
        }

        if ($reportType === 'drill') {
            return [
                'schemaVersion' => 2,
                'reportDate' => '2026-07-13',
                'reportTime' => '09:00',
                'weather' => 'Clear',
                'incidentType' => 'Fire Drill',
                'location' => $location,
                'details' => 'Reporting workflow stability drill scenario.',
                'summary' => 'Reporting workflow stability drill summary.',
                'chronology' => [['time' => '09:00', 'action' => 'Exercise started.']],
                'postIncidentAnalysis' => ['photos' => []],
            ];
        }

        return [
            'schemaVersion' => 1,
            'reportDate' => '2026-07-13',
            'reportTime' => '09:00',
            'weather' => 'Routine',
            'incidentType' => 'Endurance Test',
            'location' => $location,
            'details' => 'Reporting workflow stability fitness test details.',
            'summary' => 'Reporting workflow stability fitness test summary.',
            'chronology' => [['time' => '09:00', 'action' => 'Fitness test started.']],
        ];
    }

    private function assignWorkflowRole(
        User $user,
        string $roleName,
        string $permissionName,
        Team $team,
    ): void {
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
            'scope_type' => RoleCatalog::SITE,
            'team_id' => $team->id,
            'is_primary' => true,
        ]);
    }

    private function assertNotificationTargets(string $displayId, string $eventType, array $expectedUserIds): void
    {
        $notification = WorkflowNotification::query()
            ->where('record_display_id', $displayId)
            ->where('event_type', $eventType)
            ->latest('id')
            ->first();

        $this->assertNotNull($notification, "Missing {$eventType} notification for {$displayId}.");
        $this->assertSame('report', $notification->module);
        $this->assertEqualsCanonicalizing(
            array_map('intval', $expectedUserIds),
            array_map('intval', $notification->recipient_user_ids ?? []),
        );

        foreach ($expectedUserIds as $userId) {
            $this->assertTrue(
                WorkflowNotificationRecipientState::query()
                    ->where('notification_id', $notification->id)
                    ->where('user_id', $userId)
                    ->exists(),
                "Missing recipient state for user {$userId} on {$eventType} notification {$displayId}."
            );
        }
    }
}
