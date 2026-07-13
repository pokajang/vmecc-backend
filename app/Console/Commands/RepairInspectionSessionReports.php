<?php

namespace App\Console\Commands;

use App\Models\Report;
use App\Services\InspectionSessionReportPayloadBuilder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class RepairInspectionSessionReports extends Command
{
    protected $signature = 'inspection:repair-session-reports
        {--dry-run : Report affected rows without changing them}
        {--batch=100 : Number of reports to process per batch (1-500)}';

    protected $description = 'Repair derived location and evidence metadata on fire-extinguisher session reports.';

    public function handle(InspectionSessionReportPayloadBuilder $payloadBuilder): int
    {
        $batchSize = (int) $this->option('batch');
        if ($batchSize < 1 || $batchSize > 500) {
            $this->error('--batch must be between 1 and 500.');

            return self::INVALID;
        }

        $dryRun = (bool) $this->option('dry-run');
        $counts = ['scanned' => 0, 'eligible' => 0, 'unchanged' => 0, 'would_repair' => 0, 'repaired' => 0, 'rejected' => 0];

        Report::query()
            ->where('report_type', 'inspection')
            ->orderBy('id')
            ->chunkById($batchSize, function ($reports) use ($payloadBuilder, $dryRun, &$counts): void {
                foreach ($reports as $report) {
                    $counts['scanned']++;
                    $payload = is_array($report->payload) ? $report->payload : [];
                    if (! $payloadBuilder->isSessionFireExtinguisherPayload($payload)) {
                        continue;
                    }
                    $counts['eligible']++;
                    try {
                        $nextPayload = $payloadBuilder->normalizeDerivedFields($payload);
                        if ($nextPayload === $payload) {
                            $counts['unchanged']++;
                        } elseif ($dryRun) {
                            $counts['would_repair']++;
                        } else {
                            DB::transaction(function () use ($report, $payloadBuilder): void {
                                $locked = Report::query()->lockForUpdate()->findOrFail($report->id);
                                $payload = is_array($locked->payload) ? $locked->payload : [];
                                if (! $payloadBuilder->isSessionFireExtinguisherPayload($payload)) {
                                    return;
                                }
                                $locked->payload = $payloadBuilder->normalizeDerivedFields($payload);
                                $locked->save();
                            });
                            $counts['repaired']++;
                        }
                    } catch (Throwable $exception) {
                        $counts['rejected']++;
                        $this->warn(sprintf('Rejected report %s: %s', $report->report_uid, $exception->getMessage()));
                    }
                }
            });

        $this->table(
            ['Mode', 'Scanned', 'Eligible', 'Unchanged', 'Would repair', 'Repaired', 'Rejected'],
            [[
                $dryRun ? 'dry-run' : 'apply',
                $counts['scanned'],
                $counts['eligible'],
                $counts['unchanged'],
                $counts['would_repair'],
                $counts['repaired'],
                $counts['rejected'],
            ]],
        );

        return $counts['rejected'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
