<?php

namespace App\Mail;

use App\Models\User;
use App\Models\WorkflowNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class WorkflowImmediateNotificationMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly WorkflowNotification $notification,
        public readonly User $recipient,
        public readonly string $actionUrl,
    ) {
    }

    public function build(): self
    {
        return $this
            ->subject(sprintf('[%s Workflow] %s', ucfirst((string) $this->notification->module), (string) $this->notification->title))
            ->view('emails.workflow-immediate');
    }
}
