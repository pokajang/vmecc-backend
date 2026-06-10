<x-mail::message>
# Team {{ $teamName }} has been disbanded

Hello **{{ $name }}**,

We wanted to let you know that **Team {{ $teamName }}** has been disbanded and your assignment as **{{ $roleName ?: 'a team member' }}** has been removed.

If you have questions about this change, please contact your Incident Commander or Contract Manager.

<x-mail::button :url="$appUrl" color="primary">
Go to {{ config('app.name') }}
</x-mail::button>

Regards,
{{ config('app.name') }}
</x-mail::message>
