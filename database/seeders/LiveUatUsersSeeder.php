<?php

namespace Database\Seeders;

use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\UserRoleAssignment;
use App\Models\UserSession;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Spatie\Permission\Models\Role;

class LiveUatUsersSeeder extends Seeder
{
    public function run(): void
    {
        $personas = $this->validatedPersonas();
        $team = $this->resolveSiteTeam($personas);

        DB::transaction(function () use ($personas, $team): void {
            foreach ($personas as $persona) {
                $this->seedPersona($persona, $team);
            }
        });

        $this->command?->info('Live UAT users reconciled. Plaintext passwords remain outside Git.');
        $this->command?->table(
            ['Persona', 'Email'],
            collect($personas)
                ->map(fn (array $persona) => [$persona['key'], $persona['email']])
                ->all(),
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function validatedPersonas(): array
    {
        $configured = config('live_uat.personas', []);
        if (! is_array($configured) || count($configured) !== 6) {
            throw new RuntimeException('The live UAT persona configuration must contain exactly six roles.');
        }

        $personas = [];
        $emails = [];
        foreach ($configured as $key => $persona) {
            $email = strtolower(trim((string) ($persona['email'] ?? '')));
            $name = trim((string) ($persona['name'] ?? ''));
            $roleName = trim((string) ($persona['role'] ?? ''));
            $passwordHash = trim((string) ($persona['password_hash'] ?? ''));
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException("{$key}: configure a valid live UAT email address.");
            }
            if (! str_starts_with($name, '[Live UAT] ')) {
                throw new RuntimeException("{$key}: the protected account marker is missing.");
            }
            if ((password_get_info($passwordHash)['algoName'] ?? 'unknown') !== 'bcrypt') {
                throw new RuntimeException("{$key}: a valid bcrypt password hash is required.");
            }
            if (in_array($email, $emails, true)) {
                throw new RuntimeException("Duplicate live UAT email configured: {$email}");
            }
            if (! Role::query()->where('name', $roleName)->where('guard_name', 'web')->exists()) {
                throw new RuntimeException("{$key}: required role does not exist: {$roleName}");
            }

            $existing = User::withTrashed()->where('email', $email)->first();
            if ($existing && $existing->name !== $name) {
                throw new RuntimeException(
                    "Refusing to overwrite non-UAT user with configured email: {$email}"
                );
            }

            $emails[] = $email;
            $personas[] = [...$persona, 'key' => $key, 'email' => $email];
        }

        return $personas;
    }

    /**
     * @param  array<int, array<string, mixed>>  $personas
     */
    private function resolveSiteTeam(array $personas): ?Team
    {
        if (! collect($personas)->contains(fn (array $persona) => (bool) $persona['requires_team'])) {
            return null;
        }

        $teamName = trim((string) config('live_uat.site_team_name'));
        $team = Team::query()->where('name', $teamName)->first();
        $group = strtolower(trim((string) $team?->group));
        $status = strtolower(trim((string) $team?->status));
        $operationalStatus = strtolower(trim((string) config('team.default_status', 'On Duty')));
        if (! $team || ! in_array($group, ['', 'site'], true) || $status !== $operationalStatus) {
            throw new RuntimeException(
                'The configured live UAT team must identify an on-duty site team (legacy null group or site group).'
            );
        }

        return $team;
    }

    /**
     * @param  array<string, mixed>  $persona
     */
    private function seedPersona(array $persona, ?Team $siteTeam): void
    {
        $user = User::withTrashed()->where('email', $persona['email'])->first();
        if (! $user) {
            $user = new User(['email' => $persona['email']]);
        } elseif ($user->trashed()) {
            $user->restore();
        }

        if ($user->exists) {
            $user->tokens()->delete();
            UserSession::query()->where('user_id', $user->id)->delete();
        }

        $user->forceFill([
            'name' => $persona['name'],
            'email_verified_at' => now(),
            'status' => 'Active',
            'failed_login_count' => 0,
            'locked_at' => null,
            'locked_by' => null,
            'lock_reason' => null,
            'remember_token' => null,
        ]);

        // The configured value is an already-validated bcrypt hash. Assign it as
        // a raw attribute so Laravel's hashed cast does not reject a production
        // cost-12 hash when the test environment deliberately uses fewer rounds.
        $user->setRawAttributes([
            ...$user->getAttributes(),
            'password' => $persona['password_hash'],
        ]);
        $user->save();

        $role = Role::query()
            ->where('name', $persona['role'])
            ->where('guard_name', 'web')
            ->firstOrFail();
        $user->syncRoles([$role]);
        UserRoleAssignment::query()->where('user_id', $user->id)->delete();
        UserRoleAssignment::query()->create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'scope_type' => $persona['scope'],
            'team_id' => $persona['requires_team'] ? $siteTeam?->id : null,
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => null,
            'is_primary' => true,
        ]);

        TeamMember::query()->where('user_id', $user->id)->delete();
        if ($persona['requires_team']) {
            TeamMember::query()->create([
                'team_id' => $siteTeam->id,
                'user_id' => $user->id,
                'name' => $user->name,
                'role' => $persona['role'],
                'is_primary' => true,
                'started_at' => now()->subDay()->toDateString(),
                'ended_at' => null,
            ]);
        }
    }
}
