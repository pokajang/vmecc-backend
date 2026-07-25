<?php

namespace App\Jobs;

use App\Models\User;
use App\Models\WorkflowNotification;
use App\Services\AssignmentAuthorizationService;
use App\Services\WorkflowNotifications\WorkflowNotificationLinkResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendWorkflowNotificationEmailJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public array $backoff = [30, 120, 300];

    public function __construct(private readonly int $notificationId) {}

    public function handle(): void
    {
        DispatchWorkflowChannelsJob::dispatch($this->notificationId);
    }

    private function buildDeepLink(
        WorkflowNotification $notification,
        User $recipient,
        AssignmentAuthorizationService $authorizationService,
        string $frontendBase,
        string $fallbackUrl,
    ): string {
        $resolver = new WorkflowNotificationLinkResolver($authorizationService);
        $resolved = $resolver->resolveAbsolute($notification, $recipient, $frontendBase);

        return $resolved !== '' ? $resolved : $fallbackUrl;
    }
}
