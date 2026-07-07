<p>Hello {{ $recipient->name ?: 'there' }},</p>

<p>{{ $notification->message }}</p>

<p>
Module: {{ ucfirst((string) $notification->module) }}<br>
Event: {{ (string) $notification->event_type }}<br>
@if ($notification->record_display_id)
Record ID: {{ (string) $notification->record_display_id }}<br>
@endif
</p>

<p>
<a href="{{ $actionUrl }}">Open workflow item</a>
</p>
