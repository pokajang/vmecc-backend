<?php

namespace Tests\Feature;

use App\Models\InspectionDutyConfirmation;
use App\Models\Roster;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\UserRoleAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class InspectionDutyMutationEnforcementTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_draft_update_to_submitted_requires_bound_confirmation(): void
    {
        [$user] = $this->assignedUsers();
        config()->set('inspection_duty.enforcement_enabled', true);
        $draft = $this->actingAs($user)->postJson('/api/reports', $this->reportPayload(
            'report-update-1',
            'Draft',
        ))->assertCreated();
        $body = [
            'payload' => $this->inspectionPayload(),
            'status' => 'Submitted',
            'version' => $draft->json('data.version'),
            'submission_key' => 'update-submit-1',
        ];

        $this->actingAs($user)->putJson('/api/reports/report-update-1', $body)
            ->assertStatus(428)
            ->assertJsonPath('code', 'duty_confirmation_required');

        $token = $this->issue($user, 'submit', [
            'formId' => 'general-inspection',
            'recordId' => 'report-update-1',
            'idempotencyKey' => 'update-submit-1',
        ]);
        $this->actingAs($user)
            ->withHeader('X-Duty-Confirmation', $token)
            ->putJson('/api/reports/report-update-1', $body)
            ->assertOk()
            ->assertJsonPath('data.status', 'Submitted')
            ->assertJsonPath('data.dutyContextStatus', 'assigned');
    }

    public function test_submitted_delete_requires_delete_scoped_confirmation(): void
    {
        [$user] = $this->assignedUsers();
        config()->set('inspection_duty.enforcement_enabled', true);
        $this->createSubmittedReport($user, 'report-delete-1', 'delete-submit-1');

        $this->actingAs($user)
            ->withHeader('X-Duty-Confirmation', '')
            ->deleteJson('/api/reports/report-delete-1')
            ->assertStatus(428)
            ->assertJsonPath('code', 'duty_confirmation_required');

        $token = $this->issue($user, 'delete', ['recordId' => 'report-delete-1']);
        $this->actingAs($user)
            ->withHeader('X-Duty-Confirmation', $token)
            ->deleteJson('/api/reports/report-delete-1')
            ->assertNoContent();

        $this->assertSoftDeleted('reports', ['report_uid' => 'report-delete-1']);
        $this->assertSame(2, InspectionDutyConfirmation::query()->whereNotNull('consumed_at')->count());
    }

    public function test_review_requires_role_policy_and_review_scoped_confirmation(): void
    {
        [$submitter, $reviewer, $team] = $this->assignedUsers(true);
        $this->assignWorkflowRole($reviewer, 'Incident Commander');
        config()->set('inspection_duty.enforcement_enabled', true);
        $this->createSubmittedReport($submitter, 'report-review-1', 'review-submit-1');

        $this->actingAs($reviewer)
            ->withHeader('X-Duty-Confirmation', '')
            ->postJson('/api/reports/report-review-1/review', ['version' => 1])
            ->assertStatus(428)
            ->assertJsonPath('code', 'duty_confirmation_required');

        $token = $this->issue($reviewer, 'review', ['recordId' => 'report-review-1']);
        $this->actingAs($reviewer)
            ->withHeader('X-Duty-Confirmation', $token)
            ->postJson('/api/reports/report-review-1/review', [
                'version' => 1,
                'remarks' => 'Reviewed on active duty.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'Reviewed')
            ->assertJsonPath('data.dutyContextStatus', 'assigned');

        $this->assertDatabaseHas('reports', [
            'report_uid' => 'report-review-1',
            'scope_team_id' => null,
            'duty_context_status' => 'assigned',
        ]);
        $this->assertSame($team->id, data_get(
            InspectionDutyConfirmation::query()->where('operation', 'review')->firstOrFail()->context_snapshot,
            'teamId',
        ));
    }

    private function assignedUsers(bool $withReviewer = false): array
    {
        Carbon::setTestNow('2026-07-12 02:00:00 UTC');
        $submitter = $this->inspectionUser('Duty Submitter');
        $reviewer = $withReviewer ? $this->inspectionUser('Duty Reviewer') : null;
        $team = Team::query()->create(['name' => 'Mutation Team', 'status' => 'On Duty']);
        foreach (array_filter([$submitter, $reviewer]) as $index => $user) {
            TeamMember::query()->create([
                'team_id' => $team->id,
                'user_id' => $user->id,
                'name' => $user->name,
                'role' => 'Inspector',
                'is_primary' => $index === 0,
                'started_at' => '2026-01-01',
            ]);
        }
        Roster::query()->create([
            'date' => '2026-07-12',
            'shift' => 'day',
            'team_id' => $team->id,
            'status' => 'published',
            'created_by' => $submitter->id,
            'published_by' => $submitter->id,
            'published_at' => now(),
        ]);

        return [$submitter, $reviewer, $team];
    }

    private function inspectionUser(string $name): User
    {
        $user = User::factory()->create(['name' => $name, 'status' => 'active']);
        $role = Role::query()->firstOrCreate(['name' => 'Duty Mutation Tester', 'guard_name' => 'web']);
        foreach (['reports.inspection.view', 'reports.inspection.conduct'] as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName, 'guard_name' => 'web']);
            if (! $role->hasPermissionTo($permission)) {
                $role->givePermissionTo($permission);
            }
        }
        $user->assignRole($role);

        return $user;
    }

    private function assignWorkflowRole(User $user, string $roleName): void
    {
        $role = Role::query()->firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
        foreach (['reports.inspection.view', 'reports.inspection.conduct'] as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName, 'guard_name' => 'web']);
            if (! $role->hasPermissionTo($permission)) {
                $role->givePermissionTo($permission);
            }
        }
        UserRoleAssignment::query()->create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'scope_type' => 'global',
            'is_primary' => true,
        ]);
    }

    private function createSubmittedReport(User $user, string $reportUid, string $submissionKey): void
    {
        $token = $this->issue($user, 'submit', [
            'formId' => 'general-inspection',
            'recordId' => $reportUid,
            'idempotencyKey' => $submissionKey,
        ]);
        $this->actingAs($user)
            ->withHeader('X-Duty-Confirmation', $token)
            ->postJson('/api/reports', $this->reportPayload($reportUid, 'Submitted', $submissionKey))
            ->assertCreated();
    }

    private function issue(User $user, string $operation, array $binding): string
    {
        $contextVersion = $this->actingAs($user)->getJson('/api/inspection/duty-context')
            ->assertOk()
            ->json('data.contextVersion');

        return (string) $this->actingAs($user)->postJson('/api/inspection/duty-context/confirm', [
            'operation' => $operation,
            'contextVersion' => $contextVersion,
            ...$binding,
        ])->assertCreated()->json('data.dutyConfirmationToken');
    }

    private function reportPayload(string $reportUid, string $status, ?string $submissionKey = null): array
    {
        return [
            'report_uid' => $reportUid,
            'display_id' => strtoupper($reportUid),
            'report_type' => 'inspection',
            'status' => $status,
            'payload' => $this->inspectionPayload(),
            ...($submissionKey ? ['submission_key' => $submissionKey] : []),
        ];
    }

    private function inspectionPayload(): array
    {
        return [
            'incidentType' => 'General Inspection',
            'location' => 'Fire Rescue Tender (FRT)',
            'selectedLocation' => 'Fire Rescue Tender (FRT)',
            'mainLocation' => 'FRT',
            'description' => 'Duty mutation enforcement test.',
            'photos' => [],
            'checklist' => [[
                'id' => 'general-inspection:mutation',
                'label' => 'Mutation check',
                'inspectionType' => 'General Inspection',
                'selected' => true,
            ]],
        ];
    }
}
