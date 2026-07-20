<?php

namespace Database\Seeders;

use App\Models\LeaveAssignment;
use App\Models\Setting;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\UserRoleAssignment;
use App\Services\RoleCatalog;
use App\Support\E2eEnvironmentGuard;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class E2eScenarioSeeder extends Seeder
{
    public const PASSWORD = SmokeRbacUsersSeeder::PASSWORD;

    public const PERSONAS = [
        'hr_secondary' => [
            'role' => 'Human Resource',
            'email' => 'codex.e2e.human-resource-secondary@vmecc.local',
            'name' => 'Codex E2E Human Resource Secondary',
            'scope' => RoleCatalog::OFFICE,
            'team' => null,
        ],
        'hr_tertiary' => [
            'role' => 'Human Resource',
            'email' => 'codex.e2e.human-resource-tertiary@vmecc.local',
            'name' => 'Codex E2E Human Resource Tertiary',
            'scope' => RoleCatalog::OFFICE,
            'team' => null,
        ],
        'aic_beta' => [
            'role' => 'Assistant Incident Commander',
            'email' => 'codex.e2e.assistant-incident-commander-beta@vmecc.local',
            'name' => 'Codex E2E Assistant Incident Commander Beta',
            'scope' => RoleCatalog::SITE,
            'team' => 'Smoke Site Beta',
        ],
        'trt_beta' => [
            'role' => 'Tactical Response Team',
            'email' => 'codex.e2e.tactical-response-team-beta@vmecc.local',
            'name' => 'Codex E2E Tactical Response Team Beta',
            'scope' => RoleCatalog::SITE,
            'team' => 'Smoke Site Beta',
        ],
        'client_cm_alpha' => [
            'role' => 'Client Contract Manager',
            'email' => 'codex.e2e.client-contract-manager-alpha@vmecc.local',
            'name' => 'Codex E2E Client Contract Manager Alpha',
            'scope' => RoleCatalog::CLIENT_SITE,
            'team' => 'Smoke Site Alpha',
        ],
    ];

    public function run(): void
    {
        E2eEnvironmentGuard::assertCurrentEnvironmentIsSafe();

        $this->call([
            RolesAndPermissionsSeeder::class,
            SmokeRbacUsersSeeder::class,
            SmokeScenarioSeeder::class,
        ]);

        $teams = Team::query()
            ->whereIn('name', collect(self::PERSONAS)->pluck('team')->filter()->unique()->all())
            ->get()
            ->keyBy('name');

        foreach (self::PERSONAS as $persona) {
            $this->seedPersona($persona, $teams->get($persona['team']));
        }

        $this->seedFutureLeaveEntitlement();
        $this->seedWorkflowSettings();
    }

    private function seedFutureLeaveEntitlement(): void
    {
        $trt = User::query()
            ->where('email', 'codex.smoke.tactical-response-team@vmecc.local')
            ->firstOrFail();

        LeaveAssignment::query()->updateOrCreate(
            [
                'user_id' => $trt->id,
                'year' => now()->addYear()->year,
                'leave_type' => 'Annual Leave',
            ],
            [
                'entitlement' => 30,
                'used' => 0,
                'pending' => 0,
            ],
        );
    }

    private function seedPersona(array $persona, ?Team $team): void
    {
        $role = Role::query()
            ->where('name', $persona['role'])
            ->where('guard_name', 'web')
            ->firstOrFail();

        $user = User::withTrashed()->updateOrCreate(
            ['email' => $persona['email']],
            [
                'name' => $persona['name'],
                'password' => Hash::make(self::PASSWORD),
                'email_verified_at' => now(),
                'status' => 'Active',
                'failed_login_count' => 0,
                'locked_at' => null,
                'locked_by' => null,
                'lock_reason' => null,
                'state' => 'Selangor',
                'phone' => '60000000000',
                'team' => $team?->name,
            ],
        );

        if ($user->trashed()) {
            $user->restore();
        }

        $user->syncRoles([$persona['role']]);

        UserRoleAssignment::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'role_id' => $role->id,
                'scope_type' => $persona['scope'],
                'team_id' => $team?->id,
            ],
            [
                'start_date' => now()->subDay()->toDateString(),
                'end_date' => null,
                'is_primary' => true,
            ],
        );

        if (! $team) {
            return;
        }

        TeamMember::query()->updateOrCreate(
            [
                'team_id' => $team->id,
                'user_id' => $user->id,
            ],
            [
                'name' => $user->name,
                'role' => $persona['role'],
                'is_primary' => true,
                'started_at' => now()->subDay()->toDateString(),
                'ended_at' => null,
            ],
        );
    }

    private function seedWorkflowSettings(): void
    {
        Setting::query()->updateOrCreate(
            ['key' => 'leave_approval_rules'],
            ['value' => [
                'rules' => [],
                'fallback' => [
                    'reviewRole' => 'Human Resource',
                    'recommendRole' => 'Human Resource',
                    'approveRole' => 'Human Resource',
                ],
                'options' => [
                    'requireRecommendation' => true,
                    'enforceDistinctApprovers' => false,
                ],
            ]],
        );

        Setting::query()->updateOrCreate(
            ['key' => 'overtime_approval_rules'],
            ['value' => [
                'workflow' => [
                    'rules' => [],
                    'fallback' => [
                        'reviewRole' => 'Contract Manager',
                        'recommendRole' => 'Human Resource',
                        'approveRole' => 'Client Contract Manager',
                    ],
                    'options' => [
                        'requireRecommendation' => true,
                        'enforceDistinctApprovers' => true,
                    ],
                ],
            ]],
        );

        Setting::query()->updateOrCreate(
            ['key' => 'salary_workflow_rules'],
            ['value' => [
                'rules' => [],
                'fallback' => [
                    'checkRole' => 'Admin',
                    'reviewRole' => 'Finance',
                    'approveRole' => 'Contract Manager',
                ],
            ]],
        );
    }
}
