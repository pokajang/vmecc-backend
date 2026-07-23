<?php

namespace App\Console\Commands;

use App\Models\Report;
use App\Services\FitnessShadowReconciliationService;
use Illuminate\Console\Command;
use Throwable;

class ReconcileFitnessShadowReads extends Command
{
    protected $signature = 'report:reconcile-fitness-shadow-reads
        {--report-uid= : Reconcile a single fitness report by report UID}
        {--from-id= : Reconcile fitness reports with IDs greater than or equal to this value}
        {--to-id= : Reconcile fitness reports with IDs less than or equal to this value}
        {--max=0 : Maximum number of fitness reports to reconcile (0 = unlimited)}
        {--chunk=200 : Number of reports to process per chunk (1-1000)}';

    protected $description = 'Reconcile fitness shadow reads and record projection/legacy mismatches.';

    public function handle(FitnessShadowReconciliationService $service): int
    {
        $reportUid = trim((string) $this->option('report-uid'));
        $fromId = (int) $this->option('from-id');
        $toId = (int) $this->option('to-id');
        $chunk = (int) $this->option('chunk');
        $max = (int) $this->option('max');

        if ($chunk < 1 || $chunk > 1000) {
            $this->error('Use --chunk in range 1-1000.');

            return self::INVALID;
        }
        if ($max < 0) {
            $this->error('--max must be 0 or greater.');

            return self::INVALID;
        }

        $query = Report::query()
            ->where('report_type', 'fitness-test')
            ->orderBy('id');
        if ($reportUid !== '') {
            $query->where('report_uid', $reportUid);
        } else {
            if ($fromId > 0) {
                $query->where('id', '>=', $fromId);
            }
            if ($toId > 0) {
                $query->where('id', '<=', $toId);
            }
        }

        $counts = [
            'scanned' => 0,
            'matched' => 0,
            'mismatched' => 0,
            'missing_projection' => 0,
            'errors' => 0,
        ];
        $mismatchTypes = [];
        $stop = $max > 0 ? $max : PHP_INT_MAX;

        if ($reportUid !== '') {
            $report = $query->first();
            if (! $report) {
                $this->error("No fitness report found for UID: {$reportUid}");

                return self::INVALID;
            }

            $reports = collect([$report]);
            $counts = $this->reconcileReportBatch($reports, $service, $counts, $mismatchTypes);
        } else {
            $query->chunkById($chunk, function ($reports) use ($service, &$counts, &$mismatchTypes, &$stop): bool {
                foreach ($reports as $report) {
                    if ($stop <= 0) {
                        return false;
                    }
                    $stop--;
                    $counts = $this->reconcileReportBatch(collect([$report]), $service, $counts, $mismatchTypes);
                }

                return true;
            });
        }

        $this->table(
            ['Scanned', 'Matched', 'Mismatched', 'Missing projection', 'Errors'],
            [[
                $counts['scanned'],
                $counts['matched'],
                $counts['mismatched'],
                $counts['missing_projection'],
                $counts['errors'],
            ]],
        );

        if (! empty($mismatchTypes)) {
            ksort($mismatchTypes);
            $rows = [];
            foreach ($mismatchTypes as $type => $typeCount) {
                $rows[] = [$type, $typeCount];
            }
            $this->table(['Mismatch type', 'Count'], $rows);
        } else {
            $this->info('No mismatch categories recorded in this run.');
        }

        if ($counts['scanned'] === 0) {
            $this->info('No fitness reports selected for reconciliation.');
        } else {
            $rate = ($counts['mismatched'] / max(1, $counts['scanned'])) * 100;
            $this->info(sprintf('Mismatch rate: %.2f%%', $rate));
            if ($counts['mismatched'] === 0) {
                $this->info('Mismatch rate is zero. Legacy reads can be switched to relational views.');
            }
        }

        return $counts['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function reconcileReportBatch(
        iterable $reports,
        FitnessShadowReconciliationService $service,
        array $counts,
        array &$mismatchTypes,
    ): array {
        foreach ($reports as $report) {
            $counts['scanned']++;
            try {
                $result = $service->reconcile($report);
                if ($result->status === 'matched') {
                    $counts['matched']++;
                } elseif ($result->status === 'missing_projection') {
                    $counts['mismatched']++;
                    $counts['missing_projection']++;
                    $types = is_array($result->mismatch_types) ? $result->mismatch_types : [];
                    foreach ($types as $type) {
                        $mismatchTypes[$type] = ($mismatchTypes[$type] ?? 0) + 1;
                    }
                } else {
                    $counts['mismatched']++;
                    $types = is_array($result->mismatch_types) ? $result->mismatch_types : [];
                    foreach ($types as $type) {
                        $mismatchTypes[$type] = ($mismatchTypes[$type] ?? 0) + 1;
                    }
                }
            } catch (Throwable $exception) {
                $counts['errors']++;
                $this->error(sprintf(
                    'Reconciliation failed for report %s: %s',
                    (string) $report->report_uid,
                    $exception->getMessage(),
                ));
            }
        }

        return $counts;
    }
}
