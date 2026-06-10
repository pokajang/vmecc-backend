<?php

namespace Database\Seeders;

use App\Services\RoleCatalog;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        foreach (RoleCatalog::allPermissions() as $permission) {
            Permission::firstOrCreate([
                'name' => $permission,
                'guard_name' => 'web',
            ]);
        }

        foreach (RoleCatalog::ROLES as $roleName) {
            $role = Role::firstOrCreate([
                'name' => $roleName,
                'guard_name' => 'web',
            ]);

            if ($roleName === 'System Administrator') {
                $role->syncPermissions(Permission::query()->pluck('name')->values()->all());
                continue;
            }
            $role->syncPermissions(RoleCatalog::ROLE_PERMISSIONS[$roleName] ?? []);
        }

        // Backward-compatible alias for legacy "Client" role if it still exists in old data.
        $legacyClient = Role::firstOrCreate([
            'name' => 'Client',
            'guard_name' => 'web',
        ]);
        $legacyClient->syncPermissions(RoleCatalog::ROLE_PERMISSIONS['Representative']);
    }
}
