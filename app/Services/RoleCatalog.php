<?php

namespace App\Services;

class RoleCatalog
{
    public const GLOBAL = 'global';
    public const OFFICE = 'office';
    public const SITE = 'site';
    public const CLIENT_SITE = 'client_site';

    public const ROLES = [
        'System Administrator',
        'Contract Manager',
        'Human Resource',
        'Finance',
        'Admin',
        'Incident Commander',
        'Assistant Incident Commander',
        'Tactical Response Team',
        'Client Contract Manager',
        'Representative',
    ];

    public const ROLE_PRIORITY = [
        'System Administrator' => 100,
        'Admin' => 90,
        'Human Resource' => 80,
        'Finance' => 70,
        'Contract Manager' => 60,
        'Incident Commander' => 50,
        'Assistant Incident Commander' => 40,
        'Tactical Response Team' => 30,
        'Client Contract Manager' => 20,
        'Representative' => 10,
    ];

    public const ROLE_SCOPE = [
        'System Administrator' => self::GLOBAL,
        'Contract Manager' => self::OFFICE,
        'Human Resource' => self::OFFICE,
        'Finance' => self::OFFICE,
        'Admin' => self::OFFICE,
        'Incident Commander' => self::SITE,
        'Assistant Incident Commander' => self::SITE,
        'Tactical Response Team' => self::SITE,
        'Client Contract Manager' => self::CLIENT_SITE,
        'Representative' => self::CLIENT_SITE,
    ];

    // Self-service permissions granted to all internal roles but withheld from client-facing roles.
    private const SELF_SERVICE = [
        'self.dashboard', 'self.messages', 'self.leave', 'self.overtime', 'self.payroll',
        'self.profile.banking', 'self.profile.medical', 'self.profile.emergency',
    ];

    public const ROLE_PERMISSIONS = [
        'System Administrator' => ['*'],
        'Contract Manager'             => ['staff.view', 'teams.view', 'rosters.manage', 'reports.manage', 'reports.inspection.view', 'reports.erco.view', 'reports.drill.view', 'reports.fitness.view', 'dashboard.roster.view', 'dashboard.reports.view', ...self::SELF_SERVICE],
        'Human Resource'               => ['staff.view', 'staff.manage', 'staff.leave.manage', 'staff.salary.manage', 'staff.overtime.manage', 'teams.view', 'dashboard.payroll.view', 'dashboard.overtime.view', 'dashboard.leave.view', ...self::SELF_SERVICE],
        'Finance'                      => ['staff.view', 'staff.salary.manage', 'staff.salary.pay', 'teams.view', 'dashboard.payroll.view', ...self::SELF_SERVICE],
        'Admin'                        => ['staff.view', 'teams.view', 'teams.manage', 'rosters.manage', 'dashboard.roster.view', ...self::SELF_SERVICE],
        'Incident Commander'           => ['teams.view', 'rosters.manage', 'reports.manage', 'reports.inspection.view', 'reports.erco.view', 'reports.drill.view', 'reports.fitness.view', 'dashboard.roster.view', 'dashboard.reports.view', ...self::SELF_SERVICE],
        'Assistant Incident Commander' => ['teams.view', 'reports.manage', 'reports.inspection.view', 'reports.erco.view', 'reports.drill.view', 'reports.fitness.view', 'dashboard.reports.view', ...self::SELF_SERVICE],
        'Tactical Response Team'       => ['teams.view', 'reports.manage', 'reports.inspection.view', 'reports.erco.view', 'reports.drill.view', 'reports.fitness.view', 'dashboard.roster.view', ...self::SELF_SERVICE],
        // Client-facing roles — operational view only, no internal self-service
        'Client Contract Manager'      => ['teams.view', 'self.dashboard', 'self.messages'],
        'Representative'               => ['teams.view', 'self.dashboard', 'self.messages'],
    ];

    public static function allPermissions(): array
    {
        return [
            // System admin
            'users.manage',
            'roles.assign',
            'audit.view',
            'settings.manage',
            // Organisation
            'staff.view',
            'staff.manage',
            'staff.leave.manage',
            'staff.salary.manage',
            'staff.salary.pay',
            'staff.overtime.manage',
            'reports.manage',
            'reports.inspection.view',
            'reports.erco.view',
            'reports.drill.view',
            'reports.fitness.view',
            'dashboard.payroll.view',
            'dashboard.overtime.view',
            'dashboard.leave.view',
            'dashboard.roster.view',
            'dashboard.reports.view',
            'teams.view',
            'teams.manage',
            'rosters.manage',
            // Self service
            'self.dashboard',
            'self.messages',
            'self.leave',
            'self.overtime',
            'self.payroll',
            // Profile
            'self.profile.banking',
            'self.profile.medical',
            'self.profile.emergency',
        ];
    }

    public static function scopeForRole(?string $roleName): string
    {
        return self::ROLE_SCOPE[$roleName ?? ''] ?? self::OFFICE;
    }

    public static function isScopedRole(?string $roleName): bool
    {
        $scope = self::scopeForRole($roleName);
        return in_array($scope, [self::SITE, self::CLIENT_SITE], true);
    }
}
