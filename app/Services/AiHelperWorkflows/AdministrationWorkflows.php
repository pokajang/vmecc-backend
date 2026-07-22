<?php

namespace App\Services\AiHelperWorkflows;

final class AdministrationWorkflows
{
    /** @return array<int, array<string, mixed>> */
    public static function definitions(): array
    {
        return [
            self::workflow('users.manage', 'user-administration', 'User Management', 'Administer User Accounts', [
                ['key' => 'open_users', 'kind' => 'open_menu', 'target' => 'User Management'],
                ['key' => 'choose_user_action', 'kind' => 'choose', 'targets' => ['Create user', 'an existing user row action']],
                ['key' => 'create_user', 'kind' => 'branch', 'target' => 'Create user', 'targets' => ['Name', 'Email', 'Role Assignment']],
                ['key' => 'manage_user', 'kind' => 'branch_choose', 'target' => 'an existing user', 'targets' => ['Activate', 'Deactivate', 'Lock', 'Unlock', 'Delete', 'Restore']],
                ['key' => 'verify_user', 'kind' => 'verify', 'targets' => ['Status', 'Audit History']],
            ]),
            self::workflow('roles.permissions.manage', 'role-permissions', 'Settings', 'Manage Role Permissions', [
                ['key' => 'open_settings', 'kind' => 'open_menu', 'target' => 'Settings'],
                ['key' => 'open_permissions', 'kind' => 'select', 'target' => 'Role Permissions'],
                ['key' => 'select_role', 'kind' => 'complete', 'targets' => ['Permission Matrix', 'Role']],
                ['key' => 'edit_permissions', 'kind' => 'review', 'targets' => ['Edit', 'Save']],
                ['key' => 'verify_permissions', 'kind' => 'verify', 'targets' => ['Updated Roles', 'Representative Session']],
            ]),
            self::workflow('settings.module_activation', 'module-activation', 'Settings', 'Configure Module Activation', [
                ['key' => 'open_settings', 'kind' => 'open_menu', 'target' => 'Settings'],
                ['key' => 'open_modules', 'kind' => 'select', 'target' => 'Module Activation'],
                ['key' => 'select_module', 'kind' => 'complete', 'targets' => ['Feature', 'Parent', 'Dependencies', 'Current State']],
                ['key' => 'save_modules', 'kind' => 'review', 'targets' => ['Toggle', 'Save']],
                ['key' => 'verify_modules', 'kind' => 'verify', 'targets' => ['Navigation', 'Authorized User Access']],
            ]),
        ];
    }

    private static function workflow(string $key, string $guideKey, string $module, string $action, array $steps): array
    {
        return [
            'key' => $key,
            'guide_key' => $guideKey,
            'task_keys' => [$key],
            'entity_keys' => [],
            'module' => $module,
            'action' => $action,
            'type' => $action,
            'source_labels' => match ($key) {
                'users.manage' => ['User Management', 'Create user', 'Activate', 'Deactivate', 'Lock', 'Unlock', 'Delete', 'Restore'],
                'roles.permissions.manage' => ['Settings', 'Role Permissions', 'Permission Matrix', 'Edit', 'Save'],
                'settings.module_activation' => ['Settings', 'Module Activation', 'Save', 'Enabled', 'Disabled'],
            },
            'steps' => $steps,
            'ui' => ['actions' => [], 'fields' => [], 'statuses' => []],
        ];
    }
}
