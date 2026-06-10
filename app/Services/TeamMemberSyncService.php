<?php

namespace App\Services;

use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\UserRoleAssignment;
use App\Notifications\TeamAssignmentNotification;
use App\Notifications\TeamRosterChangedNotification;
use App\Services\WorkflowNotificationService;
use Illuminate\Support\Facades\DB;

class TeamMemberSyncService
{
    public function __construct(private readonly WorkflowNotificationService $workflowNotifications)
    {
    }

    /**
     * Sync team_members from a user's active role assignments.
     *
     * - Upserts a team_members row for every active assignment that has a team_id.
     * - Sets ended_at on rows no longer covered by an active assignment.
     * - Fires TeamAssignmentNotification to the user on new inserts.
     * - Fires TeamRosterChangedNotification to IC/AIC of the team on new inserts.
     */
    public function syncForUser(
        User $user,
        bool $notifyAssignedUser = true,
        bool $notifyLeaders = true,
    ): void
    {
        $today = now()->toDateString();

        $activeAssignments = UserRoleAssignment::query()
            ->where('user_id', $user->id)
            ->whereNotNull('team_id')
            ->where(function ($q) use ($today) {
                $q->whereNull('start_date')->orWhere('start_date', '<=', $today);
            })
            ->where(function ($q) use ($today) {
                $q->whereNull('end_date')->orWhere('end_date', '>=', $today);
            })
            ->with('role')
            ->get();

        $assignmentMap = $activeAssignments->mapWithKeys(fn($a) => [
            $a->team_id => $a->role?->name ?? '',
        ]);

        // Bulk-fetch all existing team_member rows for this user in one query
        // rather than issuing a separate SELECT per assignment.
        $activeTeamIds   = $activeAssignments->pluck('team_id')->all();
        $existingMembers = TeamMember::query()
            ->where('user_id', $user->id)
            ->whereIn('team_id', $activeTeamIds)
            ->get()
            ->keyBy('team_id');

        // Collect new-member assignments before the transaction so notifications
        // can be dispatched outside the transaction (mail failure ≠ sync rollback).
        $newAssignments = [];

        DB::transaction(function () use (
            $user, $today, $activeAssignments, $existingMembers, $assignmentMap, &$newAssignments
        ) {
            foreach ($activeAssignments as $assignment) {
                $existing = $existingMembers->get($assignment->team_id);
                $isNew    = ! $existing || $existing->ended_at !== null;

                TeamMember::updateOrCreate(
                    [
                        'team_id' => $assignment->team_id,
                        'user_id' => $user->id,
                    ],
                    [
                        'name'       => $user->name,
                        'role'       => $assignment->role?->name ?? '',
                        'is_primary' => $assignment->is_primary,
                        'started_at' => $assignment->start_date?->toDateString() ?? $today,
                        'ended_at'   => null,
                    ]
                );

                if ($isNew) {
                    $newAssignments[] = [
                        'teamId'   => $assignment->team_id,
                        'roleName' => $assignment->role?->name ?? '',
                    ];
                }
            }

            // End rows no longer covered by an active assignment
            TeamMember::query()
                ->where('user_id', $user->id)
                ->whereNull('ended_at')
                ->get()
                ->each(function (TeamMember $member) use ($assignmentMap) {
                    if (! $assignmentMap->has($member->team_id)) {
                        $member->update(['ended_at' => now()->toDateString()]);
                    }
                });
        });

        // Fire notifications outside the transaction so a mail failure cannot
        // roll back the membership writes.
        foreach ($newAssignments as $entry) {
            $this->fireNewMemberNotifications(
                $user,
                $entry['teamId'],
                $entry['roleName'],
                $notifyAssignedUser,
                $notifyLeaders,
            );
        }
    }

    /**
     * Fire notifications when a user is newly added to a team.
     * Called from TeamController::update as well (EditTeamModal path).
     */
    public function fireNewMemberNotifications(
        User $newMember,
        int $teamId,
        string $roleName,
        bool $notifyAssignedUser = true,
        bool $notifyLeaders = true,
    ): void
    {
        $team = Team::with(['members' => function ($q) {
            $q->whereNull('ended_at');
        }])->find($teamId);

        if (! $team) {
            return;
        }

        $actor = ['userId' => null, 'name' => 'System', 'email' => ''];

        $emailEnabled = config('mail.workflow_notifications.enabled', false)
            && (bool) config('mail.workflow_notifications.modules.team', false);

        // Notify the assigned user (optionally suppressed for initial user onboarding flow)
        if ($notifyAssignedUser) {
            if ($emailEnabled) {
                $newMember->notify(new TeamAssignmentNotification($team, $roleName));
            }

            // In-app notification for the assigned user
            $this->workflowNotifications->emit(
                module: 'team',
                eventType: 'member_assigned',
                recordType: 'team',
                recordId: $team->id,
                recordDisplayId: $team->name,
                ownerUserId: $newMember->id,
                actor: $actor,
                targetUserIds: [$newMember->id],
                metadata: [
                    'teamId'   => $team->id,
                    'teamName' => $team->name,
                    'role'     => $roleName,
                ],
            );
        }

        // Notify IC and AIC of the team (excluding the new member themselves)
        if (! $notifyLeaders) {
            return;
        }
        $leaderRoles = ['incident commander', 'assistant incident commander'];
        $memberCount = $team->members->count();

        $leaders = $team->members->filter(fn($m) =>
            $m->user_id &&
            $m->user_id !== $newMember->id &&
            collect($leaderRoles)->contains(fn($r) => str_contains(strtolower($m->role ?? ''), $r))
        );

        if ($leaders->isNotEmpty()) {
            $leaderUserIds = $leaders->pluck('user_id')->values()->all();
            $leaderUsers   = User::whereIn('id', $leaderUserIds)->get()->keyBy('id');

            if ($emailEnabled) {
                $leaders->each(function (TeamMember $leader) use ($team, $newMember, $roleName, $memberCount, $leaderUsers) {
                    $leaderUser = $leaderUsers->get($leader->user_id);
                    if ($leaderUser) {
                        $leaderUser->notify(new TeamRosterChangedNotification(
                            $team,
                            $newMember,
                            $roleName,
                            $memberCount,
                        ));
                    }
                });
            }

            // Single in-app notification fanned out to all leaders at once
            $this->workflowNotifications->emit(
                module: 'team',
                eventType: 'roster_changed',
                recordType: 'team',
                recordId: $team->id,
                recordDisplayId: $team->name,
                ownerUserId: $leaders->first()->user_id,
                actor: $actor,
                targetUserIds: $leaderUserIds,
                metadata: [
                    'teamId'        => $team->id,
                    'teamName'      => $team->name,
                    'newMemberName' => $newMember->name,
                    'newMemberRole' => $roleName,
                    'memberCount'   => $memberCount,
                ],
            );
        }
    }
}
