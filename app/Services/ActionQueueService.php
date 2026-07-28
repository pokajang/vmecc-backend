<?php

namespace App\Services;

use App\Models\AiHelperKnowledgeEntry;
use App\Models\AiHelperResponseReport;
use App\Models\FeedbackReport;
use App\Models\InspectionFireExtinguisherIssue;
use App\Models\Leave;
use App\Models\OvertimeRecord;
use App\Models\PayrollClaim;
use App\Models\Report;
use App\Models\Roster;
use App\Models\User;
use Illuminate\Support\Collection;

class ActionQueueService
{
    private const REPORT_FAMILIES = [
        'inspection' => [
            'label' => 'Inspections',
            'permission' => 'reports.inspection.view|reports.manage',
            'module' => 'reports.inspection',
            'path' => '/inspection',
        ],
        'erco' => [
            'label' => 'ERCO reports',
            'permission' => 'reports.erco.view|reports.manage',
            'module' => 'reports.erco',
            'path' => '/report/erco',
        ],
        'drill' => [
            'label' => 'Drill reports',
            'permission' => 'reports.drill.view|reports.manage',
            'module' => 'reports.drill',
            'path' => '/report/drill',
        ],
        'fitness-test' => [
            'label' => 'Fitness tests',
            'permission' => 'reports.fitness.view|reports.manage',
            'module' => 'reports.fitness_test',
            'path' => '/report/fitness-test',
        ],
    ];

    private const WORKFLOW_ACTIONS = ['review', 'recommend', 'approve'];

    public function __construct(
        private readonly AssignmentAuthorizationService $authorization,
        private readonly ModuleActivationService $modules,
        private readonly ReportingWorkflowService $reportingWorkflow,
        private readonly OvertimeManagementScopeService $overtimeScope,
        private readonly ReportActionContextService $reportActionContext,
        private readonly WorkflowRecipientResolver $workflowRecipients,
    ) {}

    public function forUser(User $actor): array
    {
        $items = collect()
            ->concat($this->reportItems($actor))
            ->concat($this->fireExtinguisherIssueItems($actor))
            ->concat($this->leaveItems($actor))
            ->concat($this->overtimeItems($actor))
            ->concat($this->payrollItems($actor))
            ->concat($this->rosterItems($actor))
            ->concat($this->adminItems($actor))
            ->filter(fn (array $item) => (int) ($item['count'] ?? 0) > 0)
            ->sortBy([
                [fn (array $item) => $this->priorityRank((string) ($item['priority'] ?? 'normal')), 'asc'],
                ['module', 'asc'],
                ['action', 'asc'],
            ])
            ->values();

        return [
            'asOf' => now()->toIso8601String(),
            'items' => $items->all(),
        ];
    }

    private function reportItems(User $actor): array
    {
        $items = [];

        foreach (self::REPORT_FAMILIES as $reportType => $config) {
            if (! $this->enabled($config['module']) || ! $this->allowed($actor, $config['permission'])) {
                continue;
            }

            $candidates = Report::query()
                ->where('report_type', $reportType)
                ->whereIn('status', ['Submitted', 'Reviewed', 'Rejected'])
                ->get();

            foreach (['review', 'approve'] as $action) {
                $actionable = $candidates->filter(fn (Report $report) => $action === 'review'
                    ? $this->reportingWorkflow->canReview($report, $actor)
                    : $this->reportingWorkflow->canApprove($report, $actor))->values();

                $contexts = $this->reportActionContext->grouped(
                    $actionable,
                    $action,
                    $config['path'],
                );
                foreach ($contexts as $index => $context) {
                    $items[] = $this->item(
                        count($contexts) === 1
                            ? "reports.{$reportType}.{$action}"
                            : "reports.{$reportType}.{$action}.{$index}."
                                .($context['teamId'] ?? 'organization'),
                        $reportType === 'inspection' ? 'inspection' : 'reports',
                        $action,
                        "{$config['label']} awaiting your {$action}",
                        (int) $context['count'],
                        (string) $context['to'],
                        context: $context,
                    );
                }
            }

            $items[] = $this->item(
                "reports.{$reportType}.correction",
                $reportType === 'inspection' ? 'inspection' : 'reports',
                'correct',
                "{$config['label']} returned for your correction",
                $candidates->where('status', 'Rejected')->where('owner_user_id', $actor->id)->count(),
                "{$config['path']}?status=Rejected",
                'high',
            );
        }

        return $items;
    }

    private function fireExtinguisherIssueItems(User $actor): array
    {
        if (! $this->enabled('reports.inspection')) {
            return [];
        }

        $items = [];
        $path = '/inspection/all-extinguishers?issues=with-issues';

        if ($this->allowed($actor, 'reports.inspection.issues.manage|reports.manage')) {
            $assigned = InspectionFireExtinguisherIssue::query()
                ->whereIn('status', InspectionFireExtinguisherIssue::ACTIVE_STATUSES)
                ->where('assigned_to_user_id', $actor->id)
                ->count();
            $overdue = InspectionFireExtinguisherIssue::query()
                ->whereIn('status', InspectionFireExtinguisherIssue::ACTIVE_STATUSES)
                ->where('due_at', '<', now())
                ->count();

            $items[] = $this->item(
                'inspection.extinguisher-issues.assigned',
                'inspection',
                'resolve',
                'Fire extinguisher issues assigned to you',
                $assigned,
                $path.'&assignee=me',
                'high',
            );
            $items[] = $this->item(
                'inspection.extinguisher-issues.overdue',
                'inspection',
                'resolve',
                'Overdue fire extinguisher issues',
                $overdue,
                $path.'&overdue=1',
                'high',
            );
        }

        if ($this->allowed($actor, 'reports.inspection.issues.verify|reports.manage')) {
            $items[] = $this->item(
                'inspection.extinguisher-issues.verify',
                'inspection',
                'verify',
                'Fire extinguisher issues pending verification',
                InspectionFireExtinguisherIssue::query()->where('status', 'pending_verification')->count(),
                $path.'&status=pending_verification',
                'high',
            );
        }

        return $items;
    }

    private function leaveItems(User $actor): array
    {
        $items = [];
        if ($this->enabled('leave.management') && $this->allowed($actor, 'staff.leave.manage')) {
            $roles = $this->roleNames($actor);
            $isAdmin = $roles->contains('System Administrator');
            $records = Leave::query()
                ->where('status', 'Pending')
                ->whereIn('workflow_stage', self::WORKFLOW_ACTIONS)
                ->get();

            foreach (self::WORKFLOW_ACTIONS as $action) {
                $actionable = $records->filter(fn (Leave $leave) => $leave->workflow_stage === $action
                    && $this->roleCanAct($actor, $roles, $isAdmin, $leave->next_action_role)
                    && ($isAdmin || $this->workflowRecipients
                        ->resolveForWorkflowRole(
                            (string) $leave->next_action_role,
                            $leave->workflow_team_id ? (int) $leave->workflow_team_id : null,
                            now(),
                            (int) $leave->user_id,
                        )
                        ->contains(fn (array $recipient) => (int) $recipient['userId'] === (int) $actor->id))
                    && ! $this->violatesDistinctApprovers(
                        $leave->workflow_snapshot,
                        $leave->approval_history,
                        $actor,
                    )
                )->values();

                $contexts = $this->workflowContextGroups($actionable);
                foreach ($contexts as $index => $context) {
                    $key = "leave.{$action}".(count($contexts) > 1 ? ".{$index}" : '');
                    $items[] = $this->item(
                        $key,
                        'leave',
                        $action,
                        "Leave requests pending your {$action}",
                        (int) $context['count'],
                        "/staff/leave-management/leaves?action={$action}".($context['teamId'] ? "&team_id={$context['teamId']}" : ''),
                        context: $context,
                    );
                }
            }
        }

        if ($this->enabled('leave.self_service') && $this->allowed($actor, 'self.leave')) {
            $count = Leave::query()
                ->where('user_id', $actor->id)
                ->where('status', 'Needs Correction')
                ->where('workflow_stage', 'correction')
                ->count();
            $items[] = $this->item(
                'leave.correction',
                'leave',
                'correct',
                'Leave requests returned for your correction',
                $count,
                '/leave?status=Needs%20Correction',
                'high',
            );
        }

        return $items;
    }

    private function overtimeItems(User $actor): array
    {
        $items = [];
        if ($this->enabled('overtime.management') && $this->allowed($actor, 'staff.overtime.manage')) {
            $query = OvertimeRecord::query()
                ->with('user.roleAssignments.role.permissions')
                ->where('status', 'Pending')
                ->whereIn('workflow_stage', self::WORKFLOW_ACTIONS);
            $records = $this->overtimeScope->scopeVisibleRecords($query, $actor)->get();
            $isAdmin = $this->overtimeScope->isSystemAdministrator($actor);

            foreach (self::WORKFLOW_ACTIONS as $action) {
                $actionable = $records->filter(fn (OvertimeRecord $record) => $record->workflow_stage === $action
                    && ($isAdmin || $this->overtimeScope->canPerformWorkflowRole(
                        $actor,
                        $record,
                        (string) $record->next_action_role,
                    ))
                    && ! $this->violatesDistinctApprovers(
                        $record->workflow_snapshot,
                        $record->approval_history,
                        $actor,
                    )
                )->values();

                $contexts = $this->workflowContextGroups($actionable);
                foreach ($contexts as $index => $context) {
                    $key = "overtime.{$action}".(count($contexts) > 1 ? ".{$index}" : '');
                    $items[] = $this->item(
                        $key,
                        'overtime',
                        $action,
                        "Overtime requests pending your {$action}",
                        (int) $context['count'],
                        "/staff/overtime-management/records?action={$action}".($context['teamId'] ? "&team_id={$context['teamId']}" : ''),
                        context: $context,
                    );
                }
            }
        }

        if ($this->enabled('overtime.self_service') && $this->allowed($actor, 'self.overtime')) {
            $count = OvertimeRecord::query()
                ->where('user_id', $actor->id)
                ->where('status', 'Needs Correction')
                ->where('workflow_stage', 'correction')
                ->count();
            $items[] = $this->item(
                'overtime.correction',
                'overtime',
                'correct',
                'Overtime requests returned for your correction',
                $count,
                '/overtime?status=Needs%20Correction',
                'high',
            );
        }

        return $items;
    }

    private function payrollItems(User $actor): array
    {
        $items = [];
        if ($this->enabled('payroll.salary_claims_management') && $this->allowed($actor, 'staff.salary.manage')) {
            $roles = $this->roleNames($actor);
            $isAdmin = $roles->contains('System Administrator');
            $records = PayrollClaim::query()
                ->where('status', 'Pending')
                ->whereIn('workflow_stage', ['check', 'review', 'approve'])
                ->get();

            foreach (['salary' => 'salary', 'other' => 'claims'] as $group => $tab) {
                $groupRecords = $records->filter(fn (PayrollClaim $claim) => $group === 'salary'
                    ? $claim->claim_type === 'salary'
                    : $claim->claim_type !== 'salary');

                foreach (['check', 'review', 'approve'] as $action) {
                    $count = $groupRecords->filter(fn (PayrollClaim $claim) => $claim->workflow_stage === $action
                        && $this->roleCanAct($actor, $roles, $isAdmin, $claim->next_action_role)
                    )->count();
                    $groupLabel = $group === 'salary' ? 'Salary claims' : 'Expense and other claims';
                    $items[] = $this->item(
                        "payroll.{$group}.{$action}",
                        'payroll',
                        $action,
                        "{$groupLabel} pending your {$action}",
                        $count,
                        "/staff/salary-claims/{$tab}?action={$action}",
                    );
                }
            }
        }

        if (
            $this->enabled('payroll.payment_actions')
            && $this->allowed($actor, 'staff.salary.manage')
            && $this->allowed($actor, 'staff.salary.pay')
        ) {
            $count = PayrollClaim::query()
                ->where('claim_type', 'salary')
                ->where('status', 'Approved')
                ->whereNull('paid_at')
                ->count();
            $items[] = $this->item(
                'payroll.salary.mark-paid',
                'payroll',
                'mark_paid',
                'Approved salary claims awaiting payment',
                $count,
                '/staff/salary-claims/salary?action=mark_paid',
                'high',
            );
        }

        return $items;
    }

    private function rosterItems(User $actor): array
    {
        if (! $this->enabled('roster') || ! $this->allowed($actor, 'rosters.manage')) {
            return [];
        }

        $count = Roster::query()
            ->where('status', 'draft')
            ->whereNotNull('date')
            ->distinct()
            ->count('date');

        return [$this->item(
            'roster.publish',
            'roster',
            'publish',
            'Draft roster days awaiting publication',
            $count,
            '/roster/schedule?range=all&attention=draft',
        )];
    }

    private function adminItems(User $actor): array
    {
        if (! $this->allowed($actor, '*')) {
            return [];
        }

        return [
            $this->item(
                'admin.feedback.review',
                'admin',
                'review',
                'Feedback reports awaiting moderation',
                FeedbackReport::query()->whereIn('status', [FeedbackReport::STATUS_NEW, FeedbackReport::STATUS_REVIEWING])->count(),
                '/admin/feedback-reports?status=actionable',
            ),
            $this->item(
                'admin.ai-reports.review',
                'admin',
                'review',
                'AI response reports awaiting moderation',
                AiHelperResponseReport::query()->whereIn('status', [AiHelperResponseReport::STATUS_NEW, AiHelperResponseReport::STATUS_REVIEWING])->count(),
                '/admin/ai-helper-reports?status=actionable',
            ),
            $this->item(
                'admin.ai-knowledge.review',
                'admin',
                'review',
                'AI knowledge entries awaiting review',
                AiHelperKnowledgeEntry::query()->where('review_status', AiHelperKnowledgeEntry::REVIEW_PENDING)->count(),
                '/admin/ai-helper-knowledge?status=pending',
            ),
        ];
    }

    private function roleCanAct(
        User $actor,
        Collection $roles,
        bool $isAdmin,
        mixed $requiredRole,
    ): bool {
        $requiredRole = trim((string) $requiredRole);
        if ($isAdmin) {
            return true;
        }

        return $requiredRole !== '' && $roles->contains($requiredRole);
    }

    private function workflowContextGroups(Collection $records): array
    {
        return $records
            ->groupBy(function ($record): string {
                $role = trim((string) $record->next_action_role);
                $scope = RoleCatalog::scopeForRole($role);
                $teamId = in_array($scope, [RoleCatalog::SITE, RoleCatalog::CLIENT_SITE], true)
                    ? (int) ($record->workflow_team_id ?? 0)
                    : 0;

                return implode('|', [$teamId, $role, (string) ($record->workflow_routing_source ?? 'organization')]);
            })
            ->map(function (Collection $rows): array {
                $record = $rows->first();
                $role = trim((string) $record->next_action_role);
                $scope = RoleCatalog::scopeForRole($role);
                $teamScoped = in_array($scope, [RoleCatalog::SITE, RoleCatalog::CLIENT_SITE], true);

                return [
                    'count' => $rows->count(),
                    'teamId' => $teamScoped && $record->workflow_team_id ? (int) $record->workflow_team_id : null,
                    'teamName' => $teamScoped ? trim((string) $record->workflow_team_name) : '',
                    'role' => $role,
                    'routingSource' => trim((string) ($record->workflow_routing_source ?? 'organization')),
                    'scopeLabel' => $teamScoped
                        ? (trim((string) $record->workflow_team_name) ?: 'Team scoped')
                        : 'Organization-wide',
                ];
            })
            ->values()
            ->all();
    }

    private function violatesDistinctApprovers(mixed $snapshot, mixed $history, User $actor): bool
    {
        if (! is_array($snapshot) || ($snapshot['enforceDistinctApprovers'] ?? false) !== true) {
            return false;
        }

        return collect(is_array($history) ? $history : [])->contains(
            fn ($entry) => (string) ($entry['byUserId'] ?? '') === (string) $actor->id
                && in_array((string) ($entry['action'] ?? ''), ['Reviewed', 'Recommended', 'Approved'], true),
        );
    }

    private function item(
        string $key,
        string $module,
        string $action,
        string $label,
        int $count,
        string $to,
        string $priority = 'normal',
        array $context = [],
    ): array {
        return compact('key', 'module', 'action', 'label', 'count', 'to', 'priority')
            + $context;
    }

    private function roleNames(User $actor): Collection
    {
        return $this->authorization->getActiveRoleNames($actor);
    }

    private function allowed(User $actor, string $permission): bool
    {
        return $this->authorization->hasPermission($actor, $permission);
    }

    private function enabled(string $module): bool
    {
        return $this->modules->isEnabled($module);
    }

    private function priorityRank(string $priority): int
    {
        return match ($priority) {
            'high' => 0,
            'low' => 2,
            default => 1,
        };
    }
}
