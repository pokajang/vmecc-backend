<?php

namespace App\Console\Commands;

use App\Models\Team;
use App\Models\User;
use App\Support\E2eEnvironmentGuard;
use App\Support\E2eRunLock;
use Database\Seeders\E2eScenarioSeeder;
use Database\Seeders\SmokeRbacUsersSeeder;
use Illuminate\Console\Command;
use RuntimeException;

class VerifyE2eFixtures extends Command
{
    protected $signature = 'e2e:verify-fixtures';

    protected $description = 'Verify deterministic E2E personas, recovery administrators, and team topology';

    public function handle(): int
    {
        E2eEnvironmentGuard::assertCurrentEnvironmentIsSafe();
        E2eRunLock::fromConfig()->assertOwned();

        $expectedEmails = collect(SmokeRbacUsersSeeder::PERSONAS)
            ->pluck('email')
            ->merge(collect(E2eScenarioSeeder::PERSONAS)->pluck('email'))
            ->values();
        $activePersonaCount = User::query()
            ->whereIn('email', $expectedEmails)
            ->where('status', 'Active')
            ->whereNull('locked_at')
            ->count();
        $activeSystemAdministrators = User::role('System Administrator')
            ->where('status', 'Active')
            ->whereNull('locked_at')
            ->count();
        $breakGlassReady = User::query()
            ->where('email', E2eScenarioSeeder::PERSONAS['break_glass_admin']['email'])
            ->where('status', 'Active')
            ->whereNull('locked_at')
            ->exists();
        $lockedFixtureReady = User::query()
            ->where('email', E2eScenarioSeeder::LOCKED_PERSONA_EMAIL)
            ->whereNotNull('locked_at')
            ->exists();
        $teamCount = Team::query()->whereIn('name', [
            'Smoke Site Alpha',
            'Smoke Site Beta',
            'Smoke Client Alpha',
            'Smoke Client Beta',
        ])->count();

        if ($activePersonaCount !== $expectedEmails->count()
            || $activeSystemAdministrators < 2
            || ! $breakGlassReady
            || ! $lockedFixtureReady
            || $teamCount !== 4) {
            throw new RuntimeException('The deterministic E2E persona or topology fixture is incomplete.');
        }

        $this->table(['Check', 'Value'], [
            ['active personas', $activePersonaCount],
            ['active system administrators', $activeSystemAdministrators],
            ['break-glass administrator', 'ready'],
            ['locked user', 'ready'],
            ['site/client teams', $teamCount],
        ]);
        $this->info('E2E fixtures verified.');

        return self::SUCCESS;
    }
}
