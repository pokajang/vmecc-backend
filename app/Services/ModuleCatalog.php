<?php

namespace App\Services;

class ModuleCatalog
{
    public const MODULES = [
        'settings.module_activation' => [
            'label' => 'Module Activation',
            'description' => 'Controls which application modules are available.',
            'group' => 'Core',
            'locked' => true,
        ],
        'settings.system_maintenance' => [
            'label' => 'System Maintenance',
            'description' => 'Maintenance-mode controls and enforcement.',
            'group' => 'Core',
            'locked' => true,
        ],
        'settings.role_permissions' => [
            'label' => 'Role Permissions',
            'description' => 'Role and permission matrix.',
            'group' => 'Core',
            'locked' => true,
        ],
        'settings.dashboard_visibility' => [
            'label' => 'Dashboard Visibility',
            'description' => 'Dashboard section permission editor.',
            'group' => 'Core',
            'parent' => 'dashboard',
        ],
        'users' => [
            'label' => 'Users',
            'description' => 'User management and account administration.',
            'group' => 'Core',
            'locked' => true,
        ],
        'audit' => [
            'label' => 'Audit Logs',
            'description' => 'System audit-log review.',
            'group' => 'Core',
            'locked' => true,
        ],
        'profile' => [
            'label' => 'Profile',
            'description' => 'Authenticated user profile and security settings.',
            'group' => 'Core',
            'locked' => true,
        ],
        'staff' => [
            'label' => 'Staff',
            'description' => 'Staff directory and staff profile views.',
            'group' => 'Workforce',
            'locked' => true,
        ],
        'staff.directory' => [
            'label' => 'Staff Directory',
            'description' => 'Browse staff profiles and staff details.',
            'group' => 'Workforce',
            'parent' => 'staff',
        ],
        'dashboard' => [
            'label' => 'Dashboard',
            'description' => 'Dashboard shell and module summary sections.',
            'group' => 'Dashboard',
        ],
        'dashboard.payroll' => [
            'label' => 'Payroll Dashboard',
            'description' => 'Payroll summary cards and charts.',
            'group' => 'Dashboard',
            'parent' => 'dashboard',
            'dependencies' => ['payroll'],
        ],
        'dashboard.overtime' => [
            'label' => 'Overtime Dashboard',
            'description' => 'Overtime request summary cards and charts.',
            'group' => 'Dashboard',
            'parent' => 'dashboard',
            'dependencies' => ['overtime'],
        ],
        'dashboard.leave' => [
            'label' => 'Leave Dashboard',
            'description' => 'Leave request summary cards and charts.',
            'group' => 'Dashboard',
            'parent' => 'dashboard',
            'dependencies' => ['leave'],
        ],
        'dashboard.roster' => [
            'label' => 'Roster Dashboard',
            'description' => 'Roster, shift, and team summary cards.',
            'group' => 'Dashboard',
            'parent' => 'dashboard',
            'dependencies' => ['roster'],
        ],
        'dashboard.reports' => [
            'label' => 'Reports Dashboard',
            'description' => 'Reports and inspection summary cards.',
            'group' => 'Dashboard',
            'parent' => 'dashboard',
            'dependencies' => ['reports'],
        ],
        'messages' => [
            'label' => 'Messages',
            'description' => 'Direct messages, threads, and message attachments.',
            'group' => 'Communication',
        ],
        'teams' => [
            'label' => 'Teams',
            'description' => 'Team directory, team membership, and scoped team context.',
            'group' => 'Teams and Roster',
        ],
        'teams.directory' => [
            'label' => 'Team Directory',
            'description' => 'Team list, team detail, and team member assignment.',
            'group' => 'Teams and Roster',
            'parent' => 'teams',
        ],
        'roster' => [
            'label' => 'Roster',
            'description' => 'Roster overview, schedules, publishing, and shift usage.',
            'group' => 'Teams and Roster',
            'dependencies' => ['teams'],
        ],
        'roster.shift_settings' => [
            'label' => 'Shift Settings',
            'description' => 'Shift windows and custom shifts used by roster and workflows.',
            'group' => 'Teams and Roster',
            'parent' => 'roster',
        ],
        'leave' => [
            'label' => 'Leave',
            'description' => 'Leave applications, balances, and staff leave administration.',
            'group' => 'Leave',
        ],
        'leave.self_service' => [
            'label' => 'Leave Self-Service',
            'description' => 'Employee leave applications and leave records.',
            'group' => 'Leave',
            'parent' => 'leave',
        ],
        'leave.management' => [
            'label' => 'Leave Management',
            'description' => 'Staff leave review, approval, cancellation, and allocations.',
            'group' => 'Leave',
            'parent' => 'leave',
            'dependencies' => ['staff'],
        ],
        'leave.assignments' => [
            'label' => 'Leave Assignments',
            'description' => 'Staff leave entitlement assignments.',
            'group' => 'Leave',
            'parent' => 'leave',
            'dependencies' => ['staff'],
        ],
        'leave.holidays' => [
            'label' => 'Holiday Calendar',
            'description' => 'Holiday data used by leave and overtime day classification.',
            'group' => 'Leave',
            'parent' => 'leave',
        ],
        'leave.workflow_rules' => [
            'label' => 'Leave Workflow Rules',
            'description' => 'Leave approval rule settings.',
            'group' => 'Leave',
            'parent' => 'leave',
        ],
        'overtime' => [
            'label' => 'Overtime',
            'description' => 'Overtime applications, rates, and approval workflows.',
            'group' => 'Overtime',
        ],
        'overtime.self_service' => [
            'label' => 'Overtime Self-Service',
            'description' => 'Employee overtime applications and records.',
            'group' => 'Overtime',
            'parent' => 'overtime',
        ],
        'overtime.management' => [
            'label' => 'Overtime Management',
            'description' => 'Staff overtime review and approval workflows.',
            'group' => 'Overtime',
            'parent' => 'overtime',
            'dependencies' => ['staff'],
        ],
        'overtime.workflow_rules' => [
            'label' => 'Overtime Workflow Rules',
            'description' => 'Overtime approval rule settings.',
            'group' => 'Overtime',
            'parent' => 'overtime',
        ],
        'overtime.rate_settings' => [
            'label' => 'Overtime Rate Settings',
            'description' => 'Overtime rate and base-hour calculation settings.',
            'group' => 'Overtime',
            'parent' => 'overtime',
        ],
        'payroll' => [
            'label' => 'Payroll',
            'description' => 'Payroll claims, salary claims, payslips, and salary settings.',
            'group' => 'Payroll',
        ],
        'payroll.self_service' => [
            'label' => 'Payroll Self-Service',
            'description' => 'Employee payroll claim submission and claim history.',
            'group' => 'Payroll',
            'parent' => 'payroll',
        ],
        'payroll.claims' => [
            'label' => 'Payroll Claims',
            'description' => 'Employee expense, salary, and exceptional claim records.',
            'group' => 'Payroll',
            'parent' => 'payroll.self_service',
        ],
        'payroll.payslips' => [
            'label' => 'Payslips',
            'description' => 'Employee payslip listing and downloads.',
            'group' => 'Payroll',
            'parent' => 'payroll.self_service',
        ],
        'payroll.salary_claims_management' => [
            'label' => 'Salary & Claims Management',
            'description' => 'Staff claim review, salary records, and payment actions.',
            'group' => 'Payroll',
            'parent' => 'payroll',
            'dependencies' => ['staff'],
        ],
        'payroll.salary_settings' => [
            'label' => 'Salary Settings',
            'description' => 'Salary assignments, OT rates, salary workflow, and legal information.',
            'group' => 'Payroll',
            'parent' => 'payroll',
            'dependencies' => ['staff'],
        ],
        'payroll.salary_assignments' => [
            'label' => 'Salary Assignments',
            'description' => 'Create and maintain staff salary assignments.',
            'group' => 'Payroll',
            'parent' => 'payroll.salary_settings',
        ],
        'payroll.workflow_rules' => [
            'label' => 'Salary Workflow Rules',
            'description' => 'Salary and claim workflow rule settings.',
            'group' => 'Payroll',
            'parent' => 'payroll.salary_settings',
        ],
        'payroll.company_profile' => [
            'label' => 'Payroll Company Profile',
            'description' => 'Company legal details used on payroll outputs.',
            'group' => 'Payroll',
            'parent' => 'payroll.salary_settings',
        ],
        'payroll.statutory_rates' => [
            'label' => 'Salary Statutory Rates',
            'description' => 'EPF, PERKESO, and SIP rate settings.',
            'group' => 'Payroll',
            'parent' => 'payroll.salary_settings',
        ],
        'payroll.payment_actions' => [
            'label' => 'Salary Payment Actions',
            'description' => 'Mark and unmark salary claims as paid.',
            'group' => 'Payroll',
            'parent' => 'payroll.salary_claims_management',
        ],
        'reports' => [
            'label' => 'Reports',
            'description' => 'Inspection and operational report workflows.',
            'group' => 'Reports and Inspection',
        ],
        'reports.inspection' => [
            'label' => 'Inspection',
            'description' => 'Inspection forms, records, review, and PDF exports.',
            'group' => 'Reports and Inspection',
            'parent' => 'reports',
        ],
        'reports.erco' => [
            'label' => 'ERCO',
            'description' => 'ERCO report forms, records, and PDF exports.',
            'group' => 'Reports and Inspection',
            'parent' => 'reports',
        ],
        'reports.drill' => [
            'label' => 'Drill',
            'description' => 'Drill report forms and records.',
            'group' => 'Reports and Inspection',
            'parent' => 'reports',
        ],
        'reports.fitness_test' => [
            'label' => 'Fitness Test',
            'description' => 'Fitness test report forms and records.',
            'group' => 'Reports and Inspection',
            'parent' => 'reports',
        ],
        'reports.pdf_exports' => [
            'label' => 'Report PDF Exports',
            'description' => 'PDF generation for report modules.',
            'group' => 'Reports and Inspection',
            'parent' => 'reports',
        ],
        'workflow_notifications' => [
            'label' => 'Workflow Notifications',
            'description' => 'In-app workflow notification delivery and reads.',
            'group' => 'Communication',
            'locked' => true,
        ],
        'workflow_attachments' => [
            'label' => 'Workflow Attachments',
            'description' => 'Shared workflow attachment upload and retrieval.',
            'group' => 'Core',
            'locked' => true,
        ],
    ];

    public static function keys(): array
    {
        return array_keys(self::MODULES);
    }

    public static function has(string $key): bool
    {
        return array_key_exists($key, self::MODULES);
    }

    public static function lockedKeys(): array
    {
        return array_values(array_filter(
            self::keys(),
            static fn (string $key) => (bool) (self::MODULES[$key]['locked'] ?? false)
        ));
    }

    public static function registryPayload(): array
    {
        return collect(self::MODULES)
            ->map(function (array $module, string $key) {
                return [
                    'key' => $key,
                    'label' => $module['label'],
                    'description' => $module['description'] ?? '',
                    'group' => $module['group'] ?? 'Other',
                    'parent' => $module['parent'] ?? null,
                    'dependencies' => array_values($module['dependencies'] ?? []),
                    'locked' => (bool) ($module['locked'] ?? false),
                ];
            })
            ->values()
            ->all();
    }

    public static function validateRegistry(): array
    {
        $errors = [];
        foreach (self::MODULES as $key => $module) {
            $parent = $module['parent'] ?? null;
            if ($parent !== null && ! self::has($parent)) {
                $errors[] = "{$key} has unknown parent {$parent}";
            }

            foreach (($module['dependencies'] ?? []) as $dependency) {
                if (! self::has($dependency)) {
                    $errors[] = "{$key} has unknown dependency {$dependency}";
                }
            }
        }

        foreach (self::keys() as $key) {
            if (self::hasCycle($key)) {
                $errors[] = "{$key} is part of a module dependency cycle";
            }
        }

        return array_values(array_unique($errors));
    }

    private static function hasCycle(string $key, array $seen = []): bool
    {
        if (isset($seen[$key])) {
            return true;
        }

        $seen[$key] = true;
        $module = self::MODULES[$key] ?? [];
        $next = array_filter([
            $module['parent'] ?? null,
            ...($module['dependencies'] ?? []),
        ]);

        foreach ($next as $candidate) {
            if (self::hasCycle($candidate, $seen)) {
                return true;
            }
        }

        return false;
    }
}
