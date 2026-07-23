<?php

namespace App\Console\Commands;

use App\Services\FitnessShadowReadCutoverService;
use Illuminate\Console\Command;

class FitnessShadowReadReadiness extends Command
{
    protected $signature = 'reports:fitness-shadow-read-readiness';

    protected $description = 'Reports whether Fitness relational reads can be enabled safely.';

    public function handle(FitnessShadowReadCutoverService $cutover): int
    {
        $summary = $cutover->summary();
        $this->table(['Projected reports', 'Blocking reports', 'Ready'], [[
            $summary['projectedReports'],
            $summary['blockingReports'],
            $summary['ready'] ? 'yes' : 'no',
        ]]);

        return $summary['ready'] ? self::SUCCESS : self::FAILURE;
    }
}
