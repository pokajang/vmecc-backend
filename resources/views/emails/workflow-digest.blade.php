<p>Hello {{ $recipient->name ?: 'there' }},</p>

<p>
Workflow digest for {{ $windowStart->format('d M Y H:i') }} to {{ $windowEnd->format('d M Y H:i') }}.
</p>

@if ($reminderItems->isNotEmpty())
    <h3>Pending action reminders</h3>
    @foreach ($reminderItems as $group)
        <p><strong>{{ ucfirst((string) $group['module']) }}</strong> ({{ $group['count'] }})</p>
        <ul>
            @foreach ($group['items'] as $item)
                <li>
                    <a href="{{ $item['deepLink'] }}">{{ $item['title'] }}</a>
                    @if (!empty($item['recordDisplayId']))
                        - {{ $item['recordDisplayId'] }}
                    @endif
                </li>
            @endforeach
        </ul>
    @endforeach
@endif

@if ($deferredItems->isNotEmpty())
    <h3>Other updates</h3>
    @foreach ($deferredItems as $group)
        <p><strong>{{ ucfirst((string) $group['module']) }}</strong> ({{ $group['count'] }})</p>
        <ul>
            @foreach ($group['items'] as $item)
                <li>
                    <a href="{{ $item['deepLink'] }}">{{ $item['title'] }}</a>
                    @if (!empty($item['recordDisplayId']))
                        - {{ $item['recordDisplayId'] }}
                    @endif
                </li>
            @endforeach
        </ul>
    @endforeach
@endif
