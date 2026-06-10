<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserRoleAssignment;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

class AssignmentAuthorizationService
{
    public function hasPermission(User $user, string $requiredPermissions, ?int $teamId = null): bool
    {
        $permissions = $this->getActivePermissionNames($user, $teamId)->values()->all();
        if (empty($permissions)) {
            return false;
        }

        $required = preg_split('/[|,]/', $requiredPermissions) ?: [];
        $required = array_values(array_filter(array_map('trim', $required)));
        if (empty($required)) {
            return false;
        }

        foreach ($required as $permission) {
            if (in_array('*', $permissions, true) || in_array($permission, $permissions, true)) {
                return true;
            }
        }

        return false;
    }

    public function getActivePermissionNames(User $user, ?int $teamId = null): Collection
    {
        $assignments = $this->activeAssignmentsQuery($user)
            ->with('role.permissions')
            ->get();

        if ($assignments->isEmpty()) {
            return collect($user->getAllPermissions()->pluck('name')->values()->all());
        }

        $filtered = $assignments->filter(function (UserRoleAssignment $assignment) use ($teamId) {
            if (in_array($assignment->scope_type, [RoleCatalog::GLOBAL, RoleCatalog::OFFICE], true)) {
                return true;
            }

            if ($teamId === null) {
                // Module-level checks (no specific team in context) pass if user has scoped permission anywhere.
                return true;
            }

            return (int) ($assignment->team_id ?? 0) === (int) $teamId;
        });

        $permissions = [];
        foreach ($filtered as $assignment) {
            $rolePermissions = $assignment->role?->permissions?->pluck('name')->values()->all() ?? [];
            foreach ($rolePermissions as $name) {
                $permissions[$name] = true;
            }
        }

        return collect(array_keys($permissions));
    }

    public function getActiveRoleNames(User $user): Collection
    {
        $assignments = $this->activeAssignmentsQuery($user)->with('role')->get();
        if ($assignments->isEmpty()) {
            return $this->sortRoleNames(collect($user->getRoleNames()->values()->all()));
        }

        $roles = $assignments
            ->map(fn (UserRoleAssignment $assignment) => $assignment->role?->name)
            ->filter()
            ->unique()
            ->values();

        return $this->sortRoleNames($roles);
    }

    public function getRoleAssignmentsPayload(User $user): array
    {
        $assignments = $this->activeAssignmentsQuery($user)
            ->with(['role', 'team'])
            ->orderByDesc('is_primary')
            ->orderByDesc('id')
            ->get();

        if ($assignments->isEmpty()) {
            return $user->getRoleNames()
                ->values()
                ->map(function (string $roleName, int $index) {
                    return [
                        'id' => null,
                        'role' => $roleName,
                        'role_id' => Role::query()->where('name', $roleName)->value('id'),
                        'scope_type' => RoleCatalog::scopeForRole($roleName),
                        'team_id' => null,
                        'team_name' => null,
                        'start_date' => null,
                        'end_date' => null,
                        'is_primary' => $index === 0,
                        'active' => true,
                    ];
                })
                ->values()
                ->all();
        }

        return $assignments->map(function (UserRoleAssignment $assignment) {
            $now = Carbon::today();
            $startOk = ! $assignment->start_date || $assignment->start_date->lte($now);
            $endOk = ! $assignment->end_date || $assignment->end_date->gte($now);

            return [
                'id' => $assignment->id,
                'role' => $assignment->role?->name,
                'role_id' => $assignment->role_id,
                'scope_type' => $assignment->scope_type,
                'team_id' => $assignment->team_id,
                'team_name' => $assignment->team?->name,
                'start_date' => optional($assignment->start_date)->toDateString(),
                'end_date' => optional($assignment->end_date)->toDateString(),
                'is_primary' => (bool) $assignment->is_primary,
                'active' => $startOk && $endOk,
            ];
        })->values()->all();
    }

    public function replaceAssignments(User $user, array $assignments): array
    {
        return DB::transaction(function () use ($user, $assignments) {
            $user->roleAssignments()->delete();
            return $this->addAssignments($user, $assignments);
        });
    }

    public function addAssignments(User $user, array $assignments): array
    {
        $assignments = array_values($assignments);
        if (empty($assignments)) {
            return [];
        }

        $incomingPrimaryIndex = collect($assignments)->search(fn (array $assignment) => ! empty($assignment['is_primary']));
        $hasExistingPrimary = $user->roleAssignments()->where('is_primary', true)->exists();
        if ($incomingPrimaryIndex === false && ! $hasExistingPrimary) {
            $incomingPrimaryIndex = 0;
        }

        if ($incomingPrimaryIndex !== false) {
            $user->roleAssignments()->update(['is_primary' => false]);
        }

        $created = [];
        foreach ($assignments as $index => $assignment) {
            $assignment['is_primary'] = $incomingPrimaryIndex !== false && $index === (int) $incomingPrimaryIndex;
            $created[] = $this->createAssignment($user, $assignment);
        }
        return $created;
    }

    public function createAssignment(User $user, array $assignment): UserRoleAssignment
    {
        return $user->roleAssignments()->create([
            'role_id' => $assignment['role_id'],
            'scope_type' => $assignment['scope_type'],
            'team_id' => $assignment['team_id'] ?? null,
            'start_date' => $assignment['start_date'] ?? null,
            'end_date' => $assignment['end_date'] ?? null,
            'is_primary' => (bool) ($assignment['is_primary'] ?? false),
        ]);
    }

    private function activeAssignmentsQuery(User $user)
    {
        $today = Carbon::today()->toDateString();

        return $user->roleAssignments()
            ->where(function ($query) use ($today) {
                $query->whereNull('start_date')->orWhereDate('start_date', '<=', $today);
            })
            ->where(function ($query) use ($today) {
                $query->whereNull('end_date')->orWhereDate('end_date', '>=', $today);
            });
    }

    private function sortRoleNames(Collection $roles): Collection
    {
        return $roles
            ->sortByDesc(fn (string $roleName) => RoleCatalog::ROLE_PRIORITY[$roleName] ?? 0)
            ->values();
    }
}
