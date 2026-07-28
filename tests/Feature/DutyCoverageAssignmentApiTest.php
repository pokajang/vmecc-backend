<?php

namespace Tests\Feature;

use App\Models\Team;
use App\Models\User;
use App\Models\UserRoleAssignment;
use App\Services\RoleCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DutyCoverageAssignmentApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_can_create_and_cancel_qualified_cross_team_coverage(): void
    {
        $manager = $this->manager();
        $homeTeam = Team::factory()->create(['name' => 'Home Team']);
        $actingTeam = Team::factory()->create(['name' => 'Acting Team']);
        $substitute = User::factory()->create(['status' => 'Active']);
        $role = $this->assign($substitute, 'Assistant Incident Commander', $homeTeam->id);

        $response = $this->actingAs($manager)->postJson('/api/duty-coverage', [
            'user_id' => $substitute->id,
            'acting_team_id' => $actingTeam->id,
            'acting_role' => $role->name,
            'effective_from' => now()->subMinute()->toIso8601String(),
            'effective_until' => now()->addHours(8)->toIso8601String(),
            'reason' => 'Planned shift substitution.',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.user.id', $substitute->id)
            ->assertJsonPath('data.homeTeam.id', $homeTeam->id)
            ->assertJsonPath('data.actingTeam.id', $actingTeam->id)
            ->assertJsonPath('data.status', 'active');

        $assignmentId = (int) $response->json('data.id');
        $this->patchJson("/api/duty-coverage/{$assignmentId}", [
            'reason' => 'Coverage details confirmed.',
        ])->assertOk()
            ->assertJsonPath('data.reason', 'Coverage details confirmed.');

        $this->postJson("/api/duty-coverage/{$assignmentId}/cancel", [
            'reason' => 'Original AIC returned.',
        ])->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
    }

    public function test_coverage_rejects_role_elevation_and_overlapping_windows(): void
    {
        $manager = $this->manager();
        $team = Team::factory()->create();
        $qualified = User::factory()->create(['status' => 'Active']);
        $unqualified = User::factory()->create(['status' => 'Active']);
        $role = $this->assign($qualified, 'Assistant Incident Commander', null);
        $payload = [
            'acting_team_id' => $team->id,
            'acting_role' => $role->name,
            'effective_from' => now()->addHour()->toIso8601String(),
            'effective_until' => now()->addHours(4)->toIso8601String(),
        ];

        $this->actingAs($manager)->postJson('/api/duty-coverage', [
            ...$payload,
            'user_id' => $unqualified->id,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['acting_role']);

        $this->postJson('/api/duty-coverage', [
            ...$payload,
            'user_id' => $qualified->id,
        ])->assertCreated();

        $this->postJson('/api/duty-coverage', [
            ...$payload,
            'user_id' => $qualified->id,
            'effective_from' => now()->addHours(2)->toIso8601String(),
            'effective_until' => now()->addHours(5)->toIso8601String(),
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['effective_from']);
    }

    public function test_coverage_accepts_legacy_null_status_but_rejects_inactive_users(): void
    {
        $manager = $this->manager();
        $homeTeam = Team::factory()->create(['name' => 'Legacy Home']);
        $actingTeam = Team::factory()->create(['name' => 'Legacy Acting']);
        $legacyUser = User::factory()->create(['status' => null]);
        $inactiveUser = User::factory()->create(['status' => 'Inactive']);
        $role = $this->assign($legacyUser, 'Assistant Incident Commander', $homeTeam->id);
        $this->assign($inactiveUser, 'Assistant Incident Commander', $homeTeam->id);
        $payload = [
            'acting_team_id' => $actingTeam->id,
            'acting_role' => $role->name,
            'effective_from' => now()->addHour()->toIso8601String(),
            'effective_until' => now()->addHours(4)->toIso8601String(),
        ];

        $this->actingAs($manager)->postJson('/api/duty-coverage', [
            ...$payload,
            'user_id' => $legacyUser->id,
        ])->assertCreated();

        $this->postJson('/api/duty-coverage', [
            ...$payload,
            'user_id' => $inactiveUser->id,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['user_id']);
    }

    public function test_team_scoped_manager_cannot_manage_another_teams_coverage(): void
    {
        $allowedTeam = Team::factory()->create(['name' => 'Allowed Coverage Team']);
        $otherTeam = Team::factory()->create(['name' => 'Protected Coverage Team']);
        $manager = $this->manager($allowedTeam->id);
        $substitute = User::factory()->create(['status' => 'Active']);
        $role = $this->assign(
            $substitute,
            'Assistant Incident Commander',
            $allowedTeam->id,
        );

        $this->actingAs($manager)->postJson('/api/duty-coverage', [
            'user_id' => $substitute->id,
            'acting_team_id' => $otherTeam->id,
            'acting_role' => $role->name,
            'effective_from' => now()->addHour()->toIso8601String(),
            'effective_until' => now()->addHours(4)->toIso8601String(),
        ])->assertForbidden();

        $this->getJson("/api/duty-coverage?teamId={$otherTeam->id}")->assertForbidden();
    }

    private function manager(?int $teamId = null): User
    {
        $permission = Permission::query()->firstOrCreate([
            'name' => 'teams.manage',
            'guard_name' => 'web',
        ]);
        $role = Role::query()->firstOrCreate([
            'name' => 'Admin',
            'guard_name' => 'web',
        ]);
        $role->givePermissionTo($permission);
        $manager = User::factory()->create(['status' => 'Active']);
        UserRoleAssignment::query()->create([
            'user_id' => $manager->id,
            'role_id' => $role->id,
            'scope_type' => $teamId ? RoleCatalog::SITE : RoleCatalog::GLOBAL,
            'team_id' => $teamId,
            'is_primary' => true,
        ]);

        return $manager;
    }

    private function assign(User $user, string $roleName, ?int $teamId): Role
    {
        $role = Role::query()->firstOrCreate([
            'name' => $roleName,
            'guard_name' => 'web',
        ]);
        UserRoleAssignment::query()->create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'scope_type' => $teamId ? RoleCatalog::SITE : RoleCatalog::GLOBAL,
            'team_id' => $teamId,
            'is_primary' => true,
        ]);

        return $role;
    }
}
