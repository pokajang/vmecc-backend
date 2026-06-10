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

class OvertimeApplicantEditLockTest extends TestCase
{
    use RefreshDatabase;

    public function test_applicant_can_update_pending_overtime_before_first_review_step(): void
    {
        $user = $this->createOvertimeUser();
        $this->actingAs($user);

        $record = $this->createOvertimeRecord($user, [
            'status' => 'Pending',
            'workflow_stage' => 'review',
            'approval_history' => [
                ['action' => 'Submitted', 'remarks' => 'Initial submit'],
            ],
        ]);

        $response = $this->putJson("/api/overtime/{$record->id}", $this->validPayload('Updated reason before review'));

        $response->assertOk();
        $response->assertJsonPath('data.id', $record->id);
        $response->assertJsonPath('data.reason', 'Updated reason before review');
    }

    public function test_applicant_cannot_update_pending_overtime_after_first_review_step(): void
    {
        $user = $this->createOvertimeUser();
        $this->actingAs($user);

        $record = $this->createOvertimeRecord($user, [
            'status' => 'Pending',
            'workflow_stage' => 'recommend',
            'approval_history' => [
                ['action' => 'Submitted', 'remarks' => 'Initial submit'],
                ['action' => 'Reviewed', 'remarks' => 'Reviewed by manager'],
            ],
        ]);

        $response = $this->putJson("/api/overtime/{$record->id}", $this->validPayload('Blocked update'));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['status']);
    }

    public function test_applicant_can_update_draft_overtime_record(): void
    {
        $user = $this->createOvertimeUser();
        $this->actingAs($user);

        $record = $this->createOvertimeRecord($user, [
            'status' => 'Draft',
            'workflow_stage' => 'review',
            'approval_history' => [],
        ]);

        $response = $this->putJson("/api/overtime/{$record->id}", $this->validPayload('Draft edit allowed'));

        $response->assertOk();
        $response->assertJsonPath('data.id', $record->id);
        $response->assertJsonPath('data.reason', 'Draft edit allowed');
    }

    public function test_applicant_update_normalizes_seconds_and_ampm_time_formats(): void
    {
        $user = $this->createOvertimeUser();
        $this->actingAs($user);

        $record = $this->createOvertimeRecord($user, [
            'status' => 'Pending',
            'workflow_stage' => 'review',
            'approval_history' => [
                ['action' => 'Submitted', 'remarks' => 'Initial submit'],
            ],
        ]);

        $response = $this->putJson("/api/overtime/{$record->id}", [
            'overtime_type' => 'weekday',
            'claim_date' => '2026-04-13',
            'start_time' => '9:15 PM',
            'end_time' => '22:30:00',
            'is_overnight' => false,
            'duration_minutes' => 75,
            'reason' => 'Format normalization update',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.start_time', '21:15');
        $response->assertJsonPath('data.end_time', '22:30');

        $record->refresh();
        $this->assertSame('21:15', substr((string) $record->start_time, 0, 5));
        $this->assertSame('22:30', substr((string) $record->end_time, 0, 5));
    }

    private function createOvertimeRecord(User $user, array $overrides = []): OvertimeRecord
    {
        return OvertimeRecord::query()->create(array_merge([
            'user_id' => $user->id,
            'display_id' => 'OT-2026-001',
            'overtime_type' => 'weekday',
            'claim_date' => '2026-04-13',
            'start_time' => '09:00',
            'end_time' => '10:00',
            'is_overnight' => false,
            'duration_minutes' => 60,
            'reason' => 'Initial overtime reason',
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
            'applicant_roles' => ['Incident Commander'],
            'approval_history' => [],
            'submitted_by' => 'Applicant User',
        ], $overrides));
    }

    private function createOvertimeUser(): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $role = Role::firstOrCreate(['name' => 'Tactical Response Team', 'guard_name' => 'web']);
        $permission = Permission::firstOrCreate(['name' => 'self.overtime', 'guard_name' => 'web']);
        $role->givePermissionTo($permission);

        UserRoleAssignment::create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'scope_type' => RoleCatalog::GLOBAL,
            'is_primary' => true,
        ]);

        return $user;
    }

    private function validPayload(string $reason): array
    {
        return [
            'overtime_type' => 'weekday',
            'claim_date' => '2026-04-13',
            'start_time' => '09:00',
            'end_time' => '10:30',
            'is_overnight' => false,
            'duration_minutes' => 90,
            'reason' => $reason,
        ];
    }
}
