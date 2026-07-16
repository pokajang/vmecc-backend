@php
    $inlineCompactBlocks = array_values(array_filter(
        is_array($checkCompactBlocks ?? null) ? $checkCompactBlocks : [],
        'is_array'
    ));
    $inlineTextBlocks = array_values(array_filter(
        is_array($checkTextBlocks ?? null) ? $checkTextBlocks : [],
        fn ($block) => is_array($block) && trim((string) ($block['value'] ?? '')) !== ''
    ));
    $inlineEvidenceGroups = array_values(array_filter(
        is_array($checkEvidenceGroups ?? null) ? $checkEvidenceGroups : [],
        fn ($group) => is_array($group)
            && is_array($group['photos'] ?? null)
            && count($group['photos']) > 0
    ));
    $inlineEvidenceColspan = max(1, (int) ($checkEvidenceColspan ?? 1));
@endphp

@if (count($inlineCompactBlocks) > 0 || count($inlineTextBlocks) > 0 || count($inlineEvidenceGroups) > 0)
    <tr class="inspection-check-evidence-row">
        <td colspan="{{ $inlineEvidenceColspan }}">
            <div class="inspection-check-evidence">
                @foreach ($inlineTextBlocks as $block)
                    <div class="text-block-label{{ $loop->first ? '' : ' inspection-check-evidence__spaced' }}">
                        {{ trim((string) ($block['title'] ?? 'Inspection evidence')) }}
                    </div>
                    @if (trim((string) ($block['label'] ?? '')) !== '')
                        <div class="compact-info-label">{{ $block['label'] }}</div>
                    @endif
                    <div class="text-block-value">{{ $block['value'] }}</div>
                @endforeach
                {!! $renderCompactBlocks($inlineCompactBlocks) !!}
                @include('pdf.inspection-report.partials.evidence-gallery', ['evidenceGroups' => $inlineEvidenceGroups])
            </div>
        </td>
    </tr>
@endif
