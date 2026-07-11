<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    public function up(): void
    {
        $permission = Permission::firstOrCreate([
            'name' => 'staff.overtime.manage',
            'guard_name' => 'web',
        ]);

        foreach (['Contract Manager', 'Client Contract Manager'] as $roleName) {
            $role = Role::query()->where('name', $roleName)->where('guard_name', 'web')->first();
            if ($role && ! $role->hasPermissionTo($permission)) {
                $role->givePermissionTo($permission);
            }
        }
    }

    public function down(): void
    {
        $permission = Permission::query()
            ->where('name', 'staff.overtime.manage')
            ->where('guard_name', 'web')
            ->first();
        if (! $permission) {
            return;
        }

        foreach (['Contract Manager', 'Client Contract Manager'] as $roleName) {
            $role = Role::query()->where('name', $roleName)->where('guard_name', 'web')->first();
            if ($role && $role->hasPermissionTo($permission)) {
                $role->revokePermissionTo($permission);
            }
        }
    }
};
