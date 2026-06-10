<?php

namespace App\Notifications;

use App\Models\Team;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TeamAssignmentNotification extends Notification
{
    public function __construct(
        private readonly Team $team,
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
            ->subject("You've been assigned to Team {$this->team->name} — " . config('app.name'))
            ->markdown('emails.team-assignment', [
                'name'     => $notifiable->name,
                'teamName' => $this->team->name,
                'roleName' => $this->roleName,
                'appUrl'   => $appUrl,
            ]);
    }
}
