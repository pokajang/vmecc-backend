<?php

namespace App\Jobs;

use App\Models\User;
use App\Models\UserInvitationDelivery;
use App\Notifications\UserInvitationNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendUserInvitationEmailJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;
    public array $backoff = [30, 120, 300];

    public function __construct(private readonly int $deliveryId)
    {
    }

    public function handle(): void
    {
        $delivery = UserInvitationDelivery::find($this->deliveryId);
        if (! $delivery) {
            Log::warning('User invitation delivery record not found.', [
                'delivery_id' => $this->deliveryId,
            ]);

            return;
        }

        $delivery->increment('attempts');

        $recipientEmail = trim((string) $delivery->recipient_email);
        if ($recipientEmail === '') {
            $this->markFailed($delivery, 'Recipient email is missing');
            return;
        }

        $user = User::find($delivery->user_id);
        if (! $user) {
            $this->markFailed($delivery, 'User record is missing for invitation delivery');
            return;
        }

        try {
            $frontendUrl = config('app.frontend_url', config('app.url'));
            $user->notify(new UserInvitationNotification($frontendUrl));

            $delivery->update([
                'status' => 'sent',
                'sent_at' => now(),
                'last_error' => null,
            ]);
        } catch (\Throwable $exception) {
            $delivery->update([
                'status' => 'failed',
                'last_error' => $exception->getMessage(),
            ]);

            Log::warning('User invitation email delivery failed.', [
                'delivery_id' => $delivery->id,
                'user_id' => $delivery->user_id,
                'recipient_email' => $delivery->recipient_email,
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    public function failed(\Throwable $exception): void
    {
        $delivery = UserInvitationDelivery::find($this->deliveryId);
        if (! $delivery) {
            return;
        }

        $delivery->update([
            'status' => 'failed',
            'last_error' => $delivery->last_error ?? $exception->getMessage(),
        ]);

        Log::error('User invitation email job failed after retries.', [
            'delivery_id' => $delivery->id,
            'user_id' => $delivery->user_id,
            'recipient_email' => $delivery->recipient_email,
            'error' => $exception->getMessage(),
        ]);
    }

    private function markFailed(UserInvitationDelivery $delivery, string $reason): void
    {
        $delivery->update([
            'status' => 'failed',
            'last_error' => $reason,
        ]);
    }
}
