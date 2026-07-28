<?php

namespace App\Services\WorkflowNotifications;

use App\Models\User;
use App\Models\WorkflowNotification;
use App\Services\AssignmentAuthorizationService;

class WorkflowNotificationLinkResolver
{
    public function __construct(private readonly AssignmentAuthorizationService $authorizationService) {}

    public function resolveRelative(WorkflowNotification $notification, ?User $recipient = null): string
    {
        $metadata = is_array($notification->metadata) ? $notification->metadata : [];
        $module = strtolower(trim((string) ($notification->module ?? $metadata['module'] ?? '')));
        $recordType = strtolower(trim((string) ($notification->record_type ?? $metadata['recordType'] ?? '')));
        $reportType = strtolower(trim((string) ($metadata['reportType'] ?? '')));
        $detailRouteKey = trim((string) ($metadata['detailRouteKey'] ?? ''));
        $reportUid = trim((string) ($metadata['reportUid'] ?? $detailRouteKey));
        $displayId = trim((string) ($notification->record_display_id ?? $metadata['recordDisplayId'] ?? ''));
        $recordId = $notification->record_id ? (string) $notification->record_id : trim((string) ($metadata['recordId'] ?? ''));
        $ownerUserId = $notification->owner_user_id ? (string) $notification->owner_user_id : trim((string) ($metadata['ownerUserId'] ?? ''));

        $actionRequiredForViewer = $recipient ? $this->actionRequiredForRecipient($notification, $recipient, $metadata) : false;

        if ($module === 'report' || $recordType === 'report') {
            if ($module === 'inspection' || $reportType === 'inspection') {
                return $reportUid !== '' ? '/inspection/'.rawurlencode($reportUid) : '/reports?reportType=inspection';
            }

            if ($reportType !== '' && $reportUid !== '') {
                return '/report/'.rawurlencode($reportType).'/'.rawurlencode($reportUid);
            }

            return '/reports';
        }

        if ($module === 'inspection') {
            return $reportUid !== '' ? '/inspection/'.rawurlencode($reportUid) : '/reports?reportType=inspection';
        }

        if ($recordType === 'team' || $module === 'team') {
            $teamId = $detailRouteKey !== '' ? $detailRouteKey : $recordId;

            return $teamId !== '' ? '/team/details/'.rawurlencode($teamId) : '/team';
        }

        if ($recordType === 'roster' || $module === 'roster') {
            return '/roster';
        }

        if ($recordType === 'overtime' || $module === 'overtime') {
            if ($actionRequiredForViewer) {
                $routeKey = $detailRouteKey !== '' ? $detailRouteKey : ($ownerUserId !== '' && $recordId !== '' ? "{$ownerUserId}::{$recordId}" : '');

                return $routeKey !== '' ? '/staff/overtime-management/record/'.rawurlencode($routeKey) : '/staff/overtime-management/records';
            }

            return $displayId !== '' ? '/overtime/'.rawurlencode($displayId) : '/overtime';
        }

        if ($recordType === 'leave' || $module === 'leave') {
            if ($actionRequiredForViewer) {
                $routeKey = $detailRouteKey !== '' ? $detailRouteKey : ($ownerUserId !== '' && $recordId !== '' ? "{$ownerUserId}::{$recordId}" : '');

                return $routeKey !== '' ? '/staff/leave-management/record/'.rawurlencode($routeKey) : '/staff/leave-management/records';
            }

            return $recordId !== '' ? '/leave/'.rawurlencode($recordId) : '/leave';
        }

        if ($recordType === 'salary_assignment') {
            if ($actionRequiredForViewer && $recordId !== '') {
                return '/staff/set-salary/assignment/'.rawurlencode($recordId).'/view';
            }

            $assignmentId = $detailRouteKey !== '' ? $detailRouteKey : $recordId;

            return $assignmentId !== ''
                ? '/staff/set-salary/set-salary?assignmentId='.rawurlencode($assignmentId)
                : '/staff/set-salary/set-salary';
        }

        if (in_array($module, ['salary', 'expense', 'exceptional'], true) || $recordType === 'payroll_claim') {
            if ($actionRequiredForViewer) {
                $staffKey = $ownerUserId !== '' && $recordId !== '' ? "{$ownerUserId}::{$recordId}" : ($detailRouteKey !== '' ? $detailRouteKey : $displayId);

                return $staffKey !== '' ? '/staff/salary-claims/claim/'.rawurlencode($staffKey) : '/staff/salary-claims/claims';
            }

            $claimKey = $displayId !== '' ? $displayId : ($detailRouteKey !== '' ? $detailRouteKey : $recordId);

            return $claimKey !== '' ? '/payroll/claims/'.rawurlencode($claimKey) : '/payroll/claims';
        }

        return '/notifications/workflow';
    }

    public function resolveAbsolute(
        WorkflowNotification $notification,
        ?User $recipient = null,
        ?string $frontendBase = null,
    ): string {
        $path = $this->resolveRelative($notification, $recipient);
        $base = rtrim((string) ($frontendBase ?? config('app.frontend_url', config('app.url', ''))), '/');

        return $base !== '' ? "{$base}{$path}" : $path;
    }

    private function actionRequiredForRecipient(WorkflowNotification $notification, User $recipient, array $metadata): bool
    {
        if (! $notification->action_required || $notification->resolved_at !== null) {
            return false;
        }

        $requiredRole = strtolower(trim((string) ($metadata['nextActionRole'] ?? $metadata['next_action_role'] ?? '')));
        if ($requiredRole === '') {
            return false;
        }

        $viewerRoles = $this->authorizationService->getActiveRoleNames($recipient)
            ->map(fn ($role) => strtolower(trim((string) $role)))
            ->filter()
            ->values()
            ->all();

        return in_array('system administrator', $viewerRoles, true)
            || in_array($requiredRole, $viewerRoles, true);
    }
}
