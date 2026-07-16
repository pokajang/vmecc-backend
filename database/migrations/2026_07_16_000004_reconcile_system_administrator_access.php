<?php

use App\Models\User;
use App\Services\RoleCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('roles') || ! Schema::hasTable('user_role_assignments')) {
            return;
        }

        $administrator = Role::firstOrCreate([
            'name' => 'System Administrator',
            'guard_name' => 'web',
        ]);

        $permissions = collect(RoleCatalog::allPermissions())->map(
            fn (string $name) => Permission::firstOrCreate([
                'name' => $name,
                'guard_name' => 'web',
            ])
        );
        $administrator->givePermissionTo($permissions);

        DB::table('user_role_assignments')
            ->where('role_id', $administrator->id)
            ->update([
                'scope_type' => RoleCatalog::GLOBAL,
                'team_id' => null,
                'updated_at' => now(),
            ]);

        if (Schema::hasTable('model_has_roles')) {
            $legacyAdministratorIds = DB::table('model_has_roles')
                ->join('users', 'users.id', '=', 'model_has_roles.model_id')
                ->where('role_id', $administrator->id)
                ->where('model_type', User::class)
                ->pluck('model_has_roles.model_id');

            foreach ($legacyAdministratorIds as $userId) {
                $hasAdministratorAssignment = DB::table('user_role_assignments')
                    ->where('user_id', $userId)
                    ->where('role_id', $administrator->id)
                    ->exists();

                if ($hasAdministratorAssignment) {
                    continue;
                }

                $hasPrimaryAssignment = DB::table('user_role_assignments')
                    ->where('user_id', $userId)
                    ->where('is_primary', true)
                    ->exists();

                DB::table('user_role_assignments')->insert([
                    'user_id' => $userId,
                    'role_id' => $administrator->id,
                    'scope_type' => RoleCatalog::GLOBAL,
                    'team_id' => null,
                    'start_date' => null,
                    'end_date' => null,
                    'is_primary' => ! $hasPrimaryAssignment,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Access reconciliation is intentionally irreversible.
    }
};
