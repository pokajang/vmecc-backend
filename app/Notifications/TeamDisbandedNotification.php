<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TeamDisbandedNotification extends Notification
{
    public function __construct(
        private readonly string $teamName,
        private readonly string $roleName,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $appUrl = config('app.frontend_url', config('app.url'));

        return (new MailMessage)
            ->subject("Team {$this->teamName} has been disbanded — " . config('app.name'))
            ->markdown('emails.team-disbanded', [
                'name'     => $notifiable->name,
                'teamName' => $this->teamName,
                'roleName' => $this->roleName,
                'appUrl'   => $appUrl,
            ]);
    }
}
