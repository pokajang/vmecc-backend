<?php

namespace Database\Seeders;

use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\UserRoleAssignment;
use App\Services\RoleCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class SmokeRbacUsersSeeder extends Seeder
{
    public const PASSWORD = 'SmokeRole!2026';

    public const PERSONAS = [
        'System Administrator' => [
            'email' => 'codex.smoke.sysadmin@vmecc.local',
            'name' => 'Codex Smoke System Administrator',
            'scope' => RoleCatalog::GLOBAL,
            'team' => null,
        ],
        'Contract Manager' => [
            'email' => 'codex.smoke.contract-manager@vmecc.local',
            'name' => 'Codex Smoke Contract Manager',
            'scope' => RoleCatalog::OFFICE,
            'team' => null,
        ],
        'Human Resource' => [
            'email' => 'codex.smoke.human-resource@vmecc.local',
            'name' => 'Codex Smoke Human Resource',
            'scope' => RoleCatalog::OFFICE,
            'team' => null,
        ],
        'Finance' => [
            'email' => 'codex.smoke.finance@vmecc.local',
            'name' => 'Codex Smoke Finance',
            'scope' => RoleCatalog::OFFICE,
            'team' => null,
        ],
        'Admin' => [
            'email' => 'codex.smoke.admin-role@vmecc.local',
            'name' => 'Codex Smoke Admin Role',
            'scope' => RoleCatalog::OFFICE,
            'team' => null,
        ],
        'Incident Commander' => [
            'email' => 'codex.smoke.incident-commander@vmecc.local',
            'name' => 'Codex Smoke Incident Commander',
            'scope' => RoleCatalog::SITE,
            'team' => 'Smoke Site Alpha',
        ],
        'Assistant Incident Commander' => [
            'email' => 'codex.smoke.assistant-incident-commander@vmecc.local',
            'name' => 'Codex Smoke Assistant Incident Commander',
            'scope' => RoleCatalog::SITE,
            'team' => 'Smoke Site Alpha',
        ],
        'Tactical Response Team' => [
            'email' => 'codex.smoke.tactical-response-team@vmecc.local',
            'name' => 'Codex Smoke Tactical Response Team',
            'scope' => RoleCatalog::SITE,
            'team' => 'Smoke Site Alpha',
        ],
        'Client Contract Manager' => [
            'email' => 'codex.smoke.client-contract-manager@vmecc.local',
            'name' => 'Codex Smoke Client Contract Manager',
            'scope' => RoleCatalog::CLIENT_SITE,
            'team' => 'Smoke Client Alpha',
        ],
        'Representative' => [
            'email' => 'codex.smoke.representative@vmecc.local',
            'name' => 'Codex Smoke Representative',
            'scope' => RoleCatalog::CLIENT_SITE,
            'team' => 'Smoke Client Alpha',
        ],
    ];

    public function run(): void
    {
        $teams = $this->seedTeams();

        foreach (self::PERSONAS as $roleName => $persona) {
            $role = Role::query()
                ->where('name', $roleName)
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
                ],
            );

            if ($user->trashed()) {
                $user->restore();
            }

            $team = $persona['team'] ? ($teams[$persona['team']] ?? null) : null;
            $user->syncRoles([$roleName]);

            UserRoleAssignment::updateOrCreate(
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

            if ($team) {
                TeamMember::updateOrCreate(
                    [
                        'team_id' => $team->id,
                        'user_id' => $user->id,
                    ],
                    [
                        'name' => $user->name,
                        'role' => $roleName,
                        'is_primary' => true,
                        'started_at' => now()->subDay()->toDateString(),
                        'ended_at' => null,
                    ],
                );
            }
        }
    }

    private function seedTeams(): array
    {
        $rows = [
            'Smoke Site Alpha' => [
                'group' => 'site',
                'lead_name' => 'Codex Smoke Incident Commander',
            ],
            'Smoke Site Beta' => [
                'group' => 'site',
                'lead_name' => 'Codex Smoke Out Of Scope Lead',
            ],
            'Smoke Client Alpha' => [
                'group' => 'client_site',
                'lead_name' => 'Codex Smoke Client Contract Manager',
            ],
            'Smoke Client Beta' => [
                'group' => 'client_site',
                'lead_name' => 'Codex Smoke Out Of Scope Client Lead',
            ],
        ];

        $teams = [];
        foreach ($rows as $name => $attributes) {
            $teams[$name] = Team::updateOrCreate(
                ['name' => $name],
                [
                    'group' => $attributes['group'],
                    'status' => 'Active',
                    'lead_name' => $attributes['lead_name'],
                    'lead_id' => null,
                    'image_url' => null,
                ],
            );
        }

        return $teams;
    }
}
