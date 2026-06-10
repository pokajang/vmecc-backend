<x-mail::message>
# Welcome to {{ config('app.name') }}

Hello **{{ $name }}**,

Your account has been set up. You're ready to get started — click below to set your password and access the system.

@if($roleName || $teamName)
---

@if($roleName)
**Role:** {{ $roleName }}
@endif
@if($teamName)
**Team:** {{ $teamName }}
@endif

---
@endif

<x-mail::button :url="$resetUrl" color="primary">
Set your password
</x-mail::button>

Your password link expires in **{{ $expiryHours }}**. If it expires, contact your administrator to resend the invitation.

If you weren't expecting this email, you can safely ignore it — no action is required.

Regards,
{{ config('app.name') }}
</x-mail::message>
