<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const PERMISSION = 'staff.salary.pay';

    public function up(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $permission = Permission::firstOrCreate([
            'name' => self::PERMISSION,
            'guard_name' => 'web',
        ]);

        $financeRole = Role::where('name', 'Finance')->where('guard_name', 'web')->first();
        if ($financeRole && ! $financeRole->hasPermissionTo($permission)) {
            $financeRole->givePermissionTo($permission);
        }

        $sysAdminRole = Role::where('name', 'System Administrator')->where('guard_name', 'web')->first();
        if ($sysAdminRole && ! $sysAdminRole->hasPermissionTo($permission)) {
            $sysAdminRole->givePermissionTo($permission);
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function down(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $permission = Permission::where('name', self::PERMISSION)
            ->where('guard_name', 'web')
            ->first();

        if ($permission) {
            $permission->roles()->detach();
            $permission->delete();
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
};
