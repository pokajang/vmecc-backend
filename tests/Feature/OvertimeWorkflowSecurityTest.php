<?php

namespace Tests\Feature;

use App\Models\OvertimeRecord;
use App\Models\Team;
use App\Models\User;
use App\Models\UserRoleAssignment;
use App\Services\RoleCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OvertimeWorkflowSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_administrator_cannot_cancel_another_employees_overtime(): void
    {
        $applicant = $this->createApplicant();
        $record = $this->createRecord($applicant, [
            'status' => 'Approved',
            'workflow_stage' => 'done',
            'next_action_role' => null,
        ]);
        $manager = $this->createManager('Human Resource');

        $this->actingAs($manager)
            ->postJson("/api/staff/overtime/records/{$applicant->id}/{$record->id}/cancel", [
                'expected_version' => 1,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role');
    }

    public function test_distinct_approver_policy_blocks_a_user_from_processing_two_stages(): void
    {
        $applicant = $this->createApplicant();
        $record = $this->createRecord($applicant, [
            'workflow_snapshot' => [
                'reviewRole' => 'Contract Manager',
                'recommendRole' => 'Human Resource',
                'approveRole' => 'Client Contract Manager',
                'requireRecommendation' => true,
                'enforceDistinctApprovers' => true,
            ],
            'next_action_role' => 'Contract Manager',
        ]);
        $multiRoleManager = $this->createManager('Contract Manager');
        $this->addRoleAssignment($multiRoleManager, 'Human Resource', RoleCatalog::OFFICE);

        $this->actingAs($multiRoleManager)
            ->postJson("/api/staff/overtime/records/{$applicant->id}/{$record->id}/review", [
                'expected_version' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('data.workflow_stage', 'recommend');

        $this->postJson("/api/staff/overtime/records/{$applicant->id}/{$record->id}/recommend", [
            'expected_version' => 2,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role');
    }

    public function test_team_scoped_manager_only_sees_and_processes_overtime_in_its_team(): void
    {
        $teamAlpha = Team::query()->create(['name' => 'Alpha', 'status' => 'On Duty']);
        $teamBeta = Team::query()->create(['name' => 'Beta', 'status' => 'On Duty']);
        $alphaApplicant = $this->createApplicant($teamAlpha);
        $betaApplicant = $this->createApplicant($teamBeta);
        $alphaRecord = $this->createRecord($alphaApplicant, [
            'display_id' => 'OT-ALPHA-1',
            'workflow_snapshot' => [
                'reviewRole' => 'Client Contract Manager',
                'recommendRole' => 'Human Resource',
                'approveRole' => 'Human Resource',
                'requireRecommendation' => true,
            ],
            'next_action_role' => 'Client Contract Manager',
        ]);
        $betaRecord = $this->createRecord($betaApplicant, [
            'display_id' => 'OT-BETA-1',
            'workflow_snapshot' => [
                'reviewRole' => 'Client Contract Manager',
                'recommendRole' => 'Human Resource',
                'approveRole' => 'Human Resource',
                'requireRecommendation' => true,
            ],
            'next_action_role' => 'Client Contract Manager',
        ]);
        $manager = $this->createManager('Client Contract Manager', RoleCatalog::CLIENT_SITE, $teamAlpha);

        $this->actingAs($manager)
            ->getJson('/api/staff/overtime/records?per_page=100')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.display_id', 'OT-ALPHA-1');

        $this->getJson("/api/staff/overtime/records/{$betaApplicant->id}/{$betaRecord->id}")
            ->assertForbidden();

        $this->postJson("/api/staff/overtime/records/{$betaApplicant->id}/{$betaRecord->id}/review", [
            'expected_version' => 1,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role');

        $this->postJson("/api/staff/overtime/records/{$alphaApplicant->id}/{$alphaRecord->id}/review", [
            'expected_version' => 1,
        ])
            ->assertOk()
            ->assertJsonPath('data.workflow_stage', 'recommend');
    }

    private function createApplicant(?Team $team = null): User
    {
        $user = User::factory()->create(['status' => 'active', 'team' => $team?->name]);
        $this->addRoleAssignment($user, 'Tactical Response Team', $team ? RoleCatalog::SITE : RoleCatalog::GLOBAL, $team);

        return $user;
    }

    private function createManager(string $roleName, string $scopeType = RoleCatalog::OFFICE, ?Team $team = null): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->addRoleAssignment($user, $roleName, $scopeType, $team, true);

        return $user;
    }

    private function addRoleAssignment(
        User $user,
        string $roleName,
        string $scopeType,
        ?Team $team = null,
        bool $grantOvertimePermission = false,
    ): void {
        $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
        if ($grantOvertimePermission || in_array($roleName, ['Contract Manager', 'Human Resource', 'Client Contract Manager'], true)) {
            $permission = Permission::firstOrCreate(['name' => 'staff.overtime.manage', 'guard_name' => 'web']);
            if (! $role->hasPermissionTo($permission)) {
                $role->givePermissionTo($permission);
            }
        }

        UserRoleAssignment::query()->create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'scope_type' => $scopeType,
            'team_id' => $team?->id,
            'is_primary' => ! $user->roleAssignments()->exists(),
        ]);
    }

    private function createRecord(User $user, array $overrides = []): OvertimeRecord
    {
        return OvertimeRecord::query()->create(array_merge([
            'user_id' => $user->id,
            'display_id' => 'OT-SEC-' . random_int(1000, 9999),
            'overtime_type' => 'weekday',
            'claim_date' => now()->subDay()->toDateString(),
            'start_time' => '09:00',
            'end_time' => '10:00',
            'is_overnight' => false,
            'duration_minutes' => 60,
            'reason' => 'Completed documented handover and equipment reconciliation.',
            'status' => 'Pending',
            'applied_at' => now(),
            'workflow_stage' => 'review',
            'workflow_snapshot' => [
                'reviewRole' => 'Contract Manager',
                'recommendRole' => 'Human Resource',
                'approveRole' => 'Client Contract Manager',
                'requireRecommendation' => true,
                'enforceDistinctApprovers' => false,
            ],
            'next_action_role' => 'Contract Manager',
            'applicant_roles' => ['Tactical Response Team'],
            'approval_history' => [],
            'submitted_by' => $user->name,
            'version' => 1,
        ], $overrides));
    }
}
