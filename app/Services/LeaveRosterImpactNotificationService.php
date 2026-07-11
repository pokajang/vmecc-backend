<?php

namespace App\Services;

use App\Models\Leave;
use App\Models\Team;
use App\Models\User;

class LeaveRosterImpactNotificationService
{
    public function __construct(
        private readonly LeaveRosterImpactService $impactService,
        private readonly WorkflowNotificationService $notifications,
    ) {
    }

    public function emit(Leave $leave, array $actor, string $change): void
    {
        $owner = $leave->relationLoaded('user') ? $leave->user : User::find($leave->user_id);
        if (! $owner) {
            return;
        }
        $impact = $this->impactService->forLeave($owner, $leave, true);
        $teamIds = collect($impact['items'] ?? [])->pluck('team_id')->filter()->unique()->values();
        if ($teamIds->isEmpty()) {
            return;
        }
        $teamLeadIds = Team::query()
            ->whereIn('id', $teamIds)
            ->whereNotNull('lead_id')
            ->pluck('lead_id')
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();
        $today = now()->toDateString();
        $rosterManagerIds = \App\Models\UserRoleAssignment::query()
            ->with('role.permissions:id,name')
            ->where(function ($query) use ($today) {
                $query->whereNull('start_date')->orWhereDate('start_date', '<=', $today);
            })
            ->where(function ($query) use ($today) {
                $query->whereNull('end_date')->orWhereDate('end_date', '>=', $today);
            })
            ->get()
            ->filter(fn ($assignment) => $assignment->role?->permissions->contains('name', 'rosters.manage'))
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->all();
        $targetUserIds = array_values(array_unique(array_merge($teamLeadIds, $rosterManagerIds)));
        if (empty($targetUserIds)) {
            return;
        }

        $this->notifications->emit(
            module: 'roster',
            eventType: 'roster_changed',
            recordType: 'roster',
            recordId: (int) $leave->id,
            recordDisplayId: (string) $leave->display_id,
            ownerUserId: (int) $leave->user_id,
            actor: $actor,
            targetUserIds: $targetUserIds,
            metadata: [
                'detailRouteKey' => 'roster',
                'status' => $change === 'approved' ? 'Published roster impact' : 'Roster availability restored',
                'rosterImpactChange' => $change,
                'rosterImpactCount' => count($impact['items'] ?? []),
            ],
            excludeOwner: true,
        );
    }
}
