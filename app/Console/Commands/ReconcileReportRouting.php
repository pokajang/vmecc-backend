<?php

namespace App\Console\Commands;

use App\Services\ReportRoutingReconciliationService;
use Illuminate\Console\Command;

class ReconcileReportRouting extends Command
{
    protected $signature = 'workflow:reconcile-report-routing {--json}';

    protected $description = 'Reassign open report actions after role or duty coverage changes';

    public function handle(ReportRoutingReconciliationService $service): int
    {
        $result = $service->reconcile();

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info(
                "Checked {$result['checked']} open reports; reassigned {$result['reassigned']}; "
                ."unassigned {$result['unassigned']}.",
            );
        }

        return self::SUCCESS;
    }
}
