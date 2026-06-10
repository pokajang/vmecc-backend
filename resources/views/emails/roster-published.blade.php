<x-mail::message>
# Roster Published: {{ $scopeLabel }}

Hello **{{ $name }}**,

The shift roster for **{{ $scopeLabel }}** has been published. Your team **{{ $teamName }}** has been assigned the following shifts:

<x-mail::table>
| Date | Shift |
|:-----|:------|
@foreach ($shifts as $shift)
| {{ \Carbon\Carbon::parse($shift['date'])->format('D, d M Y') }} | {{ ucfirst($shift['shift']) }} shift |
@endforeach
</x-mail::table>

Please log in to view the full roster and plan accordingly.

<x-mail::button :url="$appUrl" color="primary">
View Roster
</x-mail::button>

Regards,
{{ config('app.name') }}
</x-mail::message>
