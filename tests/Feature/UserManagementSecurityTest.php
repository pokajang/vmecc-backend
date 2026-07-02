<?php

namespace Tests\Feature;

use App\Models\LoginAttempt;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\UserInvitationDelivery;
use App\Models\User;
use App\Models\UserRoleAssignment;
use App\Jobs\SendUserInvitationEmailJob;
use App\Services\RoleCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserManagementSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function userWithRole(string $roleName, array $permissions): User
    {
        $user = User::factory()->create(['status' => 'Active']);
        $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        if (! empty($permissions)) {
            $role->syncPermissions($permissions);
        }

        UserRoleAssignment::create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'scope_type' => RoleCatalog::GLOBAL,
            'is_primary' => true,
        ]);

        return $user;
    }

    private function sensitiveTargetUser(): User
    {
        $target = User::factory()->create([
            'status' => 'Active',
            'ic_number' => '900101-10-1234',
            'address' => 'Sensitive address',
            'failed_login_count' => 3,
            'lock_reason' => 'security review',
            'emergency_contact' => ['name' => 'Emergency Person'],
            'banking_info' => ['bank' => 'Sensitive Bank'],
            'statutory_info' => ['epf' => '123'],
            'medical_info' => ['condition' => 'Private'],
        ]);

        LoginAttempt::create([
            'user_id' => $target->id,
            'email' => $target->email,
            'status' => 'Success',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'phpunit',
        ]);

        return $target;
    }

    public function test_client_facing_roles_cannot_read_user_management_list(): void
    {
        $this->sensitiveTargetUser();

        foreach (['Client Contract Manager', 'Representative'] as $roleName) {
            $actor = $this->userWithRole($roleName, ['teams.view', 'self.messages', 'self.dashboard']);
            $this->actingAs($actor);

            $this->getJson('/api/users')->assertForbidden();
        }
    }

    public function test_team_managers_cannot_read_user_management_list(): void
    {
        $this->sensitiveTargetUser();

        foreach (['Team Viewer', 'Team Manager'] as $roleName) {
            $actor = $this->userWithRole($roleName, ['teams.manage', 'teams.view']);
            $this->actingAs($actor);

            $this->getJson('/api/users')->assertForbidden();
        }
    }

    public function test_staff_viewer_can_read_redacted_user_list(): void
    {
        $target = $this->sensitiveTargetUser();
        $actor = $this->userWithRole('Staff Viewer', ['staff.view']);
        $this->actingAs($actor);

        $response = $this->getJson('/api/users')->assertOk();
        $row = collect($response->json('data'))->firstWhere('id', $target->id);

        $this->assertNotNull($row);
        $this->assertArrayHasKey('id', $row);
        $this->assertArrayHasKey('name', $row);
        $this->assertArrayHasKey('email', $row);
        $this->assertArrayNotHasKey('login_records', $row);
        $this->assertArrayNotHasKey('permissions', $row);
        $this->assertArrayNotHasKey('role_assignments', $row);
        $this->assertArrayNotHasKey('banking_info', $row);
        $this->assertArrayNotHasKey('statutory_info', $row);
        $this->assertArrayNotHasKey('medical_info', $row);
        $this->assertArrayNotHasKey('emergency_contact', $row);
        $this->assertArrayNotHasKey('lock_reason', $row);
        $this->assertArrayNotHasKey('failed_login_count', $row);
    }

    public function test_staff_management_roles_other_than_users_manage_get_redacted_payload(): void
    {
        $target = $this->sensitiveTargetUser();
        $roles = [
            ['Staff Manager', ['staff.manage']],
            ['Staff Leave Manager', ['staff.leave.manage']],
            ['Staff Salary Manager', ['staff.salary.manage']],
        ];

        foreach ($roles as [$roleName, $permissions]) {
            $actor = $this->userWithRole($roleName, $permissions);
            $this->actingAs($actor);

            $response = $this->getJson('/api/users')->assertOk();
            $row = collect($response->json('data'))->firstWhere('id', $target->id);

            $this->assertNotNull($row);
            $this->assertArrayNotHasKey('login_records', $row);
            $this->assertArrayNotHasKey('permissions', $row);
            $this->assertArrayNotHasKey('role_assignments', $row);
            $this->assertArrayNotHasKey('banking_info', $row);
            $this->assertArrayNotHasKey('statutory_info', $row);
            $this->assertArrayNotHasKey('medical_info', $row);
            $this->assertArrayNotHasKey('emergency_contact', $row);
            $this->assertArrayNotHasKey('lock_reason', $row);
            $this->assertArrayNotHasKey('failed_login_count', $row);
        }
    }

    public function test_user_manager_can_read_full_user_management_payload(): void
    {
        $target = $this->sensitiveTargetUser();
        $actor = $this->userWithRole('System Administrator', ['users.manage', '*']);
        $this->actingAs($actor);

        $response = $this->getJson('/api/users')->assertOk();
        $row = collect($response->json('data'))->firstWhere('id', $target->id);

        $this->assertNotNull($row);
        $this->assertArrayHasKey('login_records', $row);
        $this->assertArrayHasKey('permissions', $row);
        $this->assertArrayHasKey('role_assignments', $row);
        $this->assertArrayHasKey('banking_info', $row);
        $this->assertArrayHasKey('statutory_info', $row);
        $this->assertArrayHasKey('medical_info', $row);
        $this->assertArrayHasKey('emergency_contact', $row);
    }

    public function test_user_creation_queues_invitation_email_for_new_user(): void
    {
        Role::firstOrCreate(['name' => 'Contract Manager', 'guard_name' => 'web']);
        $actor = $this->userWithRole('System Administrator', ['users.manage', '*']);
        $this->actingAs($actor);
        Queue::fake();

        $response = $this->postJson('/api/users', [
            'name' => 'New Staff User',
            'email' => 'new.staff@example.test',
            'role_assignments' => [
                [
                    'role' => 'Contract Manager',
                    'scope_type' => RoleCatalog::OFFICE,
                    'team_id' => null,
                    'start_date' => now()->toDateString(),
                    'end_date' => null,
                    'is_primary' => true,
                ],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('invitation_sent', true)
            ->assertJsonPath('user.email', 'new.staff@example.test');

        $delivery = UserInvitationDelivery::query()
            ->where('recipient_email', 'new.staff@example.test')
            ->first();
        $this->assertNotNull($delivery);
        $this->assertSame('queued', $delivery->status);
        $this->assertSame(0, $delivery->attempts);
        Queue::assertPushed(SendUserInvitationEmailJob::class);
        $this->assertDatabaseHas('users', [
            'email' => 'new.staff@example.test',
            'status' => 'Active',
        ]);
    }

    public function test_user_creation_marks_invitation_delivery_as_failed_when_dispatch_fails(): void
    {
        Role::firstOrCreate(['name' => 'Contract Manager', 'guard_name' => 'web']);
        $actor = $this->userWithRole('System Administrator', ['users.manage', '*']);
        $this->actingAs($actor);

        config([
            'queue.connections.database.driver' => 'invalid',
        ]);

        $response = $this->postJson('/api/users', [
            'name' => 'Dispatch Failed User',
            'email' => 'dispatch.fail@example.test',
            'role_assignments' => [
                [
                    'role' => 'Contract Manager',
                    'scope_type' => RoleCatalog::OFFICE,
                    'team_id' => null,
                    'start_date' => now()->toDateString(),
                    'end_date' => null,
                    'is_primary' => true,
                ],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('invitation_sent', false)
            ->assertJsonPath('user.email', 'dispatch.fail@example.test');

        $delivery = UserInvitationDelivery::query()
            ->where('recipient_email', 'dispatch.fail@example.test')
            ->first();
        $this->assertNotNull($delivery);
        $this->assertSame('failed', $delivery->status);
        $this->assertNotNull($delivery->last_error);
    }

    public function test_user_manager_can_permanently_delete_active_user_with_force(): void
    {
        $actor = $this->userWithRole('System Administrator', ['users.manage', '*']);
        $target = User::factory()->create(['status' => 'Active']);
        $this->actingAs($actor);

        $this->deleteJson("/api/users/{$target->id}?force=1")
            ->assertOk()
            ->assertJsonPath('message', 'User permanently deleted.');

        $this->assertDatabaseMissing('users', [
            'id' => $target->id,
        ]);
    }

    public function test_team_member_options_are_manage_only_and_redacted(): void
    {
        $target = $this->sensitiveTargetUser();
        $team = Team::factory()->create(['name' => 'Smoke Team']);
        TeamMember::factory()->create([
            'team_id' => $team->id,
            'user_id' => $target->id,
            'name' => $target->name,
            'ended_at' => null,
        ]);

        $viewer = $this->userWithRole('Team Viewer', ['teams.view']);
        $this->actingAs($viewer);
        $this->getJson('/api/teams/member-options')->assertForbidden();

        $manager = $this->userWithRole('Team Manager', ['teams.view', 'teams.manage']);
        $this->actingAs($manager);
        $response = $this->getJson('/api/teams/member-options')->assertOk();
        $row = collect($response->json('data'))->firstWhere('id', $target->id);

        $this->assertNotNull($row);
        $this->assertSame('Smoke Team', $row['team']);
        $this->assertArrayHasKey('roles', $row);
        $this->assertArrayHasKey('profile_image_url', $row);
        $this->assertArrayNotHasKey('login_records', $row);
        $this->assertArrayNotHasKey('permissions', $row);
        $this->assertArrayNotHasKey('role_assignments', $row);
        $this->assertArrayNotHasKey('banking_info', $row);
        $this->assertArrayNotHasKey('statutory_info', $row);
        $this->assertArrayNotHasKey('medical_info', $row);
        $this->assertArrayNotHasKey('emergency_contact', $row);
    }
}
