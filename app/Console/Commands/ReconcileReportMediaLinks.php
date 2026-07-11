<?php

namespace App\Console\Commands;

use App\Models\Report;
use App\Models\ReportMediaLink;
use App\Services\ReportMediaModulePolicy;
use App\Services\ReportMediaService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class ReconcileReportMediaLinks extends Command
{
    protected $signature = 'report-media:reconcile-reports
        {--module= : Required report-media module to reconcile}
        {--dry-run : Validate and report changes without committing them}
        {--batch=100 : Number of reports to process per batch (1-500)}';

    protected $description = 'Audit or reconcile durable media links for persisted reports.';

    public function handle(
        ReportMediaService $mediaService,
        ReportMediaModulePolicy $modulePolicy,
    ): int {
        $module = $modulePolicy->normalize($this->option('module'));
        if ($module === '' || ! $modulePolicy->isSupported($module)) {
            $this->error('Provide a supported --module value.');

            return self::INVALID;
        }

        $batchSize = (int) $this->option('batch');
        if ($batchSize < 1 || $batchSize > 500) {
            $this->error('--batch must be between 1 and 500.');

            return self::INVALID;
        }

        $dryRun = (bool) $this->option('dry-run');
        $counts = [
            'scanned' => 0,
            'already_correct' => 0,
            'would_repair' => 0,
            'repaired' => 0,
            'rejected' => 0,
        ];

        Report::query()
            ->where('report_type', $module)
            ->orderBy('id')
            ->chunkById($batchSize, function ($reports) use ($mediaService, $module, $dryRun, &$counts): void {
                foreach ($reports as $report) {
                    $counts['scanned']++;
                    try {
                        $before = $this->linkedPublicIds((string) $report->report_uid);
                        $after = $dryRun
                            ? $this->simulateReconciliation($mediaService, $report, $module)
                            : $this->reconcile($mediaService, $report, $module);

                        if ($before === $after) {
                            $counts['already_correct']++;
                        } elseif ($dryRun) {
                            $counts['would_repair']++;
                        } else {
                            $counts['repaired']++;
                        }
                    } catch (Throwable $exception) {
                        $counts['rejected']++;
                        $this->warn(sprintf(
                            'Rejected report %s (%s): %s',
                            (string) $report->report_uid,
                            (string) $report->display_id,
                            $exception->getMessage(),
                        ));
                    }
                }
            });

        $this->table(
            ['Mode', 'Module', 'Scanned', 'Already correct', 'Would repair', 'Repaired', 'Rejected'],
            [[
                $dryRun ? 'dry-run' : 'apply',
                $module,
                $counts['scanned'],
                $counts['already_correct'],
                $counts['would_repair'],
                $counts['repaired'],
                $counts['rejected'],
            ]],
        );

        return $counts['rejected'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function simulateReconciliation(
        ReportMediaService $mediaService,
        Report $report,
        string $module,
    ): array {
        DB::beginTransaction();
        try {
            $mediaService->syncPayloadLinks(
                is_array($report->payload) ? $report->payload : [],
                'report',
                (string) $report->report_uid,
                (int) $report->owner_user_id,
                $module,
            );
            $after = $this->linkedPublicIds((string) $report->report_uid);
            DB::rollBack();

            return $after;
        } catch (Throwable $exception) {
            DB::rollBack();
            throw $exception;
        }
    }

    private function reconcile(
        ReportMediaService $mediaService,
        Report $report,
        string $module,
    ): array {
        DB::transaction(function () use ($mediaService, $report, $module): void {
            $locked = Report::query()->where('id', $report->id)->lockForUpdate()->firstOrFail();
            $mediaService->syncPayloadLinks(
                is_array($locked->payload) ? $locked->payload : [],
                'report',
                (string) $locked->report_uid,
                (int) $locked->owner_user_id,
                $module,
            );
        });

        return $this->linkedPublicIds((string) $report->report_uid);
    }

    /**
     * @return array<int, string>
     */
    private function linkedPublicIds(string $reportUid): array
    {
        return ReportMediaLink::query()
            ->join('report_media', 'report_media.id', '=', 'report_media_links.report_media_id')
            ->where('report_media_links.parent_type', 'report')
            ->where('report_media_links.parent_key', $reportUid)
            ->orderBy('report_media.public_id')
            ->pluck('report_media.public_id')
            ->map(fn ($value): string => (string) $value)
            ->all();
    }
}
