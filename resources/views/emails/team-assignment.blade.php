<x-mail::message>
# You've been assigned to a team

Hello **{{ $name }}**,

You have been assigned to **Team {{ $teamName }}** as **{{ $roleName }}**.

Log in to view your team details and upcoming roster.

<x-mail::button :url="$appUrl" color="primary">
Go to {{ config('app.name') }}
</x-mail::button>

If you have questions about your assignment, contact your Incident Commander or Contract Manager.

Regards,
{{ config('app.name') }}
</x-mail::message>
