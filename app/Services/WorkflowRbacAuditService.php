<?php

namespace App\Services;

use App\Models\Leave;
use App\Models\OvertimeRecord;
use App\Models\PayrollClaim;
use App\Models\Report;
use App\Models\UserRoleAssignment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

class WorkflowRbacAuditService
{
    private array $ownershipIssueCache = [];

    private const MODULES = [
        'leave' => [Leave::class, ['Pending'], 'staff.leave.manage', 'display_id'],
        'overtime' => [OvertimeRecord::class, ['Pending'], 'staff.overtime.manage', 'display_id'],
        'payroll' => [PayrollClaim::class, ['Pending'], 'staff.salary.manage', 'display_id'],
        'report' => [Report::class, ['Submitted', 'Reviewed'], 'reports.manage', 'display_id'],
    ];

    public function pendingOwnershipIssues(): Collection
    {
        $issues = collect();

        foreach (self::MODULES as $module => [$modelClass, $statuses, $permission, $displayColumn]) {
            $modelClass::query()
                ->whereIn('status', $statuses)
                ->where(fn ($query) => $query->whereNull('workflow_stage')->orWhere('workflow_stage', '!=', 'done'))
                ->orderBy('id')
                ->chunkById(200, function ($records) use ($issues, $module, $permission, $displayColumn): void {
                    foreach ($records as $record) {
                        $roleName = trim((string) ($record->next_action_role ?? ''));
                        $reason = $this->ownershipIssue($roleName, $permission);
                        if ($reason === null) {
                            continue;
                        }
                        $issues->push([
                            'module' => $module,
                            'record_id' => (string) $record->getKey(),
                            'display_id' => (string) ($record->{$displayColumn} ?? ''),
                            'status' => (string) $record->status,
                            'stage' => (string) ($record->workflow_stage ?? ''),
                            'next_action_role' => $roleName,
                            'reason' => $reason,
                        ]);
                    }
                });
        }

        return $issues;
    }

    public function assertRoleCanOwnModule(string $module, string $roleName): Role
    {
        $config = self::MODULES[$module] ?? null;
        if (! $config) {
            throw new \InvalidArgumentException("Unsupported workflow module '{$module}'.");
        }

        $role = Role::query()->with('permissions')->where('name', $roleName)->first();
        if (! $role) {
            throw new \InvalidArgumentException("Role '{$roleName}' does not exist.");
        }
        $permission = $config[2];
        if ($roleName !== 'System Administrator' && ! $role->permissions->contains('name', $permission)) {
            throw new \InvalidArgumentException("Role '{$roleName}' is missing {$permission}.");
        }
        if (! $this->hasActiveAssignee((int) $role->id)) {
            throw new \InvalidArgumentException("Role '{$roleName}' has no active assignee.");
        }

        return $role;
    }

    public function reassignableQuery(string $module, ?string $fromRole = null)
    {
        $config = self::MODULES[$module] ?? null;
        if (! $config) {
            throw new \InvalidArgumentException("Unsupported workflow module '{$module}'.");
        }
        [$modelClass, $statuses] = $config;
        $query = $modelClass::query()
            ->whereIn('status', $statuses)
            ->where(fn ($builder) => $builder->whereNull('workflow_stage')->orWhere('workflow_stage', '!=', 'done'));

        if ($fromRole === null || trim($fromRole) === '') {
            $query->where(fn ($builder) => $builder->whereNull('next_action_role')->orWhere('next_action_role', ''));
        } else {
            $query->where('next_action_role', trim($fromRole));
        }

        return $query;
    }

    public function ownerUserId(Model $record): ?int
    {
        $value = $record instanceof Report ? $record->owner_user_id : $record->user_id;

        return $value ? (int) $value : null;
    }

    private function ownershipIssue(string $roleName, string $permission): ?string
    {
        $cacheKey = $permission.'|'.$roleName;
        if (array_key_exists($cacheKey, $this->ownershipIssueCache)) {
            return $this->ownershipIssueCache[$cacheKey];
        }
        if ($roleName === '') {
            return $this->ownershipIssueCache[$cacheKey] = 'no_action_owner';
        }

        $role = Role::query()->with('permissions')->where('name', $roleName)->first();
        if (! $role) {
            return $this->ownershipIssueCache[$cacheKey] = 'role_missing';
        }
        if ($roleName !== 'System Administrator' && ! $role->permissions->contains('name', $permission)) {
            return $this->ownershipIssueCache[$cacheKey] = 'permission_missing';
        }
        if (! $this->hasActiveAssignee((int) $role->id)) {
            return $this->ownershipIssueCache[$cacheKey] = 'no_active_assignee';
        }

        $this->ownershipIssueCache[$cacheKey] = null;

        return null;
    }

    private function hasActiveAssignee(int $roleId): bool
    {
        $today = now()->toDateString();
        $hasAssignment = UserRoleAssignment::query()
            ->where('role_id', $roleId)
            ->where(fn ($query) => $query->whereNull('start_date')->orWhereDate('start_date', '<=', $today))
            ->where(fn ($query) => $query->whereNull('end_date')->orWhereDate('end_date', '>=', $today))
            ->whereHas('user', fn ($query) => $query->whereNull('deleted_at')
                ->where(fn ($userQuery) => $userQuery->whereNull('status')->orWhereRaw("LOWER(TRIM(status)) = 'active'")))
            ->exists();
        if ($hasAssignment) {
            return true;
        }

        return DB::table('model_has_roles')
            ->join('users', 'users.id', '=', 'model_has_roles.model_id')
            ->where('model_has_roles.role_id', $roleId)
            ->where('model_has_roles.model_type', 'App\\Models\\User')
            ->whereNull('users.deleted_at')
            ->where(fn ($query) => $query->whereNull('users.status')->orWhereRaw("LOWER(TRIM(users.status)) = 'active'"))
            ->exists();
    }
}
