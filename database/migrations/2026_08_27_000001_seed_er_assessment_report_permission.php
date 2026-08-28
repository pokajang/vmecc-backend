<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const PERMISSION = 'reports.er_assessment.view';

    public function up(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $permission = Permission::firstOrCreate([
            'name' => self::PERMISSION,
            'guard_name' => 'web',
        ]);

        Role::query()
            ->where('guard_name', 'web')
            ->with('permissions')
            ->get()
            ->each(function (Role $role) use ($permission): void {
                $existing = $role->permissions->pluck('name')->all();
                if (
                    ($role->name === 'System Administrator' || in_array('reports.manage', $existing, true))
                    && ! in_array(self::PERMISSION, $existing, true)
                ) {
                    $role->givePermissionTo($permission);
                }
            });

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function down(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        $permission = Permission::query()
            ->where('name', self::PERMISSION)
            ->where('guard_name', 'web')
            ->first();

        if ($permission) {
            $permission->roles()->detach();
            $permission->delete();
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
};
