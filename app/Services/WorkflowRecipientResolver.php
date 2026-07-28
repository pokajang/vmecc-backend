<?php

namespace App\Services;

use App\Models\DutyCoverageAssignment;
use App\Models\User;
use App\Models\UserRoleAssignment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class WorkflowRecipientResolver
{
    public function resolveForWorkflowRole(
        string $role,
        ?int $workflowTeamId = null,
        ?Carbon $at = null,
        ?int $excludeUserId = null,
    ): Collection {
        return $this->resolveRole($role, $workflowTeamId, $at, $excludeUserId);
    }

    public function resolveRole(
        string $role,
        ?int $teamId = null,
        ?Carbon $at = null,
        ?int $excludeUserId = null,
    ): Collection {
        $role = trim($role);
        if ($role === '') {
            return collect();
        }

        $at ??= now();
        $today = $at->toDateString();
        $roleScope = RoleCatalog::scopeForRole($role);
        $teamScoped = in_array($roleScope, [RoleCatalog::SITE, RoleCatalog::CLIENT_SITE], true);
        if ($teamScoped && $teamId === null) {
            return collect();
        }

        // Temporary coverage is meaningful only for team-scoped operational
        // roles. Ignore malformed coverage rows for organization-wide roles.
        $coverages = $teamScoped
            ? DutyCoverageAssignment::query()
                ->with(['user:id,status,deleted_at', 'actingRole:id,name'])
                ->effectiveAt($at)
                ->where('acting_team_id', $teamId)
                ->whereHas(
                    'actingRole',
                    fn ($query) => $query->whereRaw(
                        'LOWER(TRIM(name)) = ?',
                        [strtolower($role)],
                    ),
                )
                ->whereHas('user', fn ($query) => $this->activeUserQuery($query))
                ->orderByDesc('effective_from')
                ->orderBy('id')
                ->get()
            : collect();

        $replacedUserIds = $coverages
            ->pluck('replaces_user_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->all();

        $permanent = UserRoleAssignment::query()
            ->with(['user:id,status,deleted_at', 'role:id,name'])
            ->where(function ($query) use ($today) {
                $query->whereNull('start_date')->orWhereDate('start_date', '<=', $today);
            })
            ->where(function ($query) use ($today) {
                $query->whereNull('end_date')->orWhereDate('end_date', '>=', $today);
            })
            ->whereHas(
                'role',
                fn ($query) => $query->whereRaw(
                    'LOWER(TRIM(name)) = ?',
                    [strtolower($role)],
                ),
            )
            ->whereHas('user', fn ($query) => $this->activeUserQuery($query))
            ->where(function ($query) use ($roleScope, $teamId, $teamScoped) {
                if ($teamScoped) {
                    $query
                        ->where('scope_type', $roleScope)
                        ->where('team_id', $teamId);

                    return;
                }

                $query->whereIn('scope_type', [RoleCatalog::GLOBAL, RoleCatalog::OFFICE]);
            })
            ->when(
                $replacedUserIds !== [],
                fn ($query) => $query->whereNotIn('user_id', $replacedUserIds),
            )
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->get();

        $resolved = $coverages
            ->map(fn (DutyCoverageAssignment $coverage) => [
                'userId' => (int) $coverage->user_id,
                'role' => (string) ($coverage->actingRole?->name ?? $role),
                'teamId' => (int) $coverage->acting_team_id,
                'source' => 'temporary_coverage',
                'dutyCoverageAssignmentId' => (int) $coverage->id,
                'effectiveFrom' => $coverage->effective_from?->toIso8601String(),
                'effectiveUntil' => $coverage->effective_until?->toIso8601String(),
            ])
            ->concat($permanent->map(fn (UserRoleAssignment $assignment) => [
                'userId' => (int) $assignment->user_id,
                'role' => (string) ($assignment->role?->name ?? $role),
                'teamId' => $assignment->team_id ? (int) $assignment->team_id : null,
                'source' => 'role_assignment',
                'dutyCoverageAssignmentId' => null,
                'effectiveFrom' => optional($assignment->start_date)->startOfDay()?->toIso8601String(),
                'effectiveUntil' => optional($assignment->end_date)->endOfDay()?->toIso8601String(),
            ]));

        // Older accounts may only have Spatie roles. Preserve that compatibility
        // for organization-wide roles until their scoped assignments are migrated.
        if (! $teamScoped) {
            $legacy = User::query()
                ->whereNull('deleted_at')
                ->whereDoesntHave('roleAssignments')
                ->whereHas(
                    'roles',
                    fn ($query) => $query->whereRaw(
                        'LOWER(TRIM(name)) = ?',
                        [strtolower($role)],
                    ),
                )
                ->where(function ($query) {
                    $query->whereNull('status')
                        ->orWhereRaw("LOWER(TRIM(status)) = 'active'");
                })
                ->get(['id'])
                ->map(fn (User $user) => [
                    'userId' => (int) $user->id,
                    'role' => $role,
                    'teamId' => null,
                    'source' => 'legacy_role',
                    'dutyCoverageAssignmentId' => null,
                    'effectiveFrom' => null,
                    'effectiveUntil' => null,
                ]);
            $resolved = $resolved->concat($legacy);
        }

        return $resolved
            ->when(
                $excludeUserId !== null,
                fn (Collection $rows) => $rows->reject(
                    fn (array $row) => (int) $row['userId'] === $excludeUserId,
                ),
            )
            ->unique('userId')
            ->values();
    }

    public function resolveFirst(
        string $role,
        ?int $teamId = null,
        ?Carbon $at = null,
        ?int $excludeUserId = null,
    ): ?array {
        $row = $this->resolveRole($role, $teamId, $at, $excludeUserId)->first();

        return is_array($row) ? $row : null;
    }

    private function activeUserQuery($query): void
    {
        $query->whereNull('deleted_at')
            ->where(function ($builder) {
                $builder->whereNull('status')
                    ->orWhereRaw("LOWER(TRIM(status)) = 'active'");
            });
    }
}
