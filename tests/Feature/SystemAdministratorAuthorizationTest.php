<?php

namespace Tests\Feature;

use App\Http\Middleware\PermissionAssignmentMiddleware;
use App\Http\Middleware\PermissionAssignmentScopeMiddleware;
use App\Models\User;
use App\Models\UserRoleAssignment;
use App\Services\AssignmentAuthorizationService;
use App\Services\RoleCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SystemAdministratorAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_system_administrator_bypasses_every_permission_path_without_permission_rows(): void
    {
        $user = $this->createAdministratorWithAssignment();
        $authorization = app(AssignmentAuthorizationService::class);

        $this->assertTrue($authorization->isSystemAdministrator($user));
        $this->assertTrue($authorization->hasPermission($user, 'permission.added.in.the.future'));
        $this->assertTrue($authorization->hasPermission($user, 'team.restricted.permission', 999));
        $this->assertTrue(Gate::forUser($user)->allows('policy.added.in.the.future'));

        $request = Request::create('/protected', 'GET', ['team_id' => 999]);
        $request->setUserResolver(fn () => $user);

        $permissionResponse = app(PermissionAssignmentMiddleware::class)->handle(
            $request,
            fn () => response()->json(['allowed' => true]),
            'permission.not.assigned'
        );
        $scopeResponse = app(PermissionAssignmentScopeMiddleware::class)->handle(
            $request,
            fn () => response()->json(['allowed' => true]),
            'permission.not.assigned'
        );

        $this->assertSame(200, $permissionResponse->getStatusCode());
        $this->assertSame(200, $scopeResponse->getStatusCode());
    }

    public function test_expired_system_administrator_assignment_does_not_fall_back_to_legacy_role(): void
    {
        $user = $this->createAdministratorWithAssignment([
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->subDay()->toDateString(),
        ]);
        $authorization = app(AssignmentAuthorizationService::class);

        $this->assertFalse($authorization->isSystemAdministrator($user));
        $this->assertFalse($authorization->hasPermission($user, 'settings.manage'));
        $this->assertSame([], $authorization->getActiveRoleNames($user)->all());
        $this->assertSame([], $authorization->getActivePermissionNames($user)->all());
        $this->assertFalse(Gate::forUser($user)->allows('settings.manage'));
    }

    public function test_future_system_administrator_assignment_is_not_active(): void
    {
        $user = $this->createAdministratorWithAssignment([
            'start_date' => now()->addDay()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
        ]);

        $this->assertFalse(app(AssignmentAuthorizationService::class)->isSystemAdministrator($user));
    }

    public function test_legacy_system_administrator_role_without_assignment_still_bypasses_permissions(): void
    {
        $role = Role::firstOrCreate(['name' => 'System Administrator', 'guard_name' => 'web']);
        $user = User::factory()->create(['status' => 'active']);
        $user->syncRoles([$role]);

        $authorization = app(AssignmentAuthorizationService::class);

        $this->assertFalse($user->roleAssignments()->exists());
        $this->assertTrue($authorization->isSystemAdministrator($user));
        $this->assertTrue($authorization->hasPermission($user, 'permission.not.in.database'));
    }

    public function test_non_administrator_without_permission_remains_forbidden(): void
    {
        $role = Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);
        $user = User::factory()->create(['status' => 'active']);
        UserRoleAssignment::create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'scope_type' => RoleCatalog::GLOBAL,
            'is_primary' => true,
        ]);

        $authorization = app(AssignmentAuthorizationService::class);

        $this->assertFalse($authorization->isSystemAdministrator($user));
        $this->assertFalse($authorization->hasPermission($user, 'settings.manage'));
    }

    public function test_role_permission_endpoint_exposes_explicit_full_access_contract(): void
    {
        $user = $this->createAdministratorWithAssignment();
        $this->actingAs($user);

        $this->getJson('/api/settings/role-permissions')
            ->assertOk()
            ->assertJsonPath('role_access.System Administrator.full_access', true)
            ->assertJsonPath('role_access.System Administrator.permissions_locked', true)
            ->assertJsonPath('role_access.Admin.full_access', false)
            ->assertJsonPath('role_access.Admin.permissions_locked', false);
    }

    public function test_access_reconciliation_backfills_legacy_administrator_assignment_idempotently(): void
    {
        $role = Role::firstOrCreate(['name' => 'System Administrator', 'guard_name' => 'web']);
        $user = User::factory()->create(['status' => 'active']);
        $user->syncRoles([$role]);

        $migration = require database_path(
            'migrations/2026_07_16_000004_reconcile_system_administrator_access.php'
        );
        $migration->up();
        $migration->up();

        $this->assertSame(1, $user->roleAssignments()->where('role_id', $role->id)->count());
        $this->assertDatabaseHas('user_role_assignments', [
            'user_id' => $user->id,
            'role_id' => $role->id,
            'scope_type' => RoleCatalog::GLOBAL,
            'team_id' => null,
        ]);
        $this->assertSame(
            [],
            array_values(array_diff(RoleCatalog::allPermissions(), $role->fresh()->permissions->pluck('name')->all()))
        );
    }

    public function test_access_reconciliation_ignores_orphaned_legacy_role_pivots(): void
    {
        $role = Role::firstOrCreate(['name' => 'System Administrator', 'guard_name' => 'web']);
        $orphanedUserId = 999999;

        DB::table('model_has_roles')->insert([
            'role_id' => $role->id,
            'model_type' => User::class,
            'model_id' => $orphanedUserId,
        ]);

        $migration = require database_path(
            'migrations/2026_07_16_000004_reconcile_system_administrator_access.php'
        );
        $migration->up();

        $this->assertDatabaseMissing('user_role_assignments', [
            'user_id' => $orphanedUserId,
            'role_id' => $role->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $assignmentOverrides
     */
    private function createAdministratorWithAssignment(array $assignmentOverrides = []): User
    {
        $role = Role::firstOrCreate(['name' => 'System Administrator', 'guard_name' => 'web']);
        $user = User::factory()->create(['status' => 'active']);

        // Preserve the legacy Spatie assignment to prove dated assignments remain authoritative.
        $user->syncRoles([$role]);
        UserRoleAssignment::create(array_merge([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'scope_type' => RoleCatalog::GLOBAL,
            'is_primary' => true,
        ], $assignmentOverrides));

        return $user;
    }
}
