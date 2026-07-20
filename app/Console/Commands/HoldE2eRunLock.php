<?php

namespace App\Console\Commands;

use App\Support\E2eEnvironmentGuard;
use App\Support\E2eRunLock;
use Illuminate\Console\Command;

class HoldE2eRunLock extends Command
{
    protected $signature = 'e2e:lock {--heartbeat=2 : Heartbeat interval in seconds}';

    protected $description = 'Hold the exclusive E2E database lock until a graceful stop is requested';

    public function handle(): int
    {
        E2eEnvironmentGuard::assertCurrentEnvironmentIsSafe();

        $interval = max(1, min(10, (int) $this->option('heartbeat')));
        $lock = E2eRunLock::fromConfig();
        $handle = $lock->acquire();

        $this->info('Exclusive E2E run lock acquired.');

        try {
            while (! $lock->stopRequested()) {
                $lock->heartbeat($handle);
                sleep($interval);
            }
        } finally {
            $lock->release($handle);
        }

        $this->info('Exclusive E2E run lock released.');

        return self::SUCCESS;
    }
}
