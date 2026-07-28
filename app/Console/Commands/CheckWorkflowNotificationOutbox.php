<?php

namespace App\Console\Commands;

use App\Models\WorkflowNotificationOutbox;
use Illuminate\Console\Command;

class CheckWorkflowNotificationOutbox extends Command
{
    protected $signature = 'workflow:notification-outbox-status
        {--max-age=10 : Minutes before an available event is considered stale}
        {--json : Emit machine-readable JSON}';

    protected $description = 'Report pending, processing, failed, and stale workflow notification outbox events';

    public function handle(): int
    {
        $maxAge = min(max((int) $this->option('max-age'), 1), 1440);
        $staleBefore = now()->subMinutes($maxAge);
        $counts = [
            'pending' => WorkflowNotificationOutbox::query()->where('status', 'pending')->count(),
            'processing' => WorkflowNotificationOutbox::query()->where('status', 'processing')->count(),
            'processed' => WorkflowNotificationOutbox::query()->where('status', 'processed')->count(),
            'failed' => WorkflowNotificationOutbox::query()->where('status', 'failed')->count(),
            'stale' => WorkflowNotificationOutbox::query()
                ->where(function ($query) use ($staleBefore) {
                    $query
                        ->where(function ($pending) use ($staleBefore) {
                            $pending
                                ->where('status', 'pending')
                                ->where('created_at', '<=', $staleBefore)
                                ->where(fn ($available) => $available
                                    ->whereNull('available_at')
                                    ->orWhere('available_at', '<=', now()));
                        })
                        ->orWhere(function ($processing) use ($staleBefore) {
                            $processing
                                ->where('status', 'processing')
                                ->where('processing_at', '<=', $staleBefore);
                        });
                })
                ->count(),
        ];

        if ($this->option('json')) {
            $this->line(json_encode([
                'ok' => $counts['failed'] === 0 && $counts['stale'] === 0,
                'maxAgeMinutes' => $maxAge,
                'counts' => $counts,
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } else {
            $this->table(
                ['Pending', 'Processing', 'Processed', 'Failed', "Stale ({$maxAge}m)"],
                [[
                    $counts['pending'],
                    $counts['processing'],
                    $counts['processed'],
                    $counts['failed'],
                    $counts['stale'],
                ]],
            );
        }

        if ($counts['failed'] > 0 || $counts['stale'] > 0) {
            if (! $this->option('json')) {
                $this->error('Workflow notification outbox requires attention.');
            }

            return self::FAILURE;
        }

        if (! $this->option('json')) {
            $this->info('Workflow notification outbox is healthy.');
        }

        return self::SUCCESS;
    }
}
