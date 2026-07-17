<?php

namespace Tests\Unit;

use App\Services\RoleCatalog;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class WorkflowRoleCatalogMatrixTest extends TestCase
{
    #[DataProvider('workflowPermissionMatrix')]
    public function test_catalogued_role_has_the_expected_workflow_permissions(
        string $role,
        array $expectedPermissions,
    ): void {
        $this->assertContains($role, RoleCatalog::ROLES);
        $actual = RoleCatalog::ROLE_PERMISSIONS[$role] ?? [];

        foreach ($expectedPermissions as $permission => $expected) {
            $hasPermission = in_array('*', $actual, true) || in_array($permission, $actual, true);
            $this->assertSame(
                $expected,
                $hasPermission,
                "Unexpected {$permission} workflow permission for {$role}.",
            );
        }
    }

    public function test_role_catalog_and_priority_cover_the_same_roles(): void
    {
        $roles = RoleCatalog::ROLES;
        $priorities = array_keys(RoleCatalog::ROLE_PRIORITY);

        sort($roles);
        sort($priorities);

        $this->assertSame($roles, $priorities);
    }

    public static function workflowPermissionMatrix(): array
    {
        $permissions = [
            'staff.leave.manage',
            'staff.overtime.manage',
            'staff.salary.manage',
            'staff.salary.pay',
            'reports.manage',
        ];
        $row = static function (array $allowed) use ($permissions): array {
            return array_replace(
                array_fill_keys($permissions, false),
                array_fill_keys($allowed, true),
            );
        };

        return [
            'System Administrator' => ['System Administrator', $row($permissions)],
            'Contract Manager' => ['Contract Manager', $row([
                'staff.overtime.manage',
                'staff.salary.manage',
                'reports.manage',
            ])],
            'Human Resource' => ['Human Resource', $row([
                'staff.leave.manage',
                'staff.overtime.manage',
                'staff.salary.manage',
            ])],
            'Finance' => ['Finance', $row([
                'staff.salary.manage',
                'staff.salary.pay',
            ])],
            'Admin' => ['Admin', $row(['staff.salary.manage'])],
            'Incident Commander' => ['Incident Commander', $row(['reports.manage'])],
            'Assistant Incident Commander' => ['Assistant Incident Commander', $row(['reports.manage'])],
            'Tactical Response Team' => ['Tactical Response Team', $row(['reports.manage'])],
            'Client Contract Manager' => ['Client Contract Manager', $row(['staff.overtime.manage'])],
            'Representative' => ['Representative', $row([])],
        ];
    }
}
