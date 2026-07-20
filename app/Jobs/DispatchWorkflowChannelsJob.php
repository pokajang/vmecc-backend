<?php

namespace App\Jobs;

use App\Models\User;
use App\Models\WorkflowNotification;
use App\Models\WorkflowNotificationRecipientState;
use App\Services\WorkflowNotifications\WorkflowEmailModuleGate;
use App\Services\WorkflowNotifications\WorkflowNotificationChannelPolicy;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DispatchWorkflowChannelsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(private readonly int $notificationId) {}

    public function handle(): void
    {
        if (! config('mail.workflow_notifications.enabled', false)) {
            return;
        }

        $notification = WorkflowNotification::find($this->notificationId);
        if (! $notification || ! $this->isEmailEnabledFor($notification)) {
            return;
        }

        WorkflowNotificationRecipientState::query()
            ->where('notification_id', $notification->id)
            ->whereNotNull('user_id')
            ->whereNull('dismissed_at')
            ->where(function ($query) {
                $query
                    ->where('channel_policy', WorkflowNotificationChannelPolicy::IN_APP_PLUS_IMMEDIATE_EMAIL)
                    ->orWhere('channel_policy', WorkflowNotificationChannelPolicy::IN_APP_PLUS_IMMEDIATE_PLUS_DIGEST_REMINDER);
            })
            ->whereNull('emailed_immediate_at')
            ->pluck('user_id')
            ->unique()
            ->each(function (int $userId) use ($notification) {
                $recipient = User::query()
                    ->whereKey($userId)
                    ->whereRaw("LOWER(TRIM(COALESCE(status, ''))) = 'active'")
                    ->whereNotNull('email')
                    ->whereRaw("TRIM(email) <> ''")
                    ->first();
                if (! $recipient) {
                    return;
                }

                SendWorkflowImmediateEmailJob::dispatch($notification->id, (int) $recipient->id);
            });
    }

    private function isEmailEnabledFor(WorkflowNotification $notification): bool
    {
        return WorkflowEmailModuleGate::enabledFor(
            $notification->module,
            $notification->record_type,
        );
    }
}
