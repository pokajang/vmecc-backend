<?php

namespace Tests\Audit;

use App\Services\LeavePolicyService;
use App\Services\LeaveWorkflowService;
use App\Services\RoleCatalog;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Executable probes for workflow configuration contracts that are not yet safe.
 * These tests are intentionally outside the default Unit/Feature suites.
 */
class WorkflowConfigurationAuditTest extends TestCase
{
    #[DataProvider('defaultSalaryWorkflowRoles')]
    public function test_default_salary_stage_role_can_reach_salary_management_routes(string $roleName): void
    {
        $permissions = RoleCatalog::ROLE_PERMISSIONS[$roleName] ?? [];

        $this->assertTrue(
            in_array('*', $permissions, true) || in_array('staff.salary.manage', $permissions, true),
            "Default salary workflow role {$roleName} cannot reach its workflow endpoint.",
        );
    }

    public function test_leave_workflow_has_a_safe_non_empty_fallback_without_database_settings(): void
    {
        $service = new LeaveWorkflowService($this->createMock(LeavePolicyService::class));
        $fallback = $service->normalizeApprovalRules([])['fallback'];

        $this->assertNotSame('', $fallback['reviewRole']);
        $this->assertNotSame('', $fallback['approveRole']);
    }

    public static function defaultSalaryWorkflowRoles(): array
    {
        return [
            'check role' => ['Admin'],
            'review role' => ['Finance'],
            'approve role' => ['Contract Manager'],
        ];
    }
}
