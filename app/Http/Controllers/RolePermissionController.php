<?php

namespace App\Http\Controllers;

use App\Services\AuditLogger;
use App\Services\RoleCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolePermissionController extends Controller
{
    /**
     * GET /settings/role-permissions
     *
     * Returns the full matrix: all roles, all permissions, and which permissions
     * each role currently has. System Administrator is always shown as locked (*).
     */
    public function index(): JsonResponse
    {
        $permissions = RoleCatalog::allPermissions();

        $roles = Role::with('permissions')
            ->whereIn('name', RoleCatalog::ROLES)
            ->get()
            ->keyBy('name');

        $matrix = [];
        foreach (RoleCatalog::ROLES as $roleName) {
            $role = $roles->get($roleName);

            if ($roleName === 'System Administrator') {
                $matrix[$roleName] = ['*'];
                continue;
            }

            $assigned = $role
                ? $role->permissions->pluck('name')->values()->all()
                : [];

            $matrix[$roleName] = $assigned;
        }

        return response()->json([
            'permissions' => $permissions,
            'roles'       => RoleCatalog::ROLES,
            'matrix'      => $matrix,
        ]);
    }

    /**
     * PUT /settings/role-permissions
     *
     * Accepts { matrix: { "Role Name": ["perm.one", "perm.two"], ... } }
     * Syncs Spatie role_has_permissions for every non-SysAdmin role.
     * Audit-logs the change.
     */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'matrix'   => ['required', 'array'],
            'matrix.*' => ['present', 'array'],
            'matrix.*.*' => ['string'],
        ]);

        $allowedPermissions = array_flip(RoleCatalog::allPermissions());

        $before = [];
        $after  = [];
        $changed = [];

        foreach ($data['matrix'] as $roleName => $permissionNames) {
            // Silently skip System Administrator — it cannot be modified.
            if ($roleName === 'System Administrator') {
                continue;
            }

            // Skip unknown roles.
            if (!in_array($roleName, RoleCatalog::ROLES, true)) {
                continue;
            }

            // Strip any permission names not in the catalog (defense-in-depth).
            $sanitized = array_values(
                array_filter($permissionNames, fn ($p) => isset($allowedPermissions[$p]))
            );

            $role = Role::firstOrCreate(
                ['name' => $roleName, 'guard_name' => 'web']
            );

            $before[$roleName] = $role->permissions->pluck('name')->sort()->values()->all();

            // Ensure all catalog permissions exist first.
            $permissionModels = collect($sanitized)->map(
                fn ($name) => Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web'])
            );

            $role->syncPermissions($permissionModels);

            $after[$roleName] = collect($sanitized)->sort()->values()->all();

            if ($before[$roleName] !== $after[$roleName]) {
                $changed[] = $roleName;
            }
        }

        // Clear Spatie permission cache so changes take effect immediately.
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        if (!empty($changed)) {
            AuditLogger::log($request, 'role_permissions_updated', null, [
                'changed_roles' => $changed,
                'before'        => array_intersect_key($before, array_flip($changed)),
                'after'         => array_intersect_key($after, array_flip($changed)),
            ]);
        }

        return response()->json([
            'message' => 'Role permissions updated.',
            'changed' => $changed,
        ]);
    }
}
