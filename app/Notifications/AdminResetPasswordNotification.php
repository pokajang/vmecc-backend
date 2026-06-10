<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword as BaseResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

class AdminResetPasswordNotification extends BaseResetPassword
{
    public function __construct(string $token, private readonly ?string $adminName = null, private readonly ?string $adminEmail = null)
    {
        parent::__construct($token);
    }

    public function toMail($notifiable): MailMessage
    {
        $url = $this->resetUrl($notifiable);
        $expire = config('auth.passwords.'.config('auth.defaults.passwords').'.expire');

        $adminLine = 'An administrator has initiated a password reset for your account.';
        if ($this->adminName && $this->adminEmail) {
            $adminLine = "An administrator ({$this->adminName} - {$this->adminEmail}) has initiated a password reset for your account.";
        } elseif ($this->adminName) {
            $adminLine = "An administrator ({$this->adminName}) has initiated a password reset for your account.";
        } elseif ($this->adminEmail) {
            $adminLine = "An administrator ({$this->adminEmail}) has initiated a password reset for your account.";
        }

        return (new MailMessage)
            ->subject('Password Reset (Admin Initiated)')
            ->greeting("Hello {$notifiable->name},")
            ->line($adminLine)
            ->action('Reset Password', $url)
            ->line("This password reset link will expire in {$expire} minutes.")
            ->line('If you did not expect this, please contact your administrator.');
    }
}
