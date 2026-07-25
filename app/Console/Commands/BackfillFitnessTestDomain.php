<?php

namespace App\Console\Commands;

use App\Models\FitnessTestReport;
use App\Models\Report;
use App\Services\ReportModuleRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class BackfillFitnessTestDomain extends Command
{
    protected $signature = 'reports:backfill-domain
        {--module=fitness-test : Module to backfill; only fitness-test is supported}
        {--dry-run : Validate without writing}
        {--report-id= : Backfill one report ID or UID}
        {--from= : Submitted-at date lower bound (YYYY-MM-DD)}
        {--to= : Submitted-at date upper bound (YYYY-MM-DD)}
        {--chunk=100 : Number of records per chunk}
        {--cursor=0 : Resume after a report database ID}
        {--force : Replace an existing projection}';

    protected $description = 'Backfill legacy Fitness Test reports into the relational domain projection.';

    public function handle(ReportModuleRegistry $registry): int
    {
        if ($this->option('module') !== 'fitness-test') {
            $this->error('Only --module=fitness-test is supported.');

            return self::INVALID;
        }

        $adapter = $registry->for('fitness-test');
        if ($adapter === null) {
            $this->error('Fitness Test module adapter is unavailable.');

            return self::FAILURE;
        }

        $query = Report::query()->where('report_type', 'fitness-test')->orderBy('id');
        if ($reportId = trim((string) $this->option('report-id'))) {
            $query->where(fn ($q) => $q->where('id', $reportId)->orWhere('report_uid', $reportId));
        }
        if ($from = trim((string) $this->option('from'))) {
            $query->whereDate('submitted_at', '>=', $from);
        }
        if ($to = trim((string) $this->option('to'))) {
            $query->whereDate('submitted_at', '<=', $to);
        }
        $query->where('id', '>', max(0, (int) $this->option('cursor')));

        $counts = ['scanned' => 0, 'projected' => 0, 'skipped' => 0, 'failed' => 0];
        $failures = [];
        $chunk = max(1, min(500, (int) $this->option('chunk')));

        $query->chunkById($chunk, function ($reports) use ($adapter, &$counts, &$failures): void {
            foreach ($reports as $report) {
                $counts['scanned']++;
                if (! $this->option('force') && FitnessTestReport::query()->where('report_id', $report->id)->exists()) {
                    $counts['skipped']++;

                    continue;
                }
                try {
                    $payload = is_array($report->payload) ? $report->payload : [];
                    $payload = $adapter->validateSubmission($payload);
                    if (! $this->option('dry-run')) {
                        DB::transaction(fn () => $adapter->project($report, $payload));
                        $report->update([
                            'domain_projection_status' => 'projected',
                            'domain_projected_at' => now(),
                        ]);
                    }
                    $counts['projected']++;
                } catch (Throwable $exception) {
                    $counts['failed']++;
                    $failures[] = ['reportUid' => $report->report_uid, 'reason' => $exception->getMessage()];
                    if (! $this->option('dry-run')) {
                        $report->update(['domain_projection_status' => 'failed']);
                    }
                }
            }
        }, 'id');

        $this->table(['Scanned', 'Projected', 'Skipped', 'Failed'], [[
            $counts['scanned'], $counts['projected'], $counts['skipped'], $counts['failed'],
        ]]);
        foreach ($failures as $failure) {
            $this->warn("{$failure['reportUid']}: {$failure['reason']}");
        }

        return $counts['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
