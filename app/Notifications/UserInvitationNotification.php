<?php

namespace App\Notifications;

use App\Models\TeamMember;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Password;

class UserInvitationNotification extends Notification
{
    public function __construct(private readonly string $frontendUrl)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $token = Password::createToken($notifiable);
        $resetUrl = rtrim($this->frontendUrl, '/') . "/reset-password?token={$token}&email={$notifiable->email}";

        // Resolve primary role name
        $roleName = null;
        if (method_exists($notifiable, 'roleAssignments')) {
            $primary = $notifiable->roleAssignments()
                ->where('is_primary', true)
                ->with('role')
                ->latest()
                ->first();
            $roleName = $primary?->role?->name;

            // Fallback: any active assignment
            if (! $roleName) {
                $any = $notifiable->roleAssignments()->with('role')->latest()->first();
                $roleName = $any?->role?->name;
            }
        }

        // Resolve team name from team_members (already synced before notification fires)
        $teamName = null;
        $teamMember = TeamMember::query()
            ->where('user_id', $notifiable->id)
            ->whereNull('ended_at')
            ->with('team')
            ->latest()
            ->first();
        $teamName = $teamMember?->team?->name;

        // Password expiry — Laravel default is 60 minutes, but invitation tokens last longer
        $expiryMinutes = config('auth.passwords.users.expire', 60);
        $expiryHours = $expiryMinutes >= 60
            ? round($expiryMinutes / 60) . ' hour' . (round($expiryMinutes / 60) !== 1.0 ? 's' : '')
            : "{$expiryMinutes} minutes";

        return (new MailMessage)
            ->subject('Welcome to ' . config('app.name') . ' — Set your password')
            ->markdown('emails.user-invitation', [
                'name'        => $notifiable->name,
                'resetUrl'    => $resetUrl,
                'roleName'    => $roleName,
                'teamName'    => $teamName,
                'expiryHours' => $expiryHours,
            ]);
    }
}
