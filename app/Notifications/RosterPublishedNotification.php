<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class RosterPublishedNotification extends Notification
{
    /**
     * @param string $scopeLabel  Human-readable range, e.g. "April 2026" or "2026-04-14 – 2026-04-20"
     * @param array  $shifts      Array of ['date' => '2026-04-14', 'shift' => 'day'] for this member's team
     * @param string $teamName    The team name the member belongs to
     */
    public function __construct(
        private readonly string $scopeLabel,
        private readonly array $shifts,
        private readonly string $teamName,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $appUrl = config('app.frontend_url', config('app.url'));

        // Pre-sanitise shift dates so Carbon::parse() in the blade cannot throw
        // if a malformed date somehow reaches this point.
        $safeShifts = array_values(array_filter(
            array_map(function (mixed $shift): ?array {
                if (!is_array($shift)) return null;
                $date = trim((string) ($shift['date'] ?? ''));
                if ($date === '' || ! strtotime($date)) return null;
                $shiftType = in_array($shift['shift'] ?? '', ['day', 'night'], true)
                    ? $shift['shift']
                    : 'day';
                return ['date' => $date, 'shift' => $shiftType];
            }, $this->shifts),
        ));

        return (new MailMessage)
            ->subject("Roster published: {$this->scopeLabel} — " . config('app.name'))
            ->markdown('emails.roster-published', [
                'name'       => $notifiable->name,
                'teamName'   => $this->teamName,
                'scopeLabel' => $this->scopeLabel,
                'shifts'     => $safeShifts,
                'appUrl'     => $appUrl,
            ]);
    }
}
