<x-mail::message>
<x-mail.preheader>An administrator initiated a password reset for your VMECC OS account.</x-mail.preheader>
<x-mail.category>Account security</x-mail.category>

# Password reset

Hello {{ $recipientName ?: 'there' }},

{{ $adminLine }}

<x-mail::button :url="$resetUrl" color="primary">
Reset Password
</x-mail::button>

This password reset link will expire in **{{ $expireMinutes }} minutes**.

If you did not expect this, please contact your administrator.
</x-mail::message>
