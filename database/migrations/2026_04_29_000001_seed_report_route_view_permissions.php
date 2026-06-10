<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    private const PERMISSIONS = [
        'reports.inspection.view',
        'reports.erco.view',
        'reports.drill.view',
        'reports.fitness.view',
    ];

    public function up(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $permissionName) {
            Permission::firstOrCreate([
                'name' => $permissionName,
                'guard_name' => 'web',
            ]);
        }

        $roles = Role::query()
            ->where('guard_name', 'web')
            ->with('permissions')
            ->get();

        foreach ($roles as $role) {
            $existing = $role->permissions->pluck('name')->all();
            $shouldGrant =
                $role->name === 'System Administrator' ||
                in_array('reports.manage', $existing, true);

            if (! $shouldGrant) {
                continue;
            }

            $missing = array_values(array_diff(self::PERMISSIONS, $existing));
            if (! empty($missing)) {
                $role->givePermissionTo($missing);
            }
        }

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function down(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $roles = Role::query()
            ->where('guard_name', 'web')
            ->with('permissions')
            ->get();

        foreach ($roles as $role) {
            $existing = $role->permissions->pluck('name')->all();
            $toRevoke = array_values(array_intersect($existing, self::PERMISSIONS));
            if (! empty($toRevoke)) {
                $role->revokePermissionTo($toRevoke);
            }
        }

        Permission::query()
            ->where('guard_name', 'web')
            ->whereIn('name', self::PERMISSIONS)
            ->delete();

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }
};

