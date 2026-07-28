<x-mail::message>
<x-mail.preheader>{{ $notification->message }}</x-mail.preheader>
<x-mail.category>{{ ucfirst((string) $notification->module) }} workflow</x-mail.category>

# {{ $notification->title }}

Hello {{ $recipient->name ?: 'there' }},

{{ $notification->message }}

<x-mail.details :items="[
    'Module' => ucfirst((string) $notification->module),
    'Event' => ucfirst(str_replace('_', ' ', (string) $notification->event_type)),
    'Record ID' => (string) ($notification->record_display_id ?? ''),
]" />

<x-mail::button :url="$actionUrl" color="primary">
Open workflow item
</x-mail::button>
</x-mail::message>
