<?php

namespace App\Console\Commands;

use App\Support\E2eEnvironmentGuard;
use App\Support\E2eRunLock;
use Database\Seeders\E2eScenarioSeeder;
use Illuminate\Console\Command;

class ResetE2eEnvironment extends Command
{
    protected $signature = 'e2e:reset
        {--seed-only : Keep the current schema and idempotently refresh E2E fixtures}';

    protected $description = 'Safely reset and seed the isolated VMECC E2E database';

    public function handle(): int
    {
        E2eEnvironmentGuard::assertCurrentEnvironmentIsSafe();
        E2eRunLock::fromConfig()->assertOwned();

        if (! $this->option('seed-only')) {
            $exitCode = $this->call('migrate:fresh', ['--force' => true]);
            if ($exitCode !== self::SUCCESS) {
                return $exitCode;
            }
        }

        $exitCode = $this->call('db:seed', [
            '--class' => E2eScenarioSeeder::class,
            '--force' => true,
        ]);

        if ($exitCode !== self::SUCCESS) {
            return $exitCode;
        }

        $this->info('Isolated E2E database is ready.');

        return self::SUCCESS;
    }
}
