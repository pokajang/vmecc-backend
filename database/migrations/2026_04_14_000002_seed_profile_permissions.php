<?php

use App\Services\RoleCatalog;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    private const NEW_PERMISSIONS = [
        'self.profile.banking',
        'self.profile.medical',
        'self.profile.emergency',
    ];

    public function up(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // Create the new permissions
        foreach (self::NEW_PERMISSIONS as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        // Grant to all internal roles (those that have self.dashboard)
        // Withheld from CCM and Representative (client-facing)
        $internalRoles = array_filter(RoleCatalog::ROLES, function ($roleName) {
            $perms = RoleCatalog::ROLE_PERMISSIONS[$roleName] ?? [];
            return in_array('self.leave', $perms, true); // proxy: internal = has self.leave
        });

        foreach ($internalRoles as $roleName) {
            $role = Role::where('name', $roleName)->first();
            if (!$role) continue;
            foreach (self::NEW_PERMISSIONS as $permName) {
                $perm = Permission::where('name', $permName)->first();
                if ($perm && !$role->hasPermissionTo($perm)) {
                    $role->givePermissionTo($perm);
                }
            }
        }

        // Sync SysAdmin to all permissions
        $sysAdmin = Role::where('name', 'System Administrator')->first();
        if ($sysAdmin) {
            $sysAdmin->syncPermissions(Permission::all());
        }

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function down(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        foreach (self::NEW_PERMISSIONS as $name) {
            $perm = Permission::where('name', $name)->first();
            if ($perm) {
                $perm->roles()->detach();
                $perm->delete();
            }
        }

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }
};
