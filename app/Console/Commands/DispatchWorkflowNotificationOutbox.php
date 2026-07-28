<?php

namespace App\Console\Commands;

use App\Jobs\ProcessWorkflowNotificationOutboxJob;
use App\Models\WorkflowNotificationOutbox;
use Illuminate\Console\Command;

class DispatchWorkflowNotificationOutbox extends Command
{
    protected $signature = 'workflow:dispatch-notification-outbox
        {--limit=100 : Maximum events to dispatch}
        {--retry-failed : Requeue failed events}
        {--stale-after=10 : Minutes before reclaiming an interrupted processing event}';

    protected $description = 'Dispatch pending durable workflow notification delivery events';

    public function handle(): int
    {
        $limit = min(max((int) $this->option('limit'), 1), 1000);
        $staleAfter = min(max((int) $this->option('stale-after'), 1), 1440);
        $staleBefore = now()->subMinutes($staleAfter);
        $retryFailed = (bool) $this->option('retry-failed');
        $rows = WorkflowNotificationOutbox::query()
            ->where(function ($query) use ($retryFailed, $staleBefore) {
                $query
                    ->where(function ($pending) {
                        $pending
                            ->where('status', 'pending')
                            ->where(fn ($available) => $available
                                ->whereNull('available_at')
                                ->orWhere('available_at', '<=', now()));
                    })
                    ->orWhere(function ($processing) use ($staleBefore) {
                        $processing
                            ->where('status', 'processing')
                            ->where('processing_at', '<=', $staleBefore);
                    });
                if ($retryFailed) {
                    $query->orWhere('status', 'failed');
                }
            })
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'event_version', 'status']);

        $queued = 0;
        $rows->each(function (WorkflowNotificationOutbox $row) use (
            $retryFailed,
            $staleBefore,
            &$queued,
        ): void {
            if ($row->status === 'processing') {
                $claimed = WorkflowNotificationOutbox::query()
                    ->whereKey($row->id)
                    ->where('event_version', $row->event_version)
                    ->where('status', 'processing')
                    ->where('processing_at', '<=', $staleBefore)
                    ->update([
                        'status' => 'pending',
                        'available_at' => now(),
                        'processing_at' => null,
                        'last_error' => 'Recovered an interrupted outbox processing lease.',
                        'updated_at' => now(),
                    ]);
                if ($claimed !== 1) {
                    return;
                }
            } elseif ($row->status === 'failed' && $retryFailed) {
                $claimed = WorkflowNotificationOutbox::query()
                    ->whereKey($row->id)
                    ->where('event_version', $row->event_version)
                    ->where('status', 'failed')
                    ->update([
                        'status' => 'pending',
                        'available_at' => now(),
                        'processing_at' => null,
                        'failed_at' => null,
                        'updated_at' => now(),
                    ]);
                if ($claimed !== 1) {
                    return;
                }
            }

            ProcessWorkflowNotificationOutboxJob::dispatch(
                (int) $row->id,
                (int) $row->event_version,
            );
            $queued++;
        });
        $this->info("Queued {$queued} workflow notification outbox event(s).");

        return self::SUCCESS;
    }
}
