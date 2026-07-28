<?php

namespace Tests\Feature;

use App\Models\OvertimeRecord;
use App\Models\User;
use App\Models\UserRoleAssignment;
use App\Services\RoleCatalog;
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
