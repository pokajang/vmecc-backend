<?php

namespace App\Console\Commands;

use App\Models\InspectionCheckRow;
use App\Models\InspectionFireExtinguisher;
use App\Services\InspectionFireExtinguishers\FireExtinguisherIssueSyncService;
use Illuminate\Console\Command;

class BackfillFireExtinguisherIssues extends Command
{
    protected $signature = 'inspection:backfill-fire-extinguisher-issues
        {--dry-run : Report changes without writing}
        {--extinguisher-id= : Process one extinguisher}
        {--from= : Process extinguisher IDs at or above this value}
        {--chunk=100 : Number of assets per chunk}';

    protected $description = 'Create open managed issues from each extinguisher latest submitted defective criteria';

    public function handle(FireExtinguisherIssueSyncService $sync): int
    {
        $query = InspectionFireExtinguisher::query()
            ->where('is_active', true)
            ->whereIn('lifecycle_status', ['active', 'out_of_service'])
            ->orderBy('id');
        if ($id = (int) $this->option('extinguisher-id')) {
            $query->whereKey($id);
        }
        if ($from = (int) $this->option('from')) {
            $query->where('id', '>=', $from);
        }
        $dryRun = (bool) $this->option('dry-run');
        $scanned = $defects = $processed = 0;

        $query->chunkById(max(1, (int) $this->option('chunk')), function ($assets) use ($sync, $dryRun, &$scanned, &$defects, &$processed): void {
            foreach ($assets as $asset) {
                $scanned++;
                $latest = InspectionCheckRow::query()
                    ->where('inspection_type_key', 'fire-extinguisher-inspection')
                    ->where('equipment_catalog_id', $asset->id)
                    ->whereNotNull('submitted_at')
                    ->orderByDesc('submitted_at')->orderByDesc('id')->first();
                if (! $latest) {
                    continue;
                }
                $rows = InspectionCheckRow::query()
                    ->where('equipment_catalog_id', $asset->id)
                    ->where('report_id', $latest->report_id)
                    ->where('source_row_id', $latest->source_row_id)
                    ->get();
                $defects += $rows->where('has_defect', true)->count();
                if (! $dryRun) {
                    $sync->syncRows($rows, null);
                }
                $processed++;
            }
        });

        $this->table(['Assets scanned', 'Latest inspections', 'Latest defects', 'Mode'], [[
            $scanned, $processed, $defects, $dryRun ? 'dry-run' : 'written',
        ]]);

        return self::SUCCESS;
    }
}
