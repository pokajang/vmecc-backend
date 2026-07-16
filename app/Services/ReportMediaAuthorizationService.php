<?php

namespace App\Services;

use App\Models\InspectionExtinguisherResult;
use App\Models\InspectionFireExtinguisherIssue;
use App\Models\InspectionSession;
use App\Models\Report;
use App\Models\ReportDraft;
use App\Models\ReportMedia;
use App\Models\User;

class ReportMediaAuthorizationService
{
    public function __construct(
        private readonly AssignmentAuthorizationService $authorizationService,
        private readonly ReportMediaModulePolicy $modulePolicy,
    ) {}

    public function canUseModule(User $user, string $module): bool
    {
        $permission = $this->modulePolicy->permissionFor($module);

        return $permission !== null
            && $this->authorizationService->hasPermission($user, "reports.manage|{$permission}");
    }

    public function canView(User $user, ReportMedia $media): bool
    {
        if ((int) $media->user_id === (int) $user->id) {
            return true;
        }

        foreach ($media->links as $link) {
            if ($this->canViewParent($user, (string) $link->parent_type, (string) $link->parent_key)) {
                return true;
            }
        }

        return false;
    }

    private function canViewParent(User $user, string $parentType, string $parentKey): bool
    {
        if ($parentType === 'report') {
            $report = Report::query()->where('report_uid', $parentKey)->first();
            if (! $report) {
                return false;
            }
            if ((int) $report->owner_user_id === (int) $user->id) {
                return true;
            }
            $permission = $this->modulePolicy->permissionFor($report->report_type);

            return $permission !== null
                && $this->authorizationService->hasPermission($user, "reports.manage|{$permission}");
        }

        if ($parentType === 'report_draft') {
            return ReportDraft::query()->where('draft_id', $parentKey)->where('user_id', $user->id)->exists();
        }

        if ($parentType === 'inspection_result') {
            $result = InspectionExtinguisherResult::query()->with('inspectionSession')->find($parentKey);
            if (! $result) {
                return false;
            }
            if (
                (int) $result->checked_by_user_id === (int) $user->id
                || (int) $result->inspectionSession?->started_by_user_id === (int) $user->id
                || (int) $result->inspectionSession?->submitted_by_user_id === (int) $user->id
            ) {
                return true;
            }

            return $this->authorizationService->hasPermission($user, 'reports.manage|reports.inspection.view');
        }

        if ($parentType === 'inspection_session') {
            $session = InspectionSession::query()->where('session_uid', $parentKey)->first();
            if (! $session) {
                return false;
            }

            return (int) $session->started_by_user_id === (int) $user->id
                || (int) $session->submitted_by_user_id === (int) $user->id
                || $this->authorizationService->hasPermission($user, 'reports.manage|reports.inspection.view');
        }

        if ($parentType === 'fire_extinguisher_issue_resolution') {
            return InspectionFireExtinguisherIssue::query()->where('public_id', $parentKey)->exists()
                && $this->authorizationService->hasPermission(
                    $user,
                    'reports.manage|reports.inspection.view',
                );
        }

        return false;
    }
}
