<?php

namespace App\Console\Commands;

use App\Jobs\SendWorkflowDigestEmailJob;
use App\Models\WorkflowNotificationRecipientState;
use App\Services\WorkflowNotifications\WorkflowNotificationChannelPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class SendWorkflowDigests extends Command
{
    protected $signature = 'workflow:send-digests {--window-end=} {--window-hours=12}';

    protected $description = 'Dispatch workflow digest emails for unread FYI items and open action-required reminders.';

    public function handle(): int
    {
        $windowEnd = $this->option('window-end')
            ? CarbonImmutable::parse((string) $this->option('window-end'))
            : CarbonImmutable::now();
        $windowHours = max(1, (int) ($this->option('window-hours') ?: config('mail.workflow_notifications.digest_window_hours', 12)));
        $windowStart = $windowEnd->subHours($windowHours);

        $userIds = WorkflowNotificationRecipientState::query()
            ->whereNull('dismissed_at')
            ->where(function (Builder $query) use ($windowStart) {
                $query
                    ->where(function (Builder $deferred) use ($windowStart) {
                        $deferred
                            ->where(function ($inner) {
                                $inner
                                    ->where('channel_policy', WorkflowNotificationChannelPolicy::IN_APP_PLUS_DIGEST)
                                    ->orWhere('channel_policy', WorkflowNotificationChannelPolicy::IN_APP_PLUS_IMMEDIATE_PLUS_DIGEST_REMINDER);
                            })
                            ->whereNull('read_at')
                            ->where(function ($inner) use ($windowStart) {
                                $inner->whereNull('emailed_digest_at')
                                    ->orWhere('emailed_digest_at', '<', $windowStart);
                            });
                    })
                    ->orWhere(function (Builder $reminders) use ($windowStart) {
                        $reminders
                            ->where('channel_policy', WorkflowNotificationChannelPolicy::IN_APP_PLUS_IMMEDIATE_PLUS_DIGEST_REMINDER)
                            ->where(function ($inner) use ($windowStart) {
                                $inner->whereNull('last_reminder_at')
                                    ->orWhere('last_reminder_at', '<', $windowStart);
                            });
                    });
            })
            ->pluck('user_id')
            ->filter()
            ->unique()
            ->values();

        foreach ($userIds as $userId) {
            SendWorkflowDigestEmailJob::dispatch(
                (int) $userId,
                $windowStart->toIso8601String(),
                $windowEnd->toIso8601String(),
            );
        }

        $this->info(sprintf(
            'Queued workflow digests for %d users (%s to %s).',
            $userIds->count(),
            $windowStart->toIso8601String(),
            $windowEnd->toIso8601String(),
        ));

        return self::SUCCESS;
    }
}
