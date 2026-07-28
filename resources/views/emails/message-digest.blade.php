<x-mail::message>
<x-mail.preheader>You have {{ $count }} unread VMECC OS message{{ $count === 1 ? '' : 's' }}.</x-mail.preheader>
<x-mail.category>Messages</x-mail.category>

# You have unread messages

Hello {{ $recipientName ?: 'there' }},

You have **{{ $count }} unread message{{ $count === 1 ? '' : 's' }}**.

@if (!empty($topSenders))
**Top senders:** {{ collect($topSenders)->map(fn ($entry) => "{$entry['name']} ({$entry['count']})")->implode(', ') }}
@endif

@if (!empty($items))
<div class="mail-digest-group">
<h2>Unread messages</h2>
<ul class="mail-list">
@foreach ($items as $item)
<li>
<strong>{{ $item['name'] }}</strong>@if (!empty($item['time'])) ({{ $item['time'] }})@endif:
“{{ $item['snippet'] }}”
</li>
@endforeach
</ul>
</div>
@endif

<x-mail::button :url="$messagesUrl" color="primary">
Open Messages
</x-mail::button>

If you already read these messages, you can ignore this email.
</x-mail::message>
