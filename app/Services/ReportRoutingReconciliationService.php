<?php

namespace App\Services;

use App\Models\Report;
use App\Models\ReportRoutingEvent;
use Illuminate\Support\Facades\DB;

class ReportRoutingReconciliationService
{
    public function __construct(
        private readonly WorkflowRecipientResolver $recipients,
        private readonly WorkflowNotificationService $notifications,
    ) {}

    public function reconcile(
        ?int $teamId = null,
        ?string $role = null,
        ?int $actorUserId = null,
    ): array {
        $query = Report::query()
            ->whereIn('status', ['Submitted', 'Reviewed'])
            ->whereIn('workflow_stage', ['review', 'approve'])
            ->when($teamId, fn ($builder) => $builder->where('scope_team_id', $teamId))
            ->when(
                trim((string) $role) !== '',
                fn ($builder) => $builder->whereRaw(
                    'LOWER(TRIM(next_action_role)) = ?',
                    [strtolower(trim((string) $role))],
                ),
            )
            ->orderBy('id');

        $result = ['checked' => 0, 'reassigned' => 0, 'unassigned' => 0];
        $query->pluck('id')->each(function (int $reportId) use (&$result, $actorUserId): void {
            $outcome = DB::transaction(function () use ($reportId, $actorUserId): string {
                $report = Report::query()->lockForUpdate()->find($reportId);
                if (! $report
                    || ! in_array($report->status, ['Submitted', 'Reviewed'], true)
                    || ! in_array($report->workflow_stage, ['review', 'approve'], true)) {
                    return 'unchanged';
                }

                $requiredRole = trim((string) $report->next_action_role);
                if ($requiredRole === '') {
                    return 'unchanged';
                }
                $scopeTeamId = $this->teamScope($report, $requiredRole);
                $candidates = $this->recipients->resolveRole(
                    $requiredRole,
                    $scopeTeamId,
                    now(),
                    (int) $report->owner_user_id,
                );
                $currentUserId = (int) ($report->next_action_user_id ?? 0);
                $currentCoverageId = (int) ($report->next_action_duty_coverage_assignment_id ?? 0);
                if ($currentUserId === 0
                    && $currentCoverageId === 0
                    && $report->routing_reason_code !== 'no_eligible_recipient') {
                    return 'unchanged';
                }
                $current = $candidates->first(
                    fn (array $candidate) => (int) $candidate['userId'] === $currentUserId,
                );
                $currentReason = is_array($current)
                    ? $this->routingReason($report, $current, $scopeTeamId)
                    : 'no_eligible_recipient';

                if (is_array($current)
                    && (int) ($current['dutyCoverageAssignmentId'] ?? 0) === $currentCoverageId
                    && $report->routing_reason_code === $currentReason) {
                    return 'unchanged';
                }
                if (! is_array($current)
                    && $currentUserId === 0
                    && $currentCoverageId === 0
                    && $candidates->isEmpty()
                    && $report->routing_reason_code === 'no_eligible_recipient') {
                    return 'unchanged';
                }

                $replacement = $candidates->first();
                $replacementUserId = is_array($replacement)
                    ? (int) $replacement['userId']
                    : null;
                $replacementCoverageId = is_array($replacement)
                    ? ($replacement['dutyCoverageAssignmentId'] ?? null)
                    : null;
                $routingReason = is_array($replacement)
                    ? $this->routingReason($report, $replacement, $scopeTeamId)
                    : 'no_eligible_recipient';

                $report->update([
                    'next_action_user_id' => $replacementUserId,
                    'next_action_duty_coverage_assignment_id' => $replacementCoverageId,
                    'routing_reason_code' => $routingReason,
                ]);

                ReportRoutingEvent::query()->create([
                    'report_id' => $report->id,
                    'event_type' => 'routing_reconciled',
                    'from_user_id' => $currentUserId ?: null,
                    'to_user_id' => $replacementUserId,
                    'team_id' => $scopeTeamId,
                    'required_role' => $requiredRole,
                    'created_by_user_id' => $actorUserId,
                    'metadata' => [
                        'automated' => $actorUserId === null,
                        'routingReasonCode' => $routingReason,
                        'dutyCoverageAssignmentId' => $replacementCoverageId,
                    ],
                ]);

                if ($replacementUserId) {
                    $this->notifications->emit(
                        module: $report->report_type === 'inspection' ? 'inspection' : 'report',
                        eventType: 'workflow_reassigned',
                        recordType: 'report',
                        recordId: (int) $report->id,
                        recordDisplayId: (string) $report->display_id,
                        ownerUserId: (int) $report->owner_user_id,
                        actor: [
                            'userId' => $actorUserId,
                            'name' => $actorUserId ? 'Workflow administrator' : 'Workflow routing service',
                            'email' => '',
                        ],
                        targetUserIds: [$replacementUserId],
                        actionRequired: true,
                        metadata: [
                            'status' => $report->status,
                            'workflowStage' => $report->workflow_stage,
                            'nextActionRole' => $requiredRole,
                            'scopeTeamId' => $scopeTeamId,
                            'routingReasonCode' => $routingReason,
                            'dutyCoverageAssignmentId' => $replacementCoverageId,
                            'reportType' => $report->report_type,
                            'reportUid' => $report->report_uid,
                            'detailRouteKey' => $report->report_uid,
                        ],
                        excludeOwner: true,
                    );
                }

                return $replacementUserId ? 'reassigned' : 'unassigned';
            }, 3);

            $result['checked']++;
            if (array_key_exists($outcome, $result)) {
                $result[$outcome]++;
            }
        });

        return $result;
    }

    private function teamScope(Report $report, string $role): ?int
    {
        if (! $report->scope_team_id || ! RoleCatalog::isScopedRole($role)) {
            return null;
        }

        $snapshot = is_array($report->workflow_snapshot) ? $report->workflow_snapshot : [];
        $options = is_array($snapshot['options'] ?? null) ? $snapshot['options'] : [];

        return ($options['useTeamScopedAic'] ?? true) === false
            ? null
            : (int) $report->scope_team_id;
    }

    private function routingReason(
        Report $report,
        array $recipient,
        ?int $scopeTeamId,
    ): string {
        if (($recipient['source'] ?? '') === 'temporary_coverage') {
            return 'team_temporary_coverage';
        }
        if ($report->workflow_stage === 'approve') {
            return 'approval_role_assignment';
        }
        $snapshot = is_array($report->workflow_snapshot) ? $report->workflow_snapshot : [];
        if (($snapshot['usedFallbackReview'] ?? false) === true) {
            return 'fallback_role_assignment';
        }

        return $scopeTeamId ? 'team_role_assignment' : 'fallback_role_assignment';
    }
}
