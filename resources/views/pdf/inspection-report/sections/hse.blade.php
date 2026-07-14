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
                <div class="meta-label">{{ $hse['isVersion2'] ? 'Observed At' : 'Inspection Date' }}</div>
                <div class="meta-value">
                    {{ $hse['isVersion2'] ? ($hse['observedAt'] !== '' ? $fmtDateTime($hse['observedAt']) : '--') : ($hseInspectionDate !== '' ? $hseInspectionDate : '--') }}
                </div>
            </div>
            @if ($hse['isVersion2'])
                <div class="meta-cell">
                    <div class="meta-label">Location</div>
                    <div class="meta-value">{{ $hse['location'] !== '' ? $hse['location'] : '--' }}</div>
                </div>
            @else
                <div class="meta-cell">
                    <div class="meta-label">Severity</div>
                    <div class="meta-value">{{ $hseSeverity !== '' ? $hseSeverity : '--' }}</div>
                </div>
            @endif
            <div class="meta-cell">
                <div class="meta-label">{{ $hse['isVersion2'] ? 'Observation Type' : 'Outcome' }}</div>
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

        @if ($hse['isVersion2'] && $hse['photoCount'] > 0)
            <div class="divider"></div>
            <div class="text-block-label">Observation Photos ({{ $hse['photoCount'] }})</div>
            @include('pdf.inspection-report.partials.evidence-gallery', ['evidenceGroups' => $hse['photoGroups']])
        @endif
    </div>
</div>
@endif
