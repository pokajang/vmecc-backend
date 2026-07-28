<?php

namespace App\Jobs;

use App\Models\WorkflowNotificationOutbox;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class ProcessWorkflowNotificationOutboxJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public function __construct(
        private readonly int $outboxId,
        private readonly int $eventVersion,
    ) {}

    public function backoff(): array
    {
        return [15, 60, 300, 900];
    }

    public function handle(): void
    {
        $row = DB::transaction(function (): ?WorkflowNotificationOutbox {
            $row = WorkflowNotificationOutbox::query()->lockForUpdate()->find($this->outboxId);
            if (! $row
                || (int) $row->event_version !== $this->eventVersion
                || $row->processed_at !== null
                || ($row->available_at !== null && $row->available_at->isFuture())
                || ($row->status === 'processing'
                    && $row->processing_at !== null
                    && $row->processing_at->isAfter(now()->subMinutes(10)))) {
                return null;
            }
            $row->update([
                'status' => 'processing',
                'attempts' => ((int) $row->attempts) + 1,
                'available_at' => null,
                'processing_at' => now(),
                'failed_at' => null,
                'last_error' => null,
            ]);

            return $row->fresh();
        });
        if (! $row) {
            return;
        }

        try {
            DispatchWorkflowChannelsJob::dispatchSync((int) $row->notification_id);
            WorkflowNotificationOutbox::query()
                ->whereKey($row->id)
                ->where('event_version', $this->eventVersion)
                ->update([
                    'status' => 'processed',
                    'available_at' => null,
                    'processed_at' => now(),
                    'processing_at' => null,
                    'last_error' => null,
                    'updated_at' => now(),
                ]);
        } catch (\Throwable $exception) {
            WorkflowNotificationOutbox::query()
                ->whereKey($row->id)
                ->where('event_version', $this->eventVersion)
                ->update([
                    'status' => 'pending',
                    'available_at' => now()->addSeconds($this->retryDelay()),
                    'processing_at' => null,
                    'last_error' => mb_substr($exception->getMessage(), 0, 4000),
                    'updated_at' => now(),
                ]);
            throw $exception;
        }
    }

    public function failed(?\Throwable $exception): void
    {
        WorkflowNotificationOutbox::query()
            ->whereKey($this->outboxId)
            ->where('event_version', $this->eventVersion)
            ->update([
                'status' => 'failed',
                'failed_at' => now(),
                'processing_at' => null,
                'last_error' => mb_substr((string) $exception?->getMessage(), 0, 4000),
                'updated_at' => now(),
            ]);
    }

    private function retryDelay(): int
    {
        return match (min(max($this->attempts(), 1), 5)) {
            1 => 15,
            2 => 60,
            3 => 300,
            default => 900,
        };
    }
}
