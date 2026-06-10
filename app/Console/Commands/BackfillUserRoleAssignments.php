<?php

namespace App\Console\Commands;

use App\Models\RoleAssignmentReviewQueue;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\UserRoleAssignment;
use App\Services\RoleCatalog;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Role;

class BackfillUserRoleAssignments extends Command
{
    protected $signature = 'rbac:backfill-user-role-assignments {--reset : Delete existing assignment rows before backfill}';

    protected $description = 'Backfill user_role_assignments from legacy Spatie model_has_roles records.';

    public function handle(): int
    {
        if ($this->option('reset')) {
            UserRoleAssignment::query()->delete();
            RoleAssignmentReviewQueue::query()->delete();
        }

        $users = User::withTrashed()->with('roles')->get();
        $created = 0;
        $queued = 0;

        foreach ($users as $user) {
            $roles = $user->getRoleNames()->values()->all();
            $first = true;

            foreach ($roles as $legacyRoleName) {
                $roleName = $legacyRoleName === 'Client' ? 'Representative' : $legacyRoleName;
                $role = Role::query()->where('name', $roleName)->where('guard_name', 'web')->first();
                if (! $role) {
                    continue;
                }

                $scopeType = RoleCatalog::scopeForRole($roleName);
                $teamId = null;
                if (RoleCatalog::isScopedRole($roleName)) {
                    $teamId = $this->resolveTeamId($user);
                    if (! $teamId) {
                        $reviewRow = RoleAssignmentReviewQueue::query()->firstOrCreate([
                            'user_id' => $user->id,
                            'role_id' => $role->id,
                            'reason' => 'scope_unresolved',
                        ], [
                            'metadata' => [
                                'legacy_role' => $legacyRoleName,
                            ],
                        ]);
                        if ($reviewRow->wasRecentlyCreated) {
                            $queued++;
                        }
                        continue;
                    }
                }

                $exists = UserRoleAssignment::query()
                    ->where('user_id', $user->id)
                    ->where('role_id', $role->id)
                    ->where('scope_type', $scopeType)
                    ->where('team_id', $teamId)
                    ->exists();
                if ($exists) {
                    continue;
                }

                UserRoleAssignment::query()->create([
                    'user_id' => $user->id,
                    'role_id' => $role->id,
                    'scope_type' => $scopeType,
                    'team_id' => $teamId,
                    'start_date' => now()->toDateString(),
                    'end_date' => null,
                    'is_primary' => $first,
                ]);
                $created++;
                $first = false;
            }
        }

        $this->info("Created assignments: {$created}");
        $this->info("Queued for manual review: {$queued}");

        return self::SUCCESS;
    }

    private function resolveTeamId(User $user): ?int
    {
        $activeTeamId = TeamMember::query()
            ->where('user_id', $user->id)
            ->whereNull('ended_at')
            ->value('team_id');
        if ($activeTeamId) {
            return (int) $activeTeamId;
        }

        $teamName = trim((string) $user->team);
        if ($teamName === '') {
            return null;
        }

        $teamId = Team::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($teamName)])->value('id');
        return $teamId ? (int) $teamId : null;
    }
}
