<?php

namespace App\Services;

use App\Models\InspectionSession;
use App\Models\Report;
use App\Models\User;

class InspectionPolicy
{
    public function __construct(
        private readonly InspectionWorkflowService $workflow,
        private readonly InspectionSessionResolverService $sessionResolver,
        private readonly AssignmentAuthorizationService $authorization,
    ) {}

    public function canSubmit(User $actor): InspectionPolicyDecision
    {
        $reason = $this->workflow->submissionBlockReason($actor);

        return $reason === null
            ? InspectionPolicyDecision::allow()
            : InspectionPolicyDecision::deny('inspection_submit_forbidden', $reason);
    }

    public function canEdit(Report $report, User $actor, bool $isSystemAdministrator = false): InspectionPolicyDecision
    {
        if ($isSystemAdministrator) {
            return InspectionPolicyDecision::allow();
        }
        if ((int) $report->owner_user_id !== (int) $actor->id) {
            return InspectionPolicyDecision::deny('inspection_edit_forbidden', 'You cannot edit this inspection.');
        }
        if (! in_array($report->status, ['Draft', 'Submitted', 'Rejected'], true)) {
            return InspectionPolicyDecision::deny('inspection_status_not_editable', 'This inspection cannot be edited in its current status.');
        }

        return InspectionPolicyDecision::allow();
    }

    public function canDelete(Report $report, User $actor, bool $isSystemAdministrator = false): InspectionPolicyDecision
    {
        return (int) $report->owner_user_id === (int) $actor->id || $isSystemAdministrator
            ? InspectionPolicyDecision::allow()
            : InspectionPolicyDecision::deny('inspection_delete_forbidden', 'You cannot delete this inspection.');
    }

    public function canTransition(Report $report, User $actor, string $action): InspectionPolicyDecision
    {
        $reason = $this->workflow->authorizeAction($report, $actor, $action);

        return $reason === null
            ? InspectionPolicyDecision::allow()
            : InspectionPolicyDecision::deny('inspection_'.$action.'_forbidden', $reason);
    }

    public function canReadSession(InspectionSession $session, User $actor): InspectionPolicyDecision
    {
        if (($session->scope_version ?: 'legacy') !== 'v2'
            || (int) $session->started_by_user_id === (int) $actor->id
            || $this->isSessionSupervisor($actor)
            || $this->sessionResolver->userBelongsToScope($actor, $session)) {
            return InspectionPolicyDecision::allow();
        }

        return InspectionPolicyDecision::deny(
            'inspection_session_team_forbidden',
            'This inspection session belongs to another team.',
        );
    }

    public function canWriteSession(InspectionSession $session, User $actor): InspectionPolicyDecision
    {
        if ($session->status !== 'active') {
            return InspectionPolicyDecision::deny(
                'inspection_session_closed',
                'Only active inspection sessions can be changed.',
            );
        }
        if (($session->scope_version ?: 'legacy') === 'v2'
            && (int) $session->started_by_user_id !== (int) $actor->id
            && ! $this->sessionResolver->userBelongsToScope($actor, $session)) {
            return InspectionPolicyDecision::deny(
                'inspection_session_write_forbidden',
                'Only inspectors assigned to this session team can change results.',
            );
        }

        return InspectionPolicyDecision::allow();
    }

    public function canSubmitSession(InspectionSession $session, User $actor): InspectionPolicyDecision
    {
        if (($session->scope_version ?: 'legacy') !== 'v2'
            || (int) $session->started_by_user_id === (int) $actor->id
            || $this->isSessionSupervisor($actor)) {
            return InspectionPolicyDecision::allow();
        }

        return InspectionPolicyDecision::deny(
            'inspection_session_submit_forbidden',
            'Only the session starter or a supervisor can submit this inspection.',
        );
    }

    private function isSessionSupervisor(User $actor): bool
    {
        $supervisorRoles = [
            'system administrator',
            'system admin',
            'admin',
            'contract manager',
            'incident commander',
            'assistant incident commander',
        ];

        return $this->authorization->getActiveRoleNames($actor)
            ->map(fn ($role): string => strtolower(trim((string) $role)))
            ->contains(fn (string $role): bool => in_array($role, $supervisorRoles, true));
    }
}
