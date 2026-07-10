<?php

namespace App\Console\Commands;

use App\Services\ReportMediaService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class PruneReportMedia extends Command
{
    protected $signature = 'report-media:prune {--hours=24}';

    protected $description = 'Delete unlinked temporary report media.';

    public function handle(ReportMediaService $service): int
    {
        try {
            $deleted = $service->pruneUnlinked(max(1, (int) $this->option('hours')));
            Log::info('report_media_prune_completed', ['deleted_count' => $deleted]);
            $this->info('Deleted '.$deleted.' report media file(s).');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            Log::error('report_media_prune_failed', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
            $this->error('Report media cleanup failed.');

            return self::FAILURE;
        }
    }
}
