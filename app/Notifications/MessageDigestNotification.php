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
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $frontendUrl = config('app.frontend_url', config('app.url'));
        $messagesUrl = rtrim($frontendUrl, '/').'/messages';

        $mail = (new MailMessage)
            ->subject('You have unread messages')
            ->greeting("Hello {$notifiable->name},")
            ->line("You have {$this->count} unread message(s).");

        if (! empty($this->topSenders)) {
            $summary = collect($this->topSenders)
                ->map(fn ($entry) => "{$entry['name']} ({$entry['count']})")
                ->implode(', ');
            $mail->line("Top senders: {$summary}");
        }

        if (! empty($this->items)) {
            $mail->line('Unread messages:');
            foreach ($this->items as $item) {
                $time = $item['time'] ?? '';
                $label = $time ? "{$item['name']} ({$time})" : $item['name'];
                $mail->line("- {$label}: \"{$item['snippet']}\"");
            }
        }

        return $mail
            ->action('Open Messages', $messagesUrl)
            ->line('If you already read them, you can ignore this email.');
    }
}
