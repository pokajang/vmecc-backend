<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MessageDigestNotification extends Notification
{
    public function __construct(
        private readonly int $count,
        private readonly array $topSenders,
        private readonly array $items,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $frontendUrl = config('app.frontend_url', config('app.url'));
        $messagesUrl = rtrim($frontendUrl, '/').'/messages';

        return (new MailMessage)
            ->subject('You have unread messages')
            ->markdown('emails.message-digest', [
                'recipientName' => $notifiable->name,
                'count' => $this->count,
                'topSenders' => $this->topSenders,
                'items' => $this->items,
                'messagesUrl' => $messagesUrl,
            ]);
    }
}
