<x-mail::layout>
{{-- Header --}}
<x-slot:header>
<x-mail::header :url="config('app.frontend_url', config('app.url'))">
{{ config('mail.branding.product_name', config('app.name')) }}
</x-mail::header>
</x-slot:header>

{{-- Body --}}
{!! $slot !!}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Footer --}}
<x-slot:footer>
<x-mail::footer>
Sent automatically by {{ config('mail.branding.automated_sender_name', 'VMECC OS Alert') }}.<br>
Please do not reply to this message.
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
