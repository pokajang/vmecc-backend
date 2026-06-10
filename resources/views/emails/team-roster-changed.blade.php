<x-mail::message>
# Team roster update — {{ $teamName }}

Hello **{{ $name }}**,

**{{ $newMemberName }}** has been added to **Team {{ $teamName }}** as **{{ $newMemberRole }}**.

Your team now has **{{ $memberCount }}** {{ $memberCount === 1 ? 'member' : 'members' }}.

<x-mail::button :url="$appUrl" color="primary">
View team
</x-mail::button>

Regards,
{{ config('app.name') }}
</x-mail::message>
