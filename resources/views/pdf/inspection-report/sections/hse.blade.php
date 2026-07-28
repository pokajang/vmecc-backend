@if ($hasHseObservation)
<div class="card">
    <div class="card-head">HSE Observation</div>
    <div class="card-body">
        <div class="meta-grid meta-grid-4" style="margin-bottom: 8px;">
            <div class="meta-cell">
                <div class="meta-label">Inspected By</div>
                <div class="meta-value">{{ $hseInspectedBy !== '' ? $hseInspectedBy : '--' }}</div>
                @if ($inspectedByRole !== '')
                    <div class="person-meta">{{ $inspectedByRole }}</div>
                @endif
            </div>
            <div class="meta-cell">
                <div class="meta-label">Observed At</div>
                <div class="meta-value">{{ $hse['observedAt'] !== '' ? $fmtDateTime($hse['observedAt']) : '--' }}</div>
            </div>
            <div class="meta-cell">
                <div class="meta-label">Location</div>
                <div class="meta-value">{{ $hse['location'] !== '' ? $hse['location'] : '--' }}</div>
            </div>
            <div class="meta-cell">
                <div class="meta-label">Observation Type</div>
                <div class="meta-value">
                    @forelse ($hseSelections as $selection)
                        <span class="pill">{{ $hseSelectionLabels[$selection] ?? $selection }}</span>
                    @empty
                        --
                    @endforelse
                </div>
            </div>
        </div>

        @foreach ($hse['details'] as $detail)
            <div class="divider"></div>
            <div class="text-block-label">{{ $detail['label'] }}</div>
            <div class="text-block-value">{{ $detail['value'] }}</div>
        @endforeach

        @foreach ($hse['optional'] as $field)
            <div class="divider"></div>
            <div class="text-block-label">{{ $field['label'] }}</div>
            <div class="text-block-value">{{ $field['value'] }}</div>
        @endforeach

        @if ($hse['photoCount'] > 0)
            <div class="divider"></div>
            <div class="text-block-label">Observation Photos ({{ $hse['photoCount'] }})</div>
            @include('pdf.inspection-report.partials.evidence-gallery', ['evidenceGroups' => $hse['photoGroups']])
        @endif
    </div>
</div>
@endif
