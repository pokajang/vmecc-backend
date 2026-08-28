<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class CheckErAssessmentReadiness extends Command
{
    private const MIGRATION = '2026_08_27_000001_seed_er_assessment_report_permission';

    private const PERMISSION = 'reports.er_assessment.view';

    private const EXPECTED_ROLES = [
        'System Administrator',
        'Contract Manager',
        'Incident Commander',
        'Assistant Incident Commander',
        'Tactical Response Team',
    ];

    protected $signature = 'reports:er-assessment-readiness
        {--allow-custom-managed-role=* : Approved custom reports.manage role names}';

    protected $description = 'Read-only deployment preflight for ER Assessment permission policy.';

    public function handle(): int
    {
        $permission = Permission::query()
            ->where('name', self::PERMISSION)
            ->where('guard_name', 'web')
            ->first();
        $migrationRan = Schema::hasTable('migrations')
            && DB::table('migrations')->where('migration', self::MIGRATION)->exists();
        $roleResults = collect(self::EXPECTED_ROLES)->map(function (string $name) use ($permission): array {
            $role = Role::query()->where('guard_name', 'web')->where('name', $name)->first();

            return [
                'role' => $name,
                'exists' => $role !== null,
                'assigned' => $role !== null && $permission !== null && $role->hasPermissionTo($permission),
            ];
        });
        $humanResource = Role::query()
            ->where('guard_name', 'web')
            ->where('name', 'Human Resource')
            ->first();
        $humanResourceDenied = $humanResource !== null
            && ($permission === null || ! $humanResource->hasPermissionTo($permission));

        $approvedCustomRoles = collect((array) $this->option('allow-custom-managed-role'))
            ->map(fn (mixed $name): string => trim((string) $name))
            ->filter()
            ->all();
        $customManagedRoles = Role::query()
            ->where('guard_name', 'web')
            ->whereNotIn('name', self::EXPECTED_ROLES)
            ->whereHas('permissions', fn ($query) => $query->where('name', 'reports.manage'))
            ->pluck('name')
            ->reject(fn (string $name): bool => in_array($name, $approvedCustomRoles, true))
            ->values();

        $checks = collect([
            ['check' => 'Migration completed', 'ok' => $migrationRan, 'detail' => self::MIGRATION],
            ['check' => 'Permission exists', 'ok' => $permission !== null, 'detail' => self::PERMISSION.' (web)'],
            ...$roleResults->map(fn (array $result): array => [
                'check' => 'Allowed role: '.$result['role'],
                'ok' => $result['exists'] && $result['assigned'],
                'detail' => $result['exists'] ? ($result['assigned'] ? 'assigned' : 'missing permission') : 'role missing',
            ])->all(),
            ['check' => 'Denied role: Human Resource', 'ok' => $humanResourceDenied, 'detail' => $humanResource === null ? 'role missing' : ($humanResourceDenied ? 'not assigned' : 'unexpectedly assigned')],
            [
                'check' => 'Custom reports.manage roles reviewed',
                'ok' => $customManagedRoles->isEmpty(),
                'detail' => $customManagedRoles->isEmpty()
                    ? 'none unapproved'
                    : 'review or allow-list: '.$customManagedRoles->implode(', '),
            ],
        ]);

        $this->table(['Check', 'Result', 'Detail'], $checks->map(fn (array $check): array => [
            $check['check'],
            $check['ok'] ? 'PASS' : 'FAIL',
            $check['detail'],
        ])->all());

        if ($checks->contains(fn (array $check): bool => ! $check['ok'])) {
            $this->error('ER Assessment readiness failed. No permissions were changed.');

            return self::FAILURE;
        }

        $this->info('ER Assessment permission readiness passed.');

        return self::SUCCESS;
    }
}
