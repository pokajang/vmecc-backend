<?php

namespace App\Console\Commands;

use App\Support\E2eEnvironmentGuard;
use App\Support\E2eRunLock;
use Illuminate\Console\Command;
use RuntimeException;

class ReleaseE2eRunLock extends Command
{
    protected $signature = 'e2e:unlock';

    protected $description = 'Request graceful release of the current E2E run lock';

    public function handle(): int
    {
        E2eEnvironmentGuard::assertCurrentEnvironmentIsSafe();

        $lock = E2eRunLock::fromConfig();
        $lock->requestStop();

        $deadline = microtime(true) + 15;
        while (! $lock->isReleased() && microtime(true) < $deadline) {
            usleep(200_000);
        }

        if (! $lock->isReleased()) {
            throw new RuntimeException('The E2E lock holder did not stop within 15 seconds.');
        }

        $this->info('Exclusive E2E run lock released.');

        return self::SUCCESS;
    }
}
