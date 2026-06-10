<?php

namespace App\Notifications;

use App\Models\Team;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TeamRosterChangedNotification extends Notification
{
    public function __construct(
        private readonly Team $team,
        private readonly User $newMember,
        private readonly string $newMemberRole,
        private readonly int $memberCount,
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
            ->subject("Team {$this->team->name} roster updated — " . config('app.name'))
            ->markdown('emails.team-roster-changed', [
                'name'          => $notifiable->name,
                'teamName'      => $this->team->name,
                'newMemberName' => $this->newMember->name,
                'newMemberRole' => $this->newMemberRole,
                'memberCount'   => $this->memberCount,
                'appUrl'        => $appUrl,
            ]);
    }
}
