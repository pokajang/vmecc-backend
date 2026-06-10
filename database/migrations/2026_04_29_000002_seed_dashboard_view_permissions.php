<?php

use App\Services\RoleCatalog;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    private const PERMISSIONS = [
        'dashboard.payroll.view',
        'dashboard.overtime.view',
        'dashboard.leave.view',
        'dashboard.roster.view',
        'dashboard.reports.view',
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

        foreach (RoleCatalog::ROLES as $roleName) {
            $role = Role::firstOrCreate([
                'name' => $roleName,
                'guard_name' => 'web',
            ]);

            if ($roleName === 'System Administrator') {
                $missing = array_values(array_diff(self::PERMISSIONS, $role->permissions->pluck('name')->all()));
                if (! empty($missing)) {
                    $role->givePermissionTo($missing);
                }
                continue;
            }

            $catalogPerms = RoleCatalog::ROLE_PERMISSIONS[$roleName] ?? [];
            $dashboardPerms = array_values(
                array_filter(
                    $catalogPerms,
                    fn ($permission) => str_starts_with((string) $permission, 'dashboard.')
                )
            );

            if (empty($dashboardPerms)) {
                continue;
            }

            $existingNames = $role->permissions->pluck('name')->all();
            $missing = array_values(array_diff($dashboardPerms, $existingNames));
            if (! empty($missing)) {
                $role->givePermissionTo($missing);
            }
        }

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function down(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        foreach (Role::query()->where('guard_name', 'web')->get() as $role) {
            $assigned = array_values(array_intersect($role->permissions->pluck('name')->all(), self::PERMISSIONS));
            if (! empty($assigned)) {
                $role->revokePermissionTo($assigned);
            }
        }

        Permission::query()
            ->where('guard_name', 'web')
            ->whereIn('name', self::PERMISSIONS)
            ->delete();

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }
};

