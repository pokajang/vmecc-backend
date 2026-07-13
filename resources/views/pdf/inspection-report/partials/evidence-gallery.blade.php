@php
    $galleryItems = [];

    foreach (is_array($evidenceGroups ?? null) ? $evidenceGroups : [] as $group) {
        if (! is_array($group)) {
            continue;
        }

        $groupPhotos = is_array($group['photos'] ?? null) ? $group['photos'] : [];
        foreach ($groupPhotos as $photoIndex => $photo) {
            if (! is_array($photo)) {
                continue;
            }

            $url = trim((string) ($photo['url'] ?? ''));
            $imageUnavailable = ($photo['imageUnavailable'] ?? false) === true;
            if (! $imageUnavailable && ($url === '' || preg_match('/^data:image\/[a-z0-9.+-]+;base64,/i', $url) !== 1)) {
                continue;
            }

            $description = trim((string) ($photo['description'] ?? ''));
            if ($description === '') {
                $description = 'Image description not provided by user';
            }
            $description = preg_replace('/\s+/u', ' ', $description);
            if (! preg_match('/[.!?]$/u', $description)) {
                $description .= '.';
            }

            $galleryItems[] = [
                'kind' => trim((string) ($group['kind'] ?? 'Evidence')),
                'title' => trim((string) ($group['title'] ?? 'Inspection evidence')),
                'remarks' => $photoIndex === 0 ? trim((string) ($group['remarks'] ?? '')) : '',
                'remarksLabel' => trim((string) ($group['remarksLabel'] ?? '')),
                'url' => $url,
                'imageUnavailable' => $imageUnavailable,
                'description' => $description,
                'alt' => trim((string) ($group['alt'] ?? 'Inspection evidence photo')),
            ];
        }
    }
@endphp

@if (count($galleryItems) > 0)
    <table class="evidence-grid">
        <tbody>
            @if (count($galleryItems) === 1)
                @php $item = $galleryItems[0]; @endphp
                <tr>
                    <td colspan="2" style="width: 100%;">
                        <div class="evidence-card">
                            <table class="evidence-single-layout">
                                <tr>
                                    <td class="evidence-single-media">
                                        <div class="evidence-image-wrap">
                                            @if ($item['imageUnavailable'])
                                                <div class="evidence-image-unavailable"><span>Image unavailable</span></div>
                                            @else
                                                <img class="evidence-image" src="{{ $item['url'] }}" alt="{{ $item['alt'] }}">
                                            @endif
                                        </div>
                                    </td>
                                    <td class="evidence-single-copy">
                                        <div class="evidence-kind">{{ $item['kind'] }}</div>
                                        <div class="evidence-title">{{ $item['title'] }}</div>
                                        @if ($item['remarks'] !== '')
                                            @if ($item['remarksLabel'] !== '')
                                                <div class="compact-info-label">{{ $item['remarksLabel'] }}</div>
                                            @endif
                                            <div class="evidence-remarks">{{ $item['remarks'] }}</div>
                                        @endif
                                        <div class="evidence-description">{{ $item['description'] }}</div>
                                    </td>
                                </tr>
                            </table>
                        </div>
                    </td>
                </tr>
            @else
                @foreach (array_chunk($galleryItems, 2) as $galleryRow)
                    <tr>
                        @foreach ($galleryRow as $item)
                            <td>
                                <div class="evidence-card">
                                    <div class="evidence-kind">{{ $item['kind'] }}</div>
                                    <div class="evidence-title">{{ $item['title'] }}</div>
                                    @if ($item['remarks'] !== '')
                                        @if ($item['remarksLabel'] !== '')
                                            <div class="compact-info-label">{{ $item['remarksLabel'] }}</div>
                                        @endif
                                        <div class="evidence-remarks">{{ $item['remarks'] }}</div>
                                    @endif
                                    <div class="evidence-image-wrap">
                                        @if ($item['imageUnavailable'])
                                            <div class="evidence-image-unavailable"><span>Image unavailable</span></div>
                                        @else
                                            <img class="evidence-image" src="{{ $item['url'] }}" alt="{{ $item['alt'] }}">
                                        @endif
                                    </div>
                                    <div class="evidence-description">{{ $item['description'] }}</div>
                                </div>
                            </td>
                        @endforeach
                        @if (count($galleryRow) === 1)
                            <td class="evidence-grid-empty"></td>
                        @endif
                    </tr>
                @endforeach
            @endif
        </tbody>
    </table>
@endif
