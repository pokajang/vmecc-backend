<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\UserRoleAssignment;
use App\Services\RoleCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class SmokeAdminSeeder extends Seeder
{
    public const EMAIL = 'codex.smoke.admin@vmecc.local';
    public const PASSWORD = 'SmokeAdmin!2026';

    public function run(): void
    {
        $user = User::withTrashed()->updateOrCreate(
            ['email' => self::EMAIL],
            [
                'name' => 'Codex Smoke Admin',
                'password' => Hash::make(self::PASSWORD),
                'email_verified_at' => now(),
                'status' => 'Active',
                'failed_login_count' => 0,
                'locked_at' => null,
                'locked_by' => null,
                'lock_reason' => null,
            ]
        );

        if ($user->trashed()) {
            $user->restore();
        }

        $role = Role::query()
            ->where('name', 'System Administrator')
            ->where('guard_name', 'web')
            ->firstOrFail();

        $user->syncRoles(['System Administrator']);

        UserRoleAssignment::updateOrCreate(
            [
                'user_id' => $user->id,
                'role_id' => $role->id,
                'scope_type' => RoleCatalog::GLOBAL,
                'team_id' => null,
            ],
            [
                'start_date' => null,
                'end_date' => null,
                'is_primary' => true,
            ]
        );
    }
}
