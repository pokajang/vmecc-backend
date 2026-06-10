<?php

use App\Services\RoleCatalog;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    public function up(): void
    {
        // Ensure all catalog permissions exist (idempotent)
        foreach (RoleCatalog::allPermissions() as $permissionName) {
            Permission::firstOrCreate(['name' => $permissionName, 'guard_name' => 'web']);
        }

        // Sync permissions for every non-SysAdmin role from the catalog defaults.
        // SysAdmin keeps its wildcard (*) and is handled separately by the seeder.
        foreach (RoleCatalog::ROLES as $roleName) {
            if ($roleName === 'System Administrator') {
                // Ensure SysAdmin has every permission (including newly added ones)
                $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
                $role->syncPermissions(Permission::query()->pluck('name')->values()->all());
                continue;
            }

            $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
            $catalogPerms = RoleCatalog::ROLE_PERMISSIONS[$roleName] ?? [];

            // Only grant new self.* permissions that the role doesn't already have.
            // This preserves any manual changes made via the Role Permission Matrix UI.
            $existingNames = $role->permissions->pluck('name')->all();
            $newPerms = array_filter(
                $catalogPerms,
                fn ($p) => str_starts_with($p, 'self.') && !in_array($p, $existingNames, true)
            );

            if (!empty($newPerms)) {
                $role->givePermissionTo($newPerms);
            }
        }

        // Clear Spatie permission cache
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function down(): void
    {
        $selfPerms = ['self.dashboard', 'self.messages', 'self.leave', 'self.overtime', 'self.payroll'];

        foreach (Role::all() as $role) {
            $role->revokePermissionTo(
                array_intersect($role->permissions->pluck('name')->all(), $selfPerms)
            );
        }

        foreach ($selfPerms as $permName) {
            Permission::where('name', $permName)->where('guard_name', 'web')->delete();
        }

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }
};
