<?php

namespace App\Notifications;

use App\Models\FeedbackReport;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class FeedbackReportSubmittedNotification extends Notification
{
    public function __construct(
        private readonly FeedbackReport $report,
        private readonly string $adminUrl,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $pageContext = is_array($this->report->page_context) ? $this->report->page_context : [];

        return (new MailMessage)
            ->subject('New feedback report submitted - '.config('app.name'))
            ->markdown('emails.feedback-report-submitted', [
                'report' => $this->report,
                'reporter' => $this->report->reporter,
                'pageContext' => $pageContext,
                'adminUrl' => $this->adminUrl,
            ]);
    }
}
