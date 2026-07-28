<?php

namespace App\Mail;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

class WorkflowDigestNotificationMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly User $recipient,
        public readonly Collection $deferredItems,
        public readonly Collection $reminderItems,
        public readonly CarbonInterface $windowStart,
        public readonly CarbonInterface $windowEnd,
    ) {}

    public function build(): self
    {
        return $this
            ->subject(sprintf(
                '[Workflow Digest] %s to %s',
                $this->windowStart->format('d M H:i'),
                $this->windowEnd->format('d M H:i')
            ))
            ->markdown('emails.workflow-digest');
    }
}
