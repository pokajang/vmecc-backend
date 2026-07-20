<?php

namespace App\Console\Commands;

use App\Support\E2ePreflight;
use Illuminate\Console\Command;

class CheckE2ePreflight extends Command
{
    protected $signature = 'e2e:preflight';

    protected $description = 'Verify the resolved E2E database, lock, origins, integrations, and paths';

    public function handle(E2ePreflight $preflight): int
    {
        $summary = $preflight->assertReady();
        $this->table(
            ['Check', 'Resolved value'],
            collect($summary)->map(fn ($value, $key) => [$key, is_bool($value) ? ($value ? 'true' : 'false') : $value]),
        );
        $this->info('E2E preflight passed.');

        return self::SUCCESS;
    }
}
