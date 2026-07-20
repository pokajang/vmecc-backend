<?php

namespace App\Jobs;

use App\Mail\WorkflowImmediateNotificationMail;
use App\Models\User;
use App\Models\WorkflowEmailDelivery;
use App\Models\WorkflowNotification;
use App\Models\WorkflowNotificationRecipientState;
use App\Services\WorkflowNotifications\WorkflowEmailModuleGate;
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

    private const STALE_RESERVATION_MINUTES = 15;

    public function __construct(
        private readonly int $notificationId,
        private readonly int $userId,
    ) {}

    public function handle(WorkflowNotificationLinkResolver $linkResolver): void
    {
        if (! config('mail.workflow_notifications.enabled', false)) {
            return;
        }

        $notification = WorkflowNotification::find($this->notificationId);
        if (! $notification || ! WorkflowEmailModuleGate::enabledFor(
            $notification->module,
            $notification->record_type,
        )) {
            return;
        }

        $recipient = User::query()
            ->whereKey($this->userId)
            ->whereRaw("LOWER(TRIM(COALESCE(status, ''))) = 'active'")
            ->whereNotNull('email')
            ->whereRaw("TRIM(email) <> ''")
            ->first();
        $state = WorkflowNotificationRecipientState::query()
            ->where('notification_id', $this->notificationId)
            ->where('user_id', $this->userId)
            ->first();

        if (! $recipient || ! $state || $state->dismissed_at !== null) {
            return;
        }

        if ($notification->action_required && ($notification->resolved_at !== null || $state->resolved_at !== null)) {
            return;
        }

        if (! WorkflowNotificationChannelPolicy::sendsImmediateEmail((string) $state->channel_policy)) {
            return;
        }

        if ($this->hasSentDelivery()) {
            return;
        }

        if ($state->emailed_immediate_at !== null && ! $this->releaseStaleReservation($state)) {
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

    public function failed(\Throwable $exception): void
    {
        if ($this->hasSentDelivery()) {
            return;
        }

        $delivery = WorkflowEmailDelivery::query()
            ->where('notification_id', $this->notificationId)
            ->where('user_id', $this->userId)
            ->where('delivery_kind', 'immediate')
            ->where('status', 'queued')
            ->latest('id')
            ->first();

        if (! $delivery) {
            return;
        }

        $delivery->update([
            'status' => 'failed',
            'attempts' => max(1, (int) $delivery->attempts),
            'last_error' => $exception->getMessage(),
        ]);

        WorkflowNotificationRecipientState::query()
            ->where('notification_id', $this->notificationId)
            ->where('user_id', $this->userId)
            ->where('emailed_immediate_at', '<=', $delivery->created_at)
            ->update([
                'emailed_immediate_at' => null,
                'updated_at' => now(),
            ]);
    }

    private function hasSentDelivery(): bool
    {
        return WorkflowEmailDelivery::query()
            ->where('notification_id', $this->notificationId)
            ->where('user_id', $this->userId)
            ->where('delivery_kind', 'immediate')
            ->where('status', 'sent')
            ->exists();
    }

    private function releaseStaleReservation(WorkflowNotificationRecipientState $state): bool
    {
        $reservedAt = $state->emailed_immediate_at;
        if ($reservedAt === null || $reservedAt->isAfter(now()->subMinutes(self::STALE_RESERVATION_MINUTES))) {
            return false;
        }

        $released = WorkflowNotificationRecipientState::query()
            ->whereKey($state->id)
            ->where('emailed_immediate_at', $reservedAt)
            ->update([
                'emailed_immediate_at' => null,
                'updated_at' => now(),
            ]);

        if ($released !== 1) {
            return false;
        }

        WorkflowEmailDelivery::query()
            ->where('notification_id', $this->notificationId)
            ->where('user_id', $this->userId)
            ->where('delivery_kind', 'immediate')
            ->where('status', 'queued')
            ->where('created_at', '<=', $reservedAt->copy()->addSecond())
            ->update([
                'status' => 'failed',
                'last_error' => 'Recovered stale immediate-email reservation.',
                'updated_at' => now(),
            ]);

        return true;
    }
}
