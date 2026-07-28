<?php

namespace App\Services;

use App\Models\OvertimeRecord;
use App\Models\Team;
use App\Models\User;
use App\Models\UserRoleAssignment;
use Illuminate\Database\Eloquent\Builder;

class OvertimeManagementScopeService
{
    private const MANAGEMENT_PERMISSION = 'staff.overtime.manage';

    private array $activeAssignmentsCache = [];

    private array $ownerTeamIdsCache = [];

    private array $teamIdByNameCache = [];

    public function scopeVisibleRecords(Builder $query, User $actor): Builder
    {
        $scope = $this->managementScope($actor);
        if ($scope['global']) {
            return $query;
        }

        $teamIds = $scope['teamIds'];
        if ($teamIds === []) {
            return $query->whereRaw('1 = 0');
        }

        $teamNames = Team::query()->whereIn('id', $teamIds)->pluck('name')->all();
        $today = now()->toDateString();

        return $query->where(function (Builder $recordQuery) use ($teamIds, $teamNames, $today) {
            $recordQuery->whereIn('workflow_team_id', $teamIds)
                ->orWhere(function (Builder $legacyQuery) use ($teamIds, $teamNames, $today) {
                    $legacyQuery->whereNull('workflow_team_id')
                        ->whereHas('user', function (Builder $userQuery) use ($teamIds, $teamNames, $today) {
                            $userQuery->where(function (Builder $ownerQuery) use ($teamIds, $teamNames, $today) {
                                $ownerQuery
                                    ->whereHas('roleAssignments', function (Builder $assignmentQuery) use ($teamIds, $today) {
                                        $this->applyActiveWindow($assignmentQuery, $today);
                                        $assignmentQuery->whereIn('team_id', $teamIds);
                                    })
                                    ->orWhereIn('team', $teamNames);
                            });
                        });
                });
        });
    }

    public function canManageRecord(User $actor, OvertimeRecord $record): bool
    {
        $scope = $this->managementScope($actor);
        if ($scope['global']) {
            return true;
        }

        $recordTeamIds = $record->workflow_team_id
            ? [(int) $record->workflow_team_id]
            : $this->ownerTeamIds($record->user);

        return count(array_intersect($scope['teamIds'], $recordTeamIds)) > 0;
    }

    public function canPerformWorkflowRole(User $actor, OvertimeRecord $record, string $roleName): bool
    {
        if ($this->isSystemAdministrator($actor)) {
            return true;
        }

        $roleName = trim($roleName);
        if ($roleName === '') {
            return false;
        }

        $assignments = $this->activeAssignmentsFor($actor)
            ->filter(fn (UserRoleAssignment $assignment) => $assignment->role?->name === $roleName)
            ->values();

        if ($assignments->isEmpty()) {
            return $actor->roleAssignments()->doesntExist() && $actor->hasRole($roleName);
        }

        $ownerTeamIds = $record->workflow_team_id
            ? [(int) $record->workflow_team_id]
            : $this->ownerTeamIds($record->user);
        foreach ($assignments as $assignment) {
            if (! $this->roleCanManageOvertime($assignment)) {
                continue;
            }

            if (in_array($assignment->scope_type, [RoleCatalog::GLOBAL, RoleCatalog::OFFICE], true)) {
                return true;
            }

            if (in_array((int) $assignment->team_id, $ownerTeamIds, true)) {
                return true;
            }
        }

        return false;
    }

    public function isSystemAdministrator(User $actor): bool
    {
        return $this->activeAssignmentsFor($actor)
            ->contains(fn (UserRoleAssignment $assignment) => $assignment->role?->name === 'System Administrator')
            || ($actor->roleAssignments()->doesntExist() && $actor->hasRole('System Administrator'));
    }

    private function managementScope(User $actor): array
    {
        if ($this->isSystemAdministrator($actor)) {
            return ['global' => true, 'teamIds' => []];
        }

        $assignments = $this->activeAssignmentsFor($actor);
        if ($assignments->isEmpty()) {
            return [
                'global' => $actor->getAllPermissions()->contains('name', self::MANAGEMENT_PERMISSION),
                'teamIds' => [],
            ];
        }

        $teamIds = [];
        foreach ($assignments as $assignment) {
            if (! $this->roleCanManageOvertime($assignment)) {
                continue;
            }

            if (in_array($assignment->scope_type, [RoleCatalog::GLOBAL, RoleCatalog::OFFICE], true)) {
                return ['global' => true, 'teamIds' => []];
            }

            if ((int) $assignment->team_id > 0) {
                $teamIds[] = (int) $assignment->team_id;
            }
        }

        return ['global' => false, 'teamIds' => array_values(array_unique($teamIds))];
    }

    private function ownerTeamIds(?User $owner): array
    {
        if (! $owner) {
            return [];
        }

        $cacheKey = (int) $owner->id;
        if (array_key_exists($cacheKey, $this->ownerTeamIdsCache)) {
            return $this->ownerTeamIdsCache[$cacheKey];
        }

        $today = now()->toDateString();
        $assignments = $owner->relationLoaded('roleAssignments')
            ? $owner->roleAssignments
            : $owner->roleAssignments()->get();
        $teamIds = $assignments
            ->filter(fn (UserRoleAssignment $assignment) => $assignment->team_id
                && (! $assignment->start_date || $assignment->start_date->lte($today))
                && (! $assignment->end_date || $assignment->end_date->gte($today)))
            ->pluck('team_id')
            ->map(fn ($teamId) => (int) $teamId)
            ->filter(fn (int $teamId) => $teamId > 0)
            ->all();

        $legacyTeam = trim((string) $owner->team);
        if ($legacyTeam !== '') {
            if (! array_key_exists($legacyTeam, $this->teamIdByNameCache)) {
                $this->teamIdByNameCache[$legacyTeam] = Team::query()
                    ->where('name', $legacyTeam)
                    ->value('id');
            }
            $legacyTeamId = $this->teamIdByNameCache[$legacyTeam];
            if ($legacyTeamId) {
                $teamIds[] = (int) $legacyTeamId;
            }
        }

        return $this->ownerTeamIdsCache[$cacheKey] = array_values(array_unique($teamIds));
    }

    private function activeAssignmentsFor(User $user)
    {
        $cacheKey = (int) $user->id;
        if (array_key_exists($cacheKey, $this->activeAssignmentsCache)) {
            return $this->activeAssignmentsCache[$cacheKey];
        }

        $today = now()->toDateString();

        return $this->activeAssignmentsCache[$cacheKey] = $user->roleAssignments()
            ->with('role.permissions')
            ->where(function (Builder $query) use ($today) {
                $query->whereNull('start_date')->orWhereDate('start_date', '<=', $today);
            })
            ->where(function (Builder $query) use ($today) {
                $query->whereNull('end_date')->orWhereDate('end_date', '>=', $today);
            })
            ->get();
    }

    private function roleCanManageOvertime(UserRoleAssignment $assignment): bool
    {
        $permissions = $assignment->role?->permissions?->pluck('name')->all() ?? [];

        return in_array('*', $permissions, true) || in_array(self::MANAGEMENT_PERMISSION, $permissions, true);
    }

    private function applyActiveWindow(Builder $query, string $today): void
    {
        $query
            ->where(function (Builder $windowQuery) use ($today) {
                $windowQuery->whereNull('start_date')->orWhereDate('start_date', '<=', $today);
            })
            ->where(function (Builder $windowQuery) use ($today) {
                $windowQuery->whereNull('end_date')->orWhereDate('end_date', '>=', $today);
            });
    }
}
