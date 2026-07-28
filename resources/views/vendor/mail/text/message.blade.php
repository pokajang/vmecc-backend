@php
    $body = preg_replace(
        '/<div class="mail-preheader">.*?<\/div>\s*/s',
        '',
        (string) $slot,
    ) ?? (string) $slot;
@endphp

<x-mail::layout>
    <x-slot:header>
        <x-mail::header :url="config('app.frontend_url', config('app.url'))">
            {{ config('mail.branding.product_name', config('app.name')) }}
        </x-mail::header>
    </x-slot:header>

    {!! $body !!}

    @isset($subcopy)
        <x-slot:subcopy>
            <x-mail::subcopy>
                {{ $subcopy }}
            </x-mail::subcopy>
        </x-slot:subcopy>
    @endisset

    <x-slot:footer>
        <x-mail::footer>
            Sent automatically by {{ config('mail.branding.automated_sender_name', 'VMECC OS Alert') }}.
            Please do not reply to this message.
        </x-mail::footer>
    </x-slot:footer>
</x-mail::layout>
