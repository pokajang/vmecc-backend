<?php

namespace App\Services;

use App\Models\DutyCoverageAssignment;
use App\Models\Team;
use App\Models\User;
use App\Models\UserRoleAssignment;
use Illuminate\Support\Carbon;

class WorkflowSubmissionContextResolver
{
    public function resolve(User $user, Carbon $effectiveAt, ?string $applicantRole = null): array
    {
        $role = trim((string) $applicantRole);
        $coverage = DutyCoverageAssignment::query()
            ->with(['actingTeam:id,name', 'actingRole:id,name'])
            ->where('user_id', $user->id)
            ->effectiveAt($effectiveAt)
            ->when(
                $role !== '',
                fn ($query) => $query->whereHas(
                    'actingRole',
                    fn ($roleQuery) => $roleQuery->whereRaw(
                        'LOWER(TRIM(name)) = ?',
                        [strtolower($role)],
                    ),
                ),
            )
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();

        if ($coverage) {
            return [
                'teamId' => (int) $coverage->acting_team_id,
                'teamName' => (string) ($coverage->actingTeam?->name ?? ''),
                'applicantRole' => (string) ($coverage->actingRole?->name ?? $role),
                'routingSource' => 'temporary_coverage',
                'dutyCoverageAssignmentId' => (int) $coverage->id,
            ];
        }

        $date = $effectiveAt->toDateString();
        $assignment = UserRoleAssignment::query()
            ->with(['team:id,name', 'role:id,name'])
            ->where('user_id', $user->id)
            ->where(function ($query) use ($date) {
                $query->whereNull('start_date')->orWhereDate('start_date', '<=', $date);
            })
            ->where(function ($query) use ($date) {
                $query->whereNull('end_date')->orWhereDate('end_date', '>=', $date);
            })
            ->whereNotNull('team_id')
            ->when(
                $role !== '',
                fn ($query) => $query->whereHas(
                    'role',
                    fn ($roleQuery) => $roleQuery->whereRaw(
                        'LOWER(TRIM(name)) = ?',
                        [strtolower($role)],
                    ),
                ),
            )
            ->orderByDesc('is_primary')
            ->orderByDesc('id')
            ->first();

        if ($assignment) {
            return [
                'teamId' => (int) $assignment->team_id,
                'teamName' => (string) ($assignment->team?->name ?? ''),
                'applicantRole' => (string) ($assignment->role?->name ?? $role),
                'routingSource' => 'role_assignment',
                'dutyCoverageAssignmentId' => null,
            ];
        }

        $legacyTeamName = trim((string) $user->team);
        $legacyTeam = $legacyTeamName !== ''
            ? Team::query()->where('name', $legacyTeamName)->first(['id', 'name'])
            : null;

        return [
            'teamId' => $legacyTeam ? (int) $legacyTeam->id : null,
            'teamName' => (string) ($legacyTeam?->name ?? ''),
            'applicantRole' => $role !== '' ? $role : null,
            'routingSource' => $legacyTeam ? 'legacy_team' : 'organization',
            'dutyCoverageAssignmentId' => null,
        ];
    }
}
