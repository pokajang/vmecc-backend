<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\UserRoleAssignment;
use App\Services\RoleCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Spatie\Permission\Models\Role;

class AdminUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $email = trim((string) config('auth.bootstrap_admin.email'));
        $name = trim((string) config('auth.bootstrap_admin.name'));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('BOOTSTRAP_ADMIN_EMAIL must contain a valid email address.');
        }

        $user = User::withTrashed()->where('email', $email)->first();

        if ($user?->trashed()) {
            throw new RuntimeException('The bootstrap administrator is deleted. Restore it explicitly before seeding.');
        }

        if (! $user) {
            $password = (string) config('auth.bootstrap_admin.password');
            if (strlen($password) < 12) {
                throw new RuntimeException(
                    'Set BOOTSTRAP_ADMIN_PASSWORD to at least 12 characters before creating the bootstrap administrator.'
                );
            }

            $user = User::create([
                'email' => $email,
                'name' => $name !== '' ? $name : 'System Administrator',
                'password' => Hash::make($password),
                'email_verified_at' => now(),
                'status' => 'Active',
                'failed_login_count' => 0,
                'locked_at' => null,
                'locked_by' => null,
                'lock_reason' => null,
            ]);
        }

        $role = Role::query()
            ->where('name', 'System Administrator')
            ->where('guard_name', 'web')
            ->firstOrFail();

        $user->assignRole($role);

        $assignment = UserRoleAssignment::firstOrNew([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'scope_type' => RoleCatalog::GLOBAL,
            'team_id' => null,
        ]);

        if (! $assignment->exists) {
            $assignment->is_primary = ! $user->roleAssignments()->where('is_primary', true)->exists();
        }

        $assignment->start_date = null;
        $assignment->end_date = null;
        $assignment->save();
    }
}
