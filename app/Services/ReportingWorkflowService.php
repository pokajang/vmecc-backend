<?php

namespace App\Services;

use App\Models\DutyCoverageAssignment;
use App\Models\Report;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ReportingWorkflowService
{
    private const SETTINGS_KEY = 'reporting_workflow_rules';

    private const LEGACY_INSPECTION_SETTING_KEY = 'inspection_workflow_rules';

    private const REPORTING_MODULE_KEYS = [
        'inspection',
        'erco',
        'drill',
        'fitness-test',
    ];

    private const MODULE_DEFAULTS = [
        'inspection' => [
            'fallbackReviewRole' => 'Incident Commander',
            'reviewRole' => 'Assistant Incident Commander',
            'approveRole' => 'Incident Commander',
            'options' => [
                'useTeamScopedAic' => true,
                'allowSubmitWithoutTeam' => true,
                'allowIcFallbackReview' => true,
                'preventSelfReview' => true,
                'preventSelfApprove' => true,
            ],
        ],
        'erco' => [
            'fallbackReviewRole' => 'Incident Commander',
            'reviewRole' => 'Incident Commander',
            'approveRole' => 'Incident Commander',
            'options' => [
                'useTeamScopedAic' => true,
                'allowSubmitWithoutTeam' => true,
                'allowIcFallbackReview' => true,
                'preventSelfReview' => true,
                'preventSelfApprove' => true,
            ],
        ],
        'drill' => [
            'fallbackReviewRole' => 'Incident Commander',
            'reviewRole' => 'Incident Commander',
            'approveRole' => 'Incident Commander',
            'options' => [
                'useTeamScopedAic' => true,
                'allowSubmitWithoutTeam' => true,
                'allowIcFallbackReview' => true,
                'preventSelfReview' => true,
                'preventSelfApprove' => true,
            ],
        ],
        'fitness-test' => [
            'fallbackReviewRole' => 'Incident Commander',
            'reviewRole' => 'Incident Commander',
            'approveRole' => 'Incident Commander',
            'options' => [
                'useTeamScopedAic' => true,
                'allowSubmitWithoutTeam' => true,
                'allowIcFallbackReview' => true,
                'preventSelfReview' => true,
                'preventSelfApprove' => true,
            ],
        ],
    ];

    public function __construct(
        private readonly AssignmentAuthorizationService $authorizationService,
        private readonly WorkflowRecipientResolver $recipientResolver,
    ) {}

    public function isManagedModule(?string $moduleKey): bool
    {
        return in_array($this->normalizeModuleKey($moduleKey), self::REPORTING_MODULE_KEYS, true);
    }

    public function loadWorkflowRules(): array
    {
        $setting = Setting::query()->where('key', self::SETTINGS_KEY)->first();
        if (is_array($setting?->value ?? null)) {
            return $this->normalizeWorkflowRules($setting->value);
        }

        $legacySetting = Setting::query()->where('key', self::LEGACY_INSPECTION_SETTING_KEY)->first();
        if (is_array($legacySetting?->value ?? null)) {
            return $this->normalizeWorkflowRules([
                'modules' => [
                    'inspection' => $legacySetting->value,
                ],
            ]);
        }

        return $this->normalizeWorkflowRules([]);
    }

    public function loadModuleWorkflowRules(string $moduleKey): array
    {
        $moduleKey = $this->normalizeModuleKey($moduleKey);

        return $this->loadWorkflowRules()['modules'][$moduleKey]
            ?? $this->normalizeModuleRules($moduleKey, []);
    }

    public function saveWorkflowRules(array $payload): array
    {
        $normalized = $this->normalizeWorkflowRules($payload);
        Setting::query()->updateOrCreate(
            ['key' => self::SETTINGS_KEY],
            ['value' => $normalized],
        );

        return $normalized;
    }

    public function saveModuleWorkflowRules(string $moduleKey, array $rules): array
    {
        $moduleKey = $this->normalizeModuleKey($moduleKey);
        $current = $this->loadWorkflowRules();
        $current['modules'][$moduleKey] = $rules;

        return $this->saveWorkflowRules($current)['modules'][$moduleKey];
    }

    public function normalizeWorkflowRules(mixed $value): array
    {
        $source = is_array($value) ? $value : [];
        $sourceModules = is_array($source['modules'] ?? null) ? $source['modules'] : [];

        if (empty($sourceModules) && (isset($source['fallback']) || isset($source['options']))) {
            $sourceModules = ['inspection' => $source];
        }

        $normalizedModules = [];
        foreach (self::REPORTING_MODULE_KEYS as $moduleKey) {
            $normalizedModules[$moduleKey] = $this->normalizeModuleRules(
                $moduleKey,
                is_array($sourceModules[$moduleKey] ?? null) ? $sourceModules[$moduleKey] : [],
            );
        }

        return [
            'modules' => $normalizedModules,
        ];
    }

    public function buildWorkflowForSubmission(
        User $submitter,
        string $moduleKey,
        ?int $effectiveTeamId = null,
    ): array {
        $moduleKey = $this->normalizeModuleKey($moduleKey);
        $rules = $this->loadModuleWorkflowRules($moduleKey);
        $reviewRole = $rules['fallback']['reviewRole'];
        $fallbackReviewRole = $rules['fallback']['fallbackReviewRole'];
        $approveRole = $rules['fallback']['approveRole'];
        $options = $rules['options'];
        $scopeTeamId = $effectiveTeamId ?: $this->resolvePrimaryTeamId($submitter);
        $sameTeamReviewer = $scopeTeamId !== null && ($options['useTeamScopedAic'] ?? true)
            ? $this->recipientResolver->resolveFirst(
                $reviewRole,
                $scopeTeamId,
                excludeUserId: (int) $submitter->id,
            )
            : null;
        $hasSameTeamReviewer = $sameTeamReviewer !== null;
        $resolvedReviewRole = $hasSameTeamReviewer ? $reviewRole : $fallbackReviewRole;
        $assignedReviewer = $sameTeamReviewer;
        $fallbackTeamId = RoleCatalog::isScopedRole($resolvedReviewRole) ? $scopeTeamId : null;
        $hasFallbackReviewer = ! $hasSameTeamReviewer
            && $this->recipientResolver
                ->resolveRole($resolvedReviewRole, $fallbackTeamId, excludeUserId: (int) $submitter->id)
                ->isNotEmpty();
        $routingReasonCode = $hasSameTeamReviewer
            ? (($assignedReviewer['source'] ?? '') === 'temporary_coverage'
                ? 'team_temporary_coverage'
                : 'team_role_assignment')
            : ($hasFallbackReviewer ? 'fallback_role_assignment' : 'no_eligible_recipient');

        return [
            'workflow_stage' => 'review',
            'next_action_role' => $resolvedReviewRole ?: null,
            'next_action_user_id' => $assignedReviewer['userId'] ?? null,
            'next_action_duty_coverage_assignment_id' => $assignedReviewer['dutyCoverageAssignmentId'] ?? null,
            'routing_reason_code' => $routingReasonCode,
            'scope_team_id' => $scopeTeamId,
            'workflow_snapshot' => [
                'moduleKey' => $moduleKey,
                'submitterRole' => 'Tactical Response Team',
                'reviewRole' => $reviewRole,
                'fallbackReviewRole' => $fallbackReviewRole,
                'approveRole' => $approveRole,
                'resolvedReviewRole' => $resolvedReviewRole,
                'usedFallbackReview' => ! $hasSameTeamReviewer,
                'scopeTeamId' => $scopeTeamId,
                'assignedReviewerUserId' => $assignedReviewer['userId'] ?? null,
                'assignedReviewerSource' => $assignedReviewer['source'] ?? null,
                'dutyCoverageAssignmentId' => $assignedReviewer['dutyCoverageAssignmentId'] ?? null,
                'routingReasonCode' => $routingReasonCode,
                'options' => $options,
            ],
        ];
    }

    public function submissionBlockReason(
        User $submitter,
        string $moduleKey,
        ?int $effectiveTeamId = null,
    ): ?string {
        $moduleKey = $this->normalizeModuleKey($moduleKey);
        $rules = $this->loadModuleWorkflowRules($moduleKey);
        $reviewRole = $rules['fallback']['reviewRole'];
        $options = $rules['options'];
        $scopeTeamId = $effectiveTeamId ?: $this->resolvePrimaryTeamId($submitter);
        $moduleLabel = $this->moduleLabel($moduleKey);

        if ($scopeTeamId === null && ($options['allowSubmitWithoutTeam'] ?? true) === false) {
            return "{$moduleLabel} submission requires an active team assignment.";
        }

        $hasSameTeamReviewer = $scopeTeamId !== null
            && ($options['useTeamScopedAic'] ?? true)
            && $this->recipientResolver
                ->resolveRole($reviewRole, $scopeTeamId, excludeUserId: (int) $submitter->id)
                ->isNotEmpty();

        if (! $hasSameTeamReviewer && ($options['allowIcFallbackReview'] ?? true) === false) {
            return "{$moduleLabel} submission requires an active same-team reviewer.";
        }

        return null;
    }

    public function draftWorkflowFields(): array
    {
        return [
            'workflow_stage' => null,
            'next_action_role' => null,
            'next_action_user_id' => null,
            'next_action_duty_coverage_assignment_id' => null,
            'routing_reason_code' => null,
            'scope_team_id' => null,
            'workflow_snapshot' => null,
            'approval_history' => null,
        ];
    }

    public function effectiveWorkflow(Report $report): array
    {
        $moduleKey = $this->normalizeModuleKey($report->report_type ?? '');
        if (! $this->isManagedModule($moduleKey)) {
            return [];
        }

        $status = trim((string) ($report->status ?? ''));
        $snapshot = is_array($report->workflow_snapshot) ? $report->workflow_snapshot : [];
        $rules = $this->loadModuleWorkflowRules($moduleKey);
        $fallback = $rules['fallback'];
        $stage = trim((string) ($report->workflow_stage ?? ''));
        $nextRole = trim((string) ($report->next_action_role ?? ''));
        $nextUserId = $report->next_action_user_id;
        $nextDutyCoverageId = $report->next_action_duty_coverage_assignment_id;
        if ($stage === 'review') {
            $nextUserId ??= $snapshot['assignedReviewerUserId'] ?? null;
            $nextDutyCoverageId ??= $snapshot['dutyCoverageAssignmentId'] ?? null;
        }
        $routingReasonCode = trim((string) (
            $report->routing_reason_code
            ?? ($snapshot['routingReasonCode'] ?? '')
        ));
        $scopeTeamId = $report->scope_team_id !== null ? (int) $report->scope_team_id : ($snapshot['scopeTeamId'] ?? null);
        $scopeTeamId = $scopeTeamId !== null && $scopeTeamId !== '' ? (int) $scopeTeamId : null;

        if ($stage === '') {
            $stage = match ($status) {
                'Submitted' => 'review',
                'Reviewed' => 'approve',
                'Approved', 'Rejected', 'Cancelled' => 'done',
                default => null,
            };
        }

        if ($nextRole === '') {
            $nextRole = match ($stage) {
                'review' => trim((string) ($snapshot['resolvedReviewRole'] ?? $snapshot['reviewRole'] ?? $fallback['fallbackReviewRole'])),
                'approve' => trim((string) ($snapshot['approveRole'] ?? $fallback['approveRole'])),
                default => '',
            };
        }

        return [
            'workflow_stage' => $stage,
            'next_action_role' => $nextRole !== '' ? $nextRole : null,
            'next_action_user_id' => $nextUserId ? (int) $nextUserId : null,
            'next_action_duty_coverage_assignment_id' => $nextDutyCoverageId
                ? (int) $nextDutyCoverageId
                : null,
            'routing_reason_code' => $routingReasonCode !== '' ? $routingReasonCode : null,
            'scope_team_id' => $scopeTeamId,
            'workflow_snapshot' => $snapshot ?: [
                'moduleKey' => $moduleKey,
                'reviewRole' => $fallback['reviewRole'],
                'fallbackReviewRole' => $fallback['fallbackReviewRole'],
                'approveRole' => $fallback['approveRole'],
                'resolvedReviewRole' => $nextRole,
                'scopeTeamId' => $scopeTeamId,
                'options' => $rules['options'],
            ],
        ];
    }

    public function canReview(Report $report, User $actor): bool
    {
        if ((int) $report->owner_user_id === (int) $actor->id && $this->preventsSelfReview($report)) {
            return false;
        }

        $workflow = $this->effectiveWorkflow($report);
        if ((string) $report->status !== 'Submitted' || ($workflow['workflow_stage'] ?? null) !== 'review') {
            return false;
        }

        return $this->actorMatchesRoleForWorkflow($actor, (string) ($workflow['next_action_role'] ?? ''), $workflow);
    }

    public function canApprove(Report $report, User $actor): bool
    {
        if ((int) $report->owner_user_id === (int) $actor->id && $this->preventsSelfApprove($report)) {
            return false;
        }

        $workflow = $this->effectiveWorkflow($report);
        if ((string) $report->status !== 'Reviewed' || ($workflow['workflow_stage'] ?? null) !== 'approve') {
            return false;
        }

        return $this->actorMatchesRoleForWorkflow(
            $actor,
            $this->approveRole($report),
            $workflow,
        );
    }

    public function canReject(Report $report, User $actor): bool
    {
        if ((int) $report->owner_user_id === (int) $actor->id && $this->preventsSelfReview($report)) {
            return false;
        }

        $workflow = $this->effectiveWorkflow($report);
        if (! in_array((string) $report->status, ['Submitted', 'Reviewed'], true)) {
            return false;
        }

        return $this->actorMatchesRoleForWorkflow($actor, (string) ($workflow['next_action_role'] ?? ''), $workflow);
    }

    public function authorizeAction(Report $report, User $actor, string $action): ?string
    {
        $moduleLabel = $this->moduleLabel($report->report_type ?? '');

        return match ($action) {
            'review' => $this->canReview($report, $actor) ? null : "You are not assigned to review this {$moduleLabel} report.",
            'approve' => $this->canApprove($report, $actor) ? null : "You are not assigned to approve this {$moduleLabel} report.",
            'reject' => $this->canReject($report, $actor) ? null : "You are not assigned to reject this {$moduleLabel} report.",
            default => 'Unsupported workflow action.',
        };
    }

    public function advanceWorkflow(Report $report, string $action, User $actor, ?string $remarks = null): array
    {
        $workflow = $this->effectiveWorkflow($report);
        $entry = [
            'id' => (string) Str::uuid(),
            'at' => now()->toIso8601String(),
            'action' => match ($action) {
                'review' => 'Reviewed',
                'approve' => 'Approved',
                'reject' => 'Rejected',
                'submit' => 'Submitted',
                'resubmit' => 'Resubmitted',
                default => ucfirst($action),
            },
            'by' => (string) $actor->name,
            'byUserId' => (string) $actor->id,
            'remarks' => (string) ($remarks ?? ''),
            'stage' => $workflow['workflow_stage'] ?? null,
            'role' => $workflow['next_action_role'] ?? null,
        ] + $this->actorRoleSnapshot($actor);
        $history = collect(is_array($report->approval_history) ? $report->approval_history : [])
            ->push($entry)
            ->take(-30)
            ->values()
            ->all();

        if ($action === 'review') {
            $approveRole = $this->approveRole($report);
            $scopeTeamId = RoleCatalog::isScopedRole($approveRole) && $report->scope_team_id
                ? (int) $report->scope_team_id
                : null;
            $hasApprover = $this->recipientResolver
                ->resolveRole(
                    $approveRole,
                    $scopeTeamId,
                    excludeUserId: (int) $report->owner_user_id,
                )
                ->isNotEmpty();

            return [
                'status' => 'Reviewed',
                'workflow_stage' => 'approve',
                'next_action_role' => $approveRole,
                'next_action_user_id' => null,
                'next_action_duty_coverage_assignment_id' => null,
                'routing_reason_code' => $hasApprover
                    ? 'approval_role_assignment'
                    : 'no_eligible_recipient',
                'approval_history' => $history,
            ];
        }

        if ($action === 'approve') {
            return [
                'status' => 'Approved',
                'workflow_stage' => 'done',
                'next_action_role' => null,
                'next_action_user_id' => null,
                'next_action_duty_coverage_assignment_id' => null,
                'routing_reason_code' => null,
                'approval_history' => $history,
            ];
        }

        if ($action === 'reject') {
            return [
                'status' => 'Rejected',
                'workflow_stage' => 'done',
                'next_action_role' => null,
                'next_action_user_id' => null,
                'next_action_duty_coverage_assignment_id' => null,
                'routing_reason_code' => null,
                'approval_history' => $history,
            ];
        }

        return ['approval_history' => $history];
    }

    public function appendSubmissionHistory(array $workflowFields, User $actor, string $action, ?string $remarks = null): array
    {
        $history = is_array($workflowFields['approval_history'] ?? null) ? $workflowFields['approval_history'] : [];
        $history[] = [
            'id' => (string) Str::uuid(),
            'at' => now()->toIso8601String(),
            'action' => $action,
            'by' => (string) $actor->name,
            'byUserId' => (string) $actor->id,
            'remarks' => (string) ($remarks ?? ''),
            'stage' => $workflowFields['workflow_stage'] ?? null,
            'role' => $workflowFields['next_action_role'] ?? null,
        ] + $this->actorRoleSnapshot($actor);
        $workflowFields['approval_history'] = collect($history)->take(-30)->values()->all();

        return $workflowFields;
    }

    public function recipientUserIdsForNextAction(Report $report): array
    {
        $workflow = $this->effectiveWorkflow($report);
        $assignedUserId = (int) ($workflow['next_action_user_id'] ?? 0);
        if ($assignedUserId > 0) {
            return $assignedUserId === (int) $report->owner_user_id
                ? []
                : [$assignedUserId];
        }
        $nextRole = trim((string) ($workflow['next_action_role'] ?? ''));
        if ($nextRole === '') {
            return [];
        }

        $scopeTeamId = $this->teamScopeForCurrentAction($workflow);

        return $this->activeUserIdsForRole($nextRole, $scopeTeamId, (int) $report->owner_user_id)
            ->values()
            ->all();
    }

    public function approveRole(Report $report): string
    {
        $snapshot = is_array($report->workflow_snapshot) ? $report->workflow_snapshot : [];
        $rules = $this->loadModuleWorkflowRules((string) $report->report_type);

        return trim((string) ($snapshot['approveRole'] ?? $rules['fallback']['approveRole']));
    }

    private function normalizeModuleRules(string $moduleKey, array $source): array
    {
        $moduleKey = $this->normalizeModuleKey($moduleKey);
        $defaults = self::MODULE_DEFAULTS[$moduleKey] ?? self::MODULE_DEFAULTS['inspection'];
        $sourceRules = $this->extractModuleRuleShape($source);

        $fallbackSource = is_array($sourceRules['fallback'] ?? null) ? $sourceRules['fallback'] : [];
        $optionsSource = is_array($sourceRules['options'] ?? null) ? $sourceRules['options'] : [];

        return [
            'fallback' => [
                'reviewRole' => $this->normalizeRole(
                    $fallbackSource['reviewRole'] ?? $defaults['reviewRole'],
                    $defaults['reviewRole'],
                ),
                'fallbackReviewRole' => $this->normalizeRole(
                    $fallbackSource['fallbackReviewRole'] ?? $defaults['fallbackReviewRole'],
                    $defaults['fallbackReviewRole'],
                ),
                'approveRole' => $this->normalizeRole(
                    $fallbackSource['approveRole'] ?? $defaults['approveRole'],
                    $defaults['approveRole'],
                ),
            ],
            'options' => [
                'useTeamScopedAic' => ($optionsSource['useTeamScopedAic'] ?? $defaults['options']['useTeamScopedAic']) !== false,
                'allowSubmitWithoutTeam' => ($optionsSource['allowSubmitWithoutTeam'] ?? $defaults['options']['allowSubmitWithoutTeam']) !== false,
                'allowIcFallbackReview' => ($optionsSource['allowIcFallbackReview'] ?? $defaults['options']['allowIcFallbackReview']) !== false,
                'preventSelfReview' => ($optionsSource['preventSelfReview'] ?? $defaults['options']['preventSelfReview']) !== false,
                'preventSelfApprove' => ($optionsSource['preventSelfApprove'] ?? $defaults['options']['preventSelfApprove']) !== false,
            ],
        ];
    }

    private function extractModuleRuleShape(array $source): array
    {
        if (isset($source['fallback'])) {
            return $source;
        }

        if (isset($source['rules'])) {
            return is_array($source['rules']) ? $source['rules'] : [];
        }

        return [];
    }

    private function normalizeRole(string $value, string $default): string
    {
        $value = trim($value);
        if ($value === '') {
            return $default;
        }

        if (in_array($value, RoleCatalog::ROLES, true)) {
            return $value;
        }

        return $default;
    }

    private function actorRoleSnapshot(User $actor): array
    {
        $role = $this->authorizationService->getPrimaryRoleName($actor);

        return [
            'actorRole' => trim((string) ($role ?? '')),
            'actorRoleCode' => trim((string) (RoleCatalog::abbreviationForRole($role) ?? '')),
        ];
    }

    private function actorMatchesRoleForWorkflow(User $actor, string $role, array $workflow): bool
    {
        $role = trim($role);
        if ($role === '') {
            return false;
        }

        if ($this->authorizationService->isSystemAdministrator($actor)) {
            return true;
        }
        if ($this->missingRequiredTeamScope($workflow, $role)) {
            return false;
        }

        $assignedUserId = (int) ($workflow['next_action_user_id'] ?? 0);
        if ($assignedUserId > 0) {
            if ($assignedUserId !== (int) $actor->id) {
                return false;
            }

            $coverageId = (int) ($workflow['next_action_duty_coverage_assignment_id'] ?? 0);
            if ($coverageId > 0) {
                return DutyCoverageAssignment::query()
                    ->whereKey($coverageId)
                    ->where('user_id', $actor->id)
                    ->effectiveAt(now())
                    ->whereHas(
                        'actingRole',
                        fn ($query) => $query->whereRaw(
                            'LOWER(TRIM(name)) = ?',
                            [strtolower($role)],
                        ),
                    )
                    ->exists();
            }

            return $this->actorHasActiveRole(
                $actor,
                $role,
                $this->teamScopeForCurrentAction($workflow),
            );
        }

        return $this->actorHasActiveRole(
            $actor,
            $role,
            $this->teamScopeForCurrentAction($workflow),
        );
    }

    private function missingRequiredTeamScope(array $workflow, string $role): bool
    {
        if (! RoleCatalog::isScopedRole($role)
            || ! in_array(($workflow['workflow_stage'] ?? null), ['review', 'approve'], true)) {
            return false;
        }

        $snapshot = is_array($workflow['workflow_snapshot'] ?? null)
            ? $workflow['workflow_snapshot']
            : [];
        $options = is_array($snapshot['options'] ?? null) ? $snapshot['options'] : [];

        return ($options['useTeamScopedAic'] ?? true) !== false
            && empty($workflow['scope_team_id']);
    }

    private function teamScopeForCurrentAction(array $workflow): ?int
    {
        if (! in_array(($workflow['workflow_stage'] ?? null), ['review', 'approve'], true)) {
            return null;
        }

        $snapshot = is_array($workflow['workflow_snapshot'] ?? null) ? $workflow['workflow_snapshot'] : [];
        $options = is_array($snapshot['options'] ?? null) ? $snapshot['options'] : [];
        $scopeTeamId = $workflow['scope_team_id'] ?? null;
        $teamScopedReviewEnabled = ($options['useTeamScopedAic'] ?? true) !== false;
        $role = trim((string) ($workflow['next_action_role'] ?? ''));

        if (! $teamScopedReviewEnabled || ! $scopeTeamId || ! RoleCatalog::isScopedRole($role)) {
            return null;
        }

        return (int) $scopeTeamId;
    }

    private function actorHasActiveRole(User $actor, string $role, ?int $teamId = null): bool
    {
        $role = trim($role);
        if ($role === '') {
            return false;
        }

        if ($this->authorizationService->isSystemAdministrator($actor)) {
            return true;
        }

        // Keep legacy/global assignments authoritative for unscoped stages.
        // Team-scoped stages must use the duty-aware resolver so temporary
        // coverage and replaced incumbents are applied consistently.
        if ($teamId === null) {
            return $this->authorizationService->getActiveRoleNames($actor)
                ->contains(fn ($name) => strcasecmp((string) $name, $role) === 0);
        }

        return $this->recipientResolver
            ->resolveRole($role, $teamId)
            ->contains(fn (array $row) => (int) $row['userId'] === (int) $actor->id);
    }

    private function activeUserIdsForRole(string $role, ?int $teamId = null, ?int $excludeUserId = null): Collection
    {
        return $this->recipientResolver
            ->resolveRole($role, $teamId, excludeUserId: $excludeUserId)
            ->pluck('userId')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
    }

    private function resolvePrimaryTeamId(User $user): ?int
    {
        $coverageTeamId = DutyCoverageAssignment::query()
            ->where('user_id', $user->id)
            ->effectiveAt(now())
            ->orderByDesc('effective_from')
            ->value('acting_team_id');
        if ($coverageTeamId) {
            return (int) $coverageTeamId;
        }

        $assignments = collect($this->authorizationService->getRoleAssignmentsPayload($user))
            ->filter(fn ($assignment) => ! empty($assignment['active']) && ! empty($assignment['team_id']))
            ->sortByDesc(fn ($assignment) => ! empty($assignment['is_primary']) ? 1 : 0)
            ->values();

        $teamId = $assignments->first()['team_id'] ?? null;

        return $teamId ? (int) $teamId : null;
    }

    private function preventsSelfReview(Report $report): bool
    {
        $workflow = $this->effectiveWorkflow($report);
        $options = is_array($workflow['workflow_snapshot']['options'] ?? null) ? $workflow['workflow_snapshot']['options'] : [];

        return ($options['preventSelfReview'] ?? true) !== false;
    }

    private function preventsSelfApprove(Report $report): bool
    {
        $workflow = $this->effectiveWorkflow($report);
        $options = is_array($workflow['workflow_snapshot']['options'] ?? null) ? $workflow['workflow_snapshot']['options'] : [];

        return ($options['preventSelfApprove'] ?? true) !== false;
    }

    private function normalizeModuleKey(?string $moduleKey): string
    {
        return strtolower(trim((string) $moduleKey));
    }

    private function moduleLabel(?string $moduleKey): string
    {
        return match ($this->normalizeModuleKey($moduleKey)) {
            'inspection' => 'Inspection',
            'erco' => 'ERCO',
            'drill' => 'Drill',
            'fitness-test' => 'Fitness Test',
            default => 'Report',
        };
    }
}
