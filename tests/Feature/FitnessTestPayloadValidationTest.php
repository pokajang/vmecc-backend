<?php

namespace Tests\Feature;

use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FitnessTestPayloadValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_complete_fitness_submission_round_trips_and_replays_idempotently(): void
    {
        $user = $this->reporter();
        $request = [
            'report_uid' => 'fitness-validation-1',
            'display_id' => 'FIT-VALIDATION-001',
            'report_type' => 'fitness-test',
            'status' => 'Submitted',
            'submission_key' => 'fitness-validation-submit-1',
            'payload' => $this->validPayload(),
        ];

        $this->actingAs($user)->postJson('/api/reports', $request)
            ->assertCreated()
            ->assertJsonPath('data.location', 'Training yard')
            ->assertJsonPath('data.chronology.0.action', 'Fitness test started.')
            ->assertJsonPath('data.idempotent_replay', false);

        $this->postJson('/api/reports', $request)
            ->assertOk()
            ->assertJsonPath('data.id', 'fitness-validation-1')
            ->assertJsonPath('data.idempotent_replay', true);

        $this->assertSame(1, Report::query()->where('submission_key', 'fitness-validation-submit-1')->count());
    }

    public function test_fitness_final_submission_rejects_incomplete_payload(): void
    {
        $user = $this->reporter();

        $this->actingAs($user)->postJson('/api/reports', [
            'display_id' => 'FIT-INVALID-001',
            'report_type' => 'fitness-test',
            'status' => 'Submitted',
            'payload' => ['schemaVersion' => 1, 'incidentType' => 'Endurance Test'],
        ])->assertUnprocessable()->assertJsonValidationErrors([
            'reportDate',
            'reportTime',
            'weather',
            'location',
            'details',
            'summary',
            'chronology',
        ]);

        $this->assertDatabaseMissing('reports', ['display_id' => 'FIT-INVALID-001']);
    }

    public function test_fitness_draft_accepts_incomplete_progress_and_rejects_malformed_chronology(): void
    {
        $user = $this->reporter();

        $this->actingAs($user)->postJson('/api/reports/drafts', [
            'report_type' => 'fitness-test',
            'payload' => ['schemaVersion' => 1, 'reportDate' => '2026-07-13'],
        ])->assertCreated();

        $this->postJson('/api/reports/drafts', [
            'report_type' => 'fitness-test',
            'payload' => [
                'schemaVersion' => 1,
                'chronology' => [['time' => 'invalid', 'action' => []]],
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors([
            'chronology.0.time',
            'chronology.0.action',
        ]);
    }

    public function test_fitness_update_uses_optimistic_versioning_without_creating_a_sibling(): void
    {
        $user = $this->reporter();
        $created = $this->actingAs($user)->postJson('/api/reports', [
            'report_uid' => 'fitness-update-1',
            'display_id' => 'FIT-UPDATE-001',
            'report_type' => 'fitness-test',
            'status' => 'Submitted',
            'payload' => $this->validPayload(),
        ])->assertCreated();

        $payload = $this->validPayload();
        $payload['summary'] = 'Updated fitness test summary.';
        $this->putJson('/api/reports/fitness-update-1', [
            'version' => $created->json('data.version'),
            'status' => 'Submitted',
            'payload' => $payload,
        ])->assertOk()
            ->assertJsonPath('data.id', 'fitness-update-1')
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.summary', 'Updated fitness test summary.');

        $this->assertDatabaseCount('reports', 1);
    }

    private function validPayload(): array
    {
        return [
            'schemaVersion' => 1,
            'reportDate' => '2026-07-13',
            'reportTime' => '09:00',
            'weather' => 'Routine',
            'incidentType' => 'Endurance Test',
            'location' => 'Training yard',
            'details' => 'Fitness test session details.',
            'summary' => 'Fitness test completed safely.',
            'chronology' => [['time' => '09:00', 'action' => 'Fitness test started.']],
        ];
    }

    private function reporter(): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $permission = Permission::query()->firstOrCreate([
            'name' => 'reports.fitness.view',
            'guard_name' => 'web',
        ]);
        $role = Role::query()->firstOrCreate([
            'name' => 'Fitness Payload Reporter',
            'guard_name' => 'web',
        ]);
        $role->givePermissionTo($permission);
        $user->assignRole($role);

        return $user;
    }
}
