<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\RoleCatalog;
use Database\Seeders\AdminUserSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminUserSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_rerunning_the_seeder_preserves_the_existing_password_and_security_state(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        config()->set('auth.bootstrap_admin.email', 'admin@example.test');
        config()->set('auth.bootstrap_admin.password', 'unused-bootstrap-password');

        $user = User::factory()->create([
            'email' => 'admin@example.test',
            'password' => Hash::make('existing-private-password'),
            'status' => 'Inactive',
            'failed_login_count' => 3,
            'locked_at' => now(),
            'lock_reason' => 'manual-review',
        ]);
        $originalHash = $user->getRawOriginal('password');

        $this->seed(AdminUserSeeder::class);
        $this->seed(AdminUserSeeder::class);

        $user->refresh();

        $this->assertSame($originalHash, $user->getRawOriginal('password'));
        $this->assertSame('Inactive', $user->status);
        $this->assertSame(3, $user->failed_login_count);
        $this->assertNotNull($user->locked_at);
        $this->assertSame('manual-review', $user->lock_reason);
        $this->assertTrue($user->hasRole('System Administrator'));
        $this->assertSame(1, $user->roleAssignments()->count());
        $this->assertDatabaseHas('user_role_assignments', [
            'user_id' => $user->id,
            'role_id' => Role::where('name', 'System Administrator')->value('id'),
            'scope_type' => RoleCatalog::GLOBAL,
            'team_id' => null,
        ]);
    }

    public function test_first_time_creation_requires_an_explicit_password_and_assigns_global_sysadmin_access(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        config()->set('auth.bootstrap_admin.email', 'admin@example.test');
        config()->set('auth.bootstrap_admin.name', 'Bootstrap Admin');
        config()->set('auth.bootstrap_admin.password', 'one-time-bootstrap-password');

        $this->seed(AdminUserSeeder::class);

        $user = User::where('email', 'admin@example.test')->firstOrFail();
        $role = Role::where('name', 'System Administrator')->firstOrFail();

        $this->assertSame('Bootstrap Admin', $user->name);
        $this->assertTrue(Hash::check('one-time-bootstrap-password', $user->password));
        $this->assertTrue($user->hasRole($role));
        $this->assertDatabaseHas('user_role_assignments', [
            'user_id' => $user->id,
            'role_id' => $role->id,
            'scope_type' => RoleCatalog::GLOBAL,
            'team_id' => null,
            'is_primary' => true,
        ]);
    }

    public function test_first_time_creation_refuses_a_missing_bootstrap_password(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        config()->set('auth.bootstrap_admin.email', 'admin@example.test');
        config()->set('auth.bootstrap_admin.password', null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('BOOTSTRAP_ADMIN_PASSWORD');

        $this->seed(AdminUserSeeder::class);
    }
}
