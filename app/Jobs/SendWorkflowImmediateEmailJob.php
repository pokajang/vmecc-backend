<?php

namespace App\Jobs;

use App\Mail\WorkflowImmediateNotificationMail;
use App\Models\User;
use App\Models\WorkflowEmailDelivery;
use App\Models\WorkflowNotification;
use App\Models\WorkflowNotificationRecipientState;
use App\Services\WorkflowNotifications\WorkflowNotificationChannelPolicy;
use App\Services\WorkflowNotifications\WorkflowNotificationLinkResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendWorkflowImmediateEmailJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;
    public array $backoff = [30, 120, 300];

    public function __construct(
        private readonly int $notificationId,
        private readonly int $userId,
    ) {
    }

    public function handle(WorkflowNotificationLinkResolver $linkResolver): void
    {
        if (! config('mail.workflow_notifications.enabled', false)) {
            return;
        }

        $notification = WorkflowNotification::find($this->notificationId);
        $recipient = User::query()->whereKey($this->userId)->whereNotNull('email')->first();
        $state = WorkflowNotificationRecipientState::query()
            ->where('notification_id', $this->notificationId)
            ->where('user_id', $this->userId)
            ->first();

        if (! $notification || ! $recipient || ! $state) {
            return;
        }

        if ($state->emailed_immediate_at !== null) {
            return;
        }

        if (! WorkflowNotificationChannelPolicy::sendsImmediateEmail((string) $state->channel_policy)) {
            return;
        }

        $reservedAt = now();
        $reserved = WorkflowNotificationRecipientState::query()
            ->whereKey($state->id)
            ->whereNull('emailed_immediate_at')
            ->whereNull('dismissed_at')
            ->where(function ($query) {
                $query
                    ->where('channel_policy', WorkflowNotificationChannelPolicy::IN_APP_PLUS_IMMEDIATE_EMAIL)
                    ->orWhere('channel_policy', WorkflowNotificationChannelPolicy::IN_APP_PLUS_IMMEDIATE_PLUS_DIGEST_REMINDER);
            })
            ->update([
                'emailed_immediate_at' => $reservedAt,
                'updated_at' => $reservedAt,
            ]);

        if ($reserved !== 1) {
            return;
        }

        $delivery = WorkflowEmailDelivery::create([
            'notification_id' => $notification->id,
            'user_id' => $recipient->id,
            'recipient_email' => (string) $recipient->email,
            'delivery_kind' => 'immediate',
            'status' => 'queued',
            'attempts' => 0,
        ]);

        try {
            Mail::to($recipient->email)->send(new WorkflowImmediateNotificationMail(
                $notification,
                $recipient,
                $linkResolver->resolveAbsolute($notification, $recipient),
            ));

            $delivery->update([
                'status' => 'sent',
                'attempts' => 1,
                'sent_at' => now(),
                'last_error' => null,
            ]);

            $state->forceFill([
                'emailed_immediate_at' => $reservedAt,
                'updated_at' => now(),
            ])->save();
        } catch (\Throwable $exception) {
            WorkflowNotificationRecipientState::query()
                ->whereKey($state->id)
                ->where('emailed_immediate_at', $reservedAt)
                ->update([
                    'emailed_immediate_at' => null,
                    'updated_at' => now(),
                ]);

            $delivery->update([
                'status' => 'failed',
                'attempts' => 1,
                'last_error' => $exception->getMessage(),
            ]);

            Log::warning('Workflow immediate email dispatch failed.', [
                'notification_id' => $notification->id,
                'user_id' => $recipient->id,
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }
}
