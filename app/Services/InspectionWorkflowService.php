<?php

namespace App\Services;

use App\Models\Report;
use App\Models\Setting;
use App\Models\User;
use App\Models\UserRoleAssignment;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class InspectionWorkflowService
{
    private const SETTINGS_KEY = 'inspection_workflow_rules';

    private const DEFAULT_RULES = [
        'fallback' => [
            'reviewRole' => 'Assistant Incident Commander',
            'fallbackReviewRole' => 'Incident Commander',
            'approveRole' => 'Incident Commander',
        ],
        'options' => [
            'useTeamScopedAic' => true,
            'allowSubmitWithoutTeam' => true,
            'allowIcFallbackReview' => true,
            'preventSelfReview' => true,
            'preventSelfApprove' => true,
        ],
    ];

    public function __construct(private readonly AssignmentAuthorizationService $authorizationService)
    {
    }

    public function loadWorkflowRules(): array
    {
        $setting = Setting::query()->where('key', self::SETTINGS_KEY)->first();

        return $this->normalizeWorkflowRules($setting?->value ?? []);
    }

    public function saveWorkflowRules(array $rules): void
    {
        Setting::query()->updateOrCreate(
            ['key' => self::SETTINGS_KEY],
            ['value' => $this->normalizeWorkflowRules($rules)],
        );
    }

    public function normalizeWorkflowRules(mixed $value): array
    {
        $source = is_array($value) ? $value : [];
        $fallback = is_array($source['fallback'] ?? null) ? $source['fallback'] : [];
        $options = is_array($source['options'] ?? null) ? $source['options'] : [];

        return [
            'fallback' => [
                'reviewRole' => trim((string) ($fallback['reviewRole'] ?? self::DEFAULT_RULES['fallback']['reviewRole']))
                    ?: self::DEFAULT_RULES['fallback']['reviewRole'],
                'fallbackReviewRole' => trim((string) ($fallback['fallbackReviewRole'] ?? self::DEFAULT_RULES['fallback']['fallbackReviewRole']))
                    ?: self::DEFAULT_RULES['fallback']['fallbackReviewRole'],
                'approveRole' => trim((string) ($fallback['approveRole'] ?? self::DEFAULT_RULES['fallback']['approveRole']))
                    ?: self::DEFAULT_RULES['fallback']['approveRole'],
            ],
            'options' => [
                'useTeamScopedAic' => ($options['useTeamScopedAic'] ?? true) !== false,
                'allowSubmitWithoutTeam' => ($options['allowSubmitWithoutTeam'] ?? true) !== false,
                'allowIcFallbackReview' => ($options['allowIcFallbackReview'] ?? true) !== false,
                'preventSelfReview' => ($options['preventSelfReview'] ?? true) !== false,
                'preventSelfApprove' => ($options['preventSelfApprove'] ?? true) !== false,
            ],
        ];
    }

    public function buildWorkflowForSubmission(User $submitter): array
    {
        $rules = $this->loadWorkflowRules();
        $reviewRole = $rules['fallback']['reviewRole'];
        $fallbackReviewRole = $rules['fallback']['fallbackReviewRole'];
        $approveRole = $rules['fallback']['approveRole'];
        $options = $rules['options'];
        $scopeTeamId = $this->resolvePrimaryTeamId($submitter);
        $hasSameTeamReviewer = $scopeTeamId !== null
            && ($options['useTeamScopedAic'] ?? true)
            && $this->activeUserIdsForRole($reviewRole, $scopeTeamId, (int) $submitter->id)->isNotEmpty();
        $resolvedReviewRole = $hasSameTeamReviewer ? $reviewRole : $fallbackReviewRole;

        return [
            'workflow_stage' => 'review',
            'next_action_role' => $resolvedReviewRole ?: null,
            'scope_team_id' => $scopeTeamId,
            'workflow_snapshot' => [
                'submitterRole' => 'Tactical Response Team',
                'reviewRole' => $reviewRole,
                'fallbackReviewRole' => $fallbackReviewRole,
                'approveRole' => $approveRole,
                'resolvedReviewRole' => $resolvedReviewRole,
                'usedFallbackReview' => ! $hasSameTeamReviewer,
                'scopeTeamId' => $scopeTeamId,
                'options' => $options,
            ],
        ];
    }

    public function submissionBlockReason(User $submitter): ?string
    {
        $rules = $this->loadWorkflowRules();
        $reviewRole = $rules['fallback']['reviewRole'];
        $options = $rules['options'];
        $scopeTeamId = $this->resolvePrimaryTeamId($submitter);

        if ($scopeTeamId === null && ($options['allowSubmitWithoutTeam'] ?? true) === false) {
            return 'Inspection submission requires an active team assignment.';
        }

        $hasSameTeamReviewer = $scopeTeamId !== null
            && ($options['useTeamScopedAic'] ?? true)
            && $this->activeUserIdsForRole($reviewRole, $scopeTeamId, (int) $submitter->id)->isNotEmpty();

        if (! $hasSameTeamReviewer && ($options['allowIcFallbackReview'] ?? true) === false) {
            return 'Inspection submission requires an active same-team reviewer.';
        }

        return null;
    }

    public function draftWorkflowFields(): array
    {
        return [
            'workflow_stage' => null,
            'next_action_role' => null,
            'scope_team_id' => null,
            'workflow_snapshot' => null,
            'approval_history' => null,
        ];
    }

    public function effectiveWorkflow(Report $report): array
    {
        $status = trim((string) ($report->status ?? ''));
        $snapshot = is_array($report->workflow_snapshot) ? $report->workflow_snapshot : [];
        $rules = $this->loadWorkflowRules();
        $fallback = $rules['fallback'];
        $stage = trim((string) ($report->workflow_stage ?? ''));
        $nextRole = trim((string) ($report->next_action_role ?? ''));
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
            'scope_team_id' => $scopeTeamId,
            'workflow_snapshot' => $snapshot ?: [
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
        if ((string) $report->status !== 'Submitted' || $workflow['workflow_stage'] !== 'review') {
            return false;
        }

        return $this->actorMatchesRoleForWorkflow($actor, (string) $workflow['next_action_role'], $workflow);
    }

    public function canApprove(Report $report, User $actor): bool
    {
        if ((int) $report->owner_user_id === (int) $actor->id && $this->preventsSelfApprove($report)) {
            return false;
        }

        $workflow = $this->effectiveWorkflow($report);
        if ((string) $report->status !== 'Reviewed' || $workflow['workflow_stage'] !== 'approve') {
            return false;
        }

        return $this->actorHasActiveRole($actor, $this->approveRole($report), null);
    }

    public function canReject(Report $report, User $actor): bool
    {
        if ((int) $report->owner_user_id === (int) $actor->id && $this->preventsSelfReview($report)) {
            return false;
        }

        $workflow = $this->effectiveWorkflow($report);
        if (!in_array((string) $report->status, ['Submitted', 'Reviewed'], true)) {
            return false;
        }

        return $this->actorMatchesRoleForWorkflow($actor, (string) $workflow['next_action_role'], $workflow);
    }

    public function authorizeAction(Report $report, User $actor, string $action): ?string
    {
        return match ($action) {
            'review' => $this->canReview($report, $actor) ? null : 'You are not assigned to review this inspection report.',
            'approve' => $this->canApprove($report, $actor) ? null : 'You are not assigned to approve this inspection report.',
            'reject' => $this->canReject($report, $actor) ? null : 'You are not assigned to reject this inspection report.',
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
            'stage' => $workflow['workflow_stage'],
            'role' => $workflow['next_action_role'],
        ];
        $history = collect(is_array($report->approval_history) ? $report->approval_history : [])
            ->push($entry)
            ->take(-30)
            ->values()
            ->all();

        if ($action === 'review') {
            return [
                'status' => 'Reviewed',
                'workflow_stage' => 'approve',
                'next_action_role' => $this->approveRole($report),
                'approval_history' => $history,
            ];
        }

        if ($action === 'approve') {
            return [
                'status' => 'Approved',
                'workflow_stage' => 'done',
                'next_action_role' => null,
                'approval_history' => $history,
            ];
        }

        if ($action === 'reject') {
            return [
                'status' => 'Rejected',
                'workflow_stage' => 'done',
                'next_action_role' => null,
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
        ];
        $workflowFields['approval_history'] = collect($history)->take(-30)->values()->all();

        return $workflowFields;
    }

    public function recipientUserIdsForNextAction(Report $report): array
    {
        $workflow = $this->effectiveWorkflow($report);
        $nextRole = trim((string) ($workflow['next_action_role'] ?? ''));
        if ($nextRole === '') {
            return [];
        }

        $scopeTeamId = null;
        if ($nextRole === trim((string) ($workflow['workflow_snapshot']['reviewRole'] ?? '')) && ($workflow['scope_team_id'] ?? null)) {
            $scopeTeamId = (int) $workflow['scope_team_id'];
        }

        return $this->activeUserIdsForRole($nextRole, $scopeTeamId, (int) $report->owner_user_id)
            ->values()
            ->all();
    }

    public function approveRole(Report $report): string
    {
        $snapshot = is_array($report->workflow_snapshot) ? $report->workflow_snapshot : [];
        $rules = $this->loadWorkflowRules();

        return trim((string) ($snapshot['approveRole'] ?? $rules['fallback']['approveRole']));
    }

    private function actorMatchesRoleForWorkflow(User $actor, string $role, array $workflow): bool
    {
        $role = trim($role);
        if ($role === '') {
            return false;
        }

        $snapshot = is_array($workflow['workflow_snapshot'] ?? null) ? $workflow['workflow_snapshot'] : [];
        $reviewRole = trim((string) ($snapshot['reviewRole'] ?? self::DEFAULT_RULES['fallback']['reviewRole']));
        $scopeTeamId = $workflow['scope_team_id'] ?? null;
        $isTeamScopedReview = $scopeTeamId && strcasecmp($role, $reviewRole) === 0;

        return $this->actorHasActiveRole($actor, $role, $isTeamScopedReview ? (int) $scopeTeamId : null);
    }

    private function actorHasActiveRole(User $actor, string $role, ?int $teamId = null): bool
    {
        $role = trim($role);
        if ($role === '') {
            return false;
        }

        if ($teamId === null) {
            return $this->authorizationService->getActiveRoleNames($actor)
                ->contains(fn ($name) => strcasecmp((string) $name, $role) === 0);
        }

        return $this->activeAssignmentsForRole($role, $teamId)
            ->where('user_id', (int) $actor->id)
            ->isNotEmpty();
    }

    private function activeUserIdsForRole(string $role, ?int $teamId = null, ?int $excludeUserId = null): Collection
    {
        $rows = $this->activeAssignmentsForRole($role, $teamId);
        if ($excludeUserId) {
            $rows = $rows->where('user_id', '!=', (int) $excludeUserId);
        }

        return $rows->pluck('user_id')->map(fn ($id) => (int) $id)->unique()->values();
    }

    private function activeAssignmentsForRole(string $role, ?int $teamId = null): Collection
    {
        $today = now()->toDateString();
        $query = UserRoleAssignment::query()
            ->where(function ($builder) use ($today) {
                $builder->whereNull('start_date')->orWhereDate('start_date', '<=', $today);
            })
            ->where(function ($builder) use ($today) {
                $builder->whereNull('end_date')->orWhereDate('end_date', '>=', $today);
            })
            ->whereHas('role', fn ($builder) => $builder->whereRaw('LOWER(TRIM(name)) = ?', [strtolower(trim($role))]))
            ->whereHas('user', function ($builder) {
                $builder->whereNull('deleted_at')
                    ->where(function ($query) {
                        $query->whereNull('status')
                            ->orWhereRaw("LOWER(TRIM(status)) = 'active'");
                    });
            });

        if ($teamId !== null) {
            $query->where('team_id', (int) $teamId);
        }

        return $query->get();
    }

    private function resolvePrimaryTeamId(User $user): ?int
    {
        $assignments = collect($this->authorizationService->getRoleAssignmentsPayload($user))
            ->filter(fn ($assignment) => !empty($assignment['active']) && !empty($assignment['team_id']))
            ->sortByDesc(fn ($assignment) => !empty($assignment['is_primary']) ? 1 : 0)
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
}
