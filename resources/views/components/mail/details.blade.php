@props(['items' => []])

<table class="mail-details" width="100%" cellpadding="0" cellspacing="0" role="presentation">
    @foreach ($items as $label => $value)
        @continue($value === null || $value === '')
        <tr>
            <th scope="row">{{ $label }}:</th>
            <td>{{ $value }}</td>
        </tr>
    @endforeach
</table>
