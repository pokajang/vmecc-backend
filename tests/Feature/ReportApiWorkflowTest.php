<?php

namespace Tests\Feature;

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
            'payload' => $this->ercoPayload('Zone 1'),
        ]);
        $create->assertCreated();
        $create->assertJsonPath('data.status', 'Submitted');
        $create->assertJsonPath('data.version', 1);
        $reportUid = (string) $create->json('data.id');

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
        $reportUid = (string) $first->json('data.id');

        $second = $this->postJson('/api/reports', $payload);
        $second->assertOk();
        $second->assertJsonPath('data.id', $reportUid);
        $second->assertJsonPath('data.idempotent_replay', true);

        $this->assertDatabaseCount('reports', 1);
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
                'photos' => [],
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
