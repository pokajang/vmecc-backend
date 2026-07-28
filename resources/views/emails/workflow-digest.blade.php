<x-mail::message>
<x-mail.preheader>Your VMECC OS workflow summary and pending action reminders.</x-mail.preheader>
<x-mail.category>Workflow notifications</x-mail.category>

# Workflow digest

Hello {{ $recipient->name ?: 'there' }},

Here is your workflow summary for **{{ $windowStart->format('d M Y H:i') }}** to **{{ $windowEnd->format('d M Y H:i') }}**.

@if ($reminderItems->isNotEmpty())
<div class="mail-digest-group">
<h2>Pending action reminders</h2>
@foreach ($reminderItems as $group)
<p class="mail-digest-module"><strong>{{ ucfirst((string) $group['module']) }}</strong> ({{ $group['count'] }})</p>
<ul class="mail-list">
@foreach ($group['items'] as $item)
<li>
<a href="{{ $item['deepLink'] }}">{{ $item['title'] }}</a>@if (!empty($item['recordDisplayId'])) — {{ $item['recordDisplayId'] }}@endif
</li>
@endforeach
</ul>
@endforeach
</div>
@endif

@if ($deferredItems->isNotEmpty())
<div class="mail-digest-group">
<h2>Other updates</h2>
@foreach ($deferredItems as $group)
<p class="mail-digest-module"><strong>{{ ucfirst((string) $group['module']) }}</strong> ({{ $group['count'] }})</p>
<ul class="mail-list">
@foreach ($group['items'] as $item)
<li>
<a href="{{ $item['deepLink'] }}">{{ $item['title'] }}</a>@if (!empty($item['recordDisplayId'])) — {{ $item['recordDisplayId'] }}@endif
</li>
@endforeach
</ul>
@endforeach
</div>
@endif
</x-mail::message>
