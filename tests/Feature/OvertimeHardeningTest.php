<?php

namespace Tests\Feature;

use App\Models\OvertimeRecord;
use App\Models\PayrollClaim;
use App\Models\User;
use App\Models\UserRoleAssignment;
use App\Services\RoleCatalog;
use App\Services\WorkflowNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OvertimeHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $reviewer = User::factory()->create(['status' => 'active']);
        $role = Role::firstOrCreate(['name' => 'Contract Manager', 'guard_name' => 'web']);
        UserRoleAssignment::query()->create([
            'user_id' => $reviewer->id,
            'role_id' => $role->id,
            'scope_type' => RoleCatalog::OFFICE,
            'is_primary' => true,
        ]);
    }

    public function test_applicant_cannot_submit_future_or_overlapping_overtime(): void
    {
        $user = $this->createApplicant();
        $this->actingAs($user);

        $this->postJson('/api/overtime', $this->payload(['claim_date' => now()->addDay()->toDateString()]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['claim_date']);

        $this->record($user, ['start_time' => '09:00', 'end_time' => '11:00', 'duration_minutes' => 120]);

        $this->postJson('/api/overtime', $this->payload([
            'start_time' => '10:00',
            'end_time' => '12:00',
            'duration_minutes' => 120,
        ]))
            ->assertStatus(409)
            ->assertJsonPath('code', 'OT_WINDOW_CONFLICT');

        $this->postJson('/api/overtime', $this->payload([
            'start_time' => '11:00',
            'end_time' => '12:00',
            'duration_minutes' => 60,
        ]))->assertCreated();
    }

    public function test_stale_update_returns_version_conflict(): void
    {
        $user = $this->createApplicant();
        $this->actingAs($user);
        $record = $this->record($user);

        $this->putJson("/api/overtime/{$record->id}", $this->payload([
            'expected_version' => 2,
        ]))
            ->assertStatus(409)
            ->assertJsonPath('code', 'OT_VERSION_CONFLICT');
    }

    public function test_manager_can_request_correction_and_applicant_can_resubmit(): void
    {
        $applicant = $this->createApplicant();
        $record = $this->record($applicant);
        $manager = $this->createManager();
        $this->actingAs($manager);

        $this->postJson("/api/staff/overtime/records/{$applicant->id}/{$record->id}/request-correction", [
            'remarks' => 'Please clarify the shift handover work completed.',
            'expected_version' => 1,
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'Needs Correction')
            ->assertJsonPath('data.workflow_stage', 'correction')
            ->assertJsonPath('data.version', 2);

        $this->actingAs($applicant);
        $this->putJson("/api/overtime/{$record->id}", $this->payload([
            'reason' => 'Completed documented shift handover and equipment reconciliation.',
            'expected_version' => 2,
        ]))
            ->assertOk()
            ->assertJsonPath('data.status', 'Pending')
            ->assertJsonPath('data.workflow_stage', 'review')
            ->assertJsonPath('data.version', 3);
    }

    public function test_submission_key_makes_retried_create_idempotent(): void
    {
        $user = $this->createApplicant();
        $payload = $this->payload(['submission_key' => 'ot-idempotency-test-2026-04-13']);

        $first = $this->actingAs($user)->postJson('/api/overtime', $payload);
        $second = $this->postJson('/api/overtime', $payload);

        $first->assertCreated()->assertJsonPath('idempotent_replay', false);
        $second->assertOk()
            ->assertJsonPath('idempotent_replay', true)
            ->assertJsonPath('data.id', $first->json('data.id'));

        $this->postJson('/api/overtime', array_merge($payload, [
            'reason' => 'A different claim must not reuse the same submission key.',
        ]))
            ->assertConflict()
            ->assertJsonPath('code', 'OT_SUBMISSION_KEY_REUSED');
        $this->assertDatabaseCount('overtime_records', 1);
    }

    public function test_existing_draft_requires_the_current_version_to_save_or_delete(): void
    {
        $user = $this->createApplicant();
        $this->actingAs($user)
            ->postJson('/api/overtime/draft', [
                'payload' => ['reason' => 'First private draft value.'],
            ])
            ->assertOk()
            ->assertJsonPath('data.draftVersion', 1);

        $this->postJson('/api/overtime/draft', [
            'payload' => ['reason' => 'Stale overwrite attempt.'],
        ])
            ->assertConflict()
            ->assertJsonPath('code', 'OT_DRAFT_VERSION_CONFLICT')
            ->assertJsonPath('currentVersion', 1)
            ->assertJsonPath('currentDraft.reason', 'First private draft value.');

        $this->postJson('/api/overtime/draft', [
            'payload' => ['reason' => 'Versioned draft value.'],
            'expected_version' => 1,
        ])
            ->assertOk()
            ->assertJsonPath('data.draftVersion', 2);

        $this->deleteJson('/api/overtime/draft', ['expected_version' => 1])
            ->assertConflict()
            ->assertJsonPath('currentVersion', 2);
        $this->deleteJson('/api/overtime/draft', ['expected_version' => 2])
            ->assertNoContent();
        $this->postJson('/api/overtime/draft', [
            'payload' => ['reason' => 'Stale draft resurrection attempt.'],
            'expected_version' => 2,
        ])
            ->assertConflict()
            ->assertJsonPath('currentVersion', 0)
            ->assertJsonPath('currentDraft', null);
    }

    public function test_mutations_require_an_expected_version(): void
    {
        $user = $this->createApplicant();
        $record = $this->record($user);
        $this->actingAs($user);

        $this->putJson("/api/overtime/{$record->id}", $this->payload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('expected_version');
        $this->postJson("/api/overtime/{$record->id}/cancel")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('expected_version');
        $this->deleteJson("/api/overtime/{$record->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('expected_version');

        $this->assertSame(1, $record->fresh()->version);
        $this->assertSame('Pending', $record->fresh()->status);
    }

    public function test_overtime_create_rolls_back_when_notification_outbox_persistence_fails(): void
    {
        $user = $this->createApplicant();
        $notificationService = \Mockery::mock(WorkflowNotificationService::class);
        $notificationService->shouldReceive('emit')
            ->once()
            ->andThrow(new \RuntimeException('Simulated notification persistence failure.'));
        $this->app->instance(WorkflowNotificationService::class, $notificationService);

        $this->actingAs($user)
            ->postJson('/api/overtime', $this->payload([
                'submission_key' => 'ot-notification-rollback-test',
            ]))
            ->assertStatus(500);

        $this->assertDatabaseCount('overtime_records', 0);
    }

    public function test_paid_salary_overtime_cannot_be_cancelled_or_deleted(): void
    {
        $user = $this->createApplicant();
        $record = $this->record($user, [
            'status' => 'Approved',
            'workflow_stage' => 'done',
            'next_action_role' => null,
        ]);
        PayrollClaim::query()->create([
            'user_id' => $user->id,
            'display_id' => 'CLM-PAID-OT-LOCK',
            'claim_type' => 'salary',
            'period' => 'April 2026',
            'period_value' => '2026-04',
            'amount' => 3000,
            'status' => 'Paid',
            'workflow_stage' => 'done',
            'approval_history' => [],
            'payroll_snapshot' => [],
            'overtime_rows' => [[
                'overtimeRecordId' => $record->id,
                'overtimePublicId' => $record->public_id,
                'overtimeRecordVersion' => 1,
            ]],
            'version' => 1,
        ]);

        $this->actingAs($user)
            ->postJson("/api/overtime/{$record->id}/cancel", ['expected_version' => 1])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $record->update(['status' => 'Cancelled']);
        $this->deleteJson("/api/overtime/{$record->id}", ['expected_version' => 1])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
        $this->assertNotSoftDeleted('overtime_records', ['id' => $record->id]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'overtime_type' => 'weekday',
            'claim_date' => '2026-04-13',
            'start_time' => '09:00',
            'end_time' => '10:00',
            'is_overnight' => false,
            'duration_minutes' => 60,
            'reason' => 'Completed shift handover and incident log updates.',
        ], $overrides);
    }

    private function record(User $user, array $overrides = []): OvertimeRecord
    {
        return OvertimeRecord::query()->create(array_merge([
            'user_id' => $user->id,
            'display_id' => 'OT-2026-'.random_int(100, 999),
            'overtime_type' => 'weekday',
            'claim_date' => '2026-04-13',
            'start_time' => '09:00',
            'end_time' => '10:00',
            'is_overnight' => false,
            'duration_minutes' => 60,
            'reason' => 'Completed shift handover and incident log updates.',
            'status' => 'Pending',
            'applied_at' => now(),
            'workflow_stage' => 'review',
            'workflow_snapshot' => [
                'reviewRole' => 'Contract Manager',
                'recommendRole' => 'Human Resource',
                'approveRole' => 'Client Contract Manager',
                'requireRecommendation' => true,
            ],
            'next_action_role' => 'Contract Manager',
            'applicant_roles' => ['Tactical Response Team'],
            'approval_history' => [],
            'submitted_by' => $user->name,
            'version' => 1,
        ], $overrides));
    }

    private function createApplicant(): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $role = Role::firstOrCreate(['name' => 'Tactical Response Team', 'guard_name' => 'web']);
        $permission = Permission::firstOrCreate(['name' => 'self.overtime', 'guard_name' => 'web']);
        $role->givePermissionTo($permission);
        UserRoleAssignment::query()->create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'scope_type' => RoleCatalog::GLOBAL,
            'is_primary' => true,
        ]);

        return $user;
    }

    private function createManager(): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $role = Role::firstOrCreate(['name' => 'System Administrator', 'guard_name' => 'web']);
        $permission = Permission::firstOrCreate(['name' => 'staff.overtime.manage', 'guard_name' => 'web']);
        $role->givePermissionTo($permission);
        UserRoleAssignment::query()->create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'scope_type' => RoleCatalog::GLOBAL,
            'is_primary' => true,
        ]);

        return $user;
    }
}
