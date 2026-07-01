<?php

namespace App\Console\Commands;

use App\Models\Report;
use App\Services\InspectionCheckRowSyncService;
use Illuminate\Console\Command;

class SyncInspectionCheckRows extends Command
{
    protected $signature = 'inspection:sync-check-rows {--report-uid= : Sync one report UID only}';

    protected $description = 'Rebuild normalized inspection analytics check rows from stored inspection report payloads.';

    public function handle(InspectionCheckRowSyncService $syncService): int
    {
        $reportUid = trim((string) $this->option('report-uid'));
        $reportsSynced = 0;
        $rowsSynced = 0;

        $query = Report::query()
            ->where('report_type', 'inspection')
            ->orderBy('id');

        if ($reportUid !== '') {
            $query->where('report_uid', $reportUid);
        }

        $query->chunkById(100, function ($reports) use ($syncService, &$reportsSynced, &$rowsSynced) {
            foreach ($reports as $report) {
                $rowsSynced += $syncService->syncForReport($report);
                $reportsSynced++;
            }
        });

        $this->info("Synced {$rowsSynced} inspection check row(s) from {$reportsSynced} inspection report(s).");

        return self::SUCCESS;
    }
}
