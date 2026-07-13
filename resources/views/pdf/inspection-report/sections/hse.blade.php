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
                <div class="meta-label">Inspection Date</div>
                <div class="meta-value">{{ $hseInspectionDate !== '' ? $hseInspectionDate : '--' }}</div>
            </div>
            <div class="meta-cell">
                <div class="meta-label">Severity</div>
                <div class="meta-value">{{ $hseSeverity !== '' ? $hseSeverity : '--' }}</div>
            </div>
            <div class="meta-cell">
                <div class="meta-label">Outcome</div>
                <div class="meta-value">
                    @if (count($hseSelections) > 0)
                        @foreach ($hseSelections as $selection)
                            <span class="pill">{{ $hseSelectionLabels[$selection] ?? $selection }}</span>
                        @endforeach
                    @else
                        --
                    @endif
                </div>
            </div>
        </div>

        @foreach ($hseSelections as $selection)
            @php
                $field = $hseDetailFields[$selection] ?? null;
                $value = $field ? trim((string) ($record[$field['camel']] ?? $record[$field['snake']] ?? '')) : '';
            @endphp
            @if ($field && $value !== '')
                <div class="divider"></div>
                <div class="text-block-label">{{ $field['label'] }}</div>
                <div class="text-block-value">{{ $value }}</div>
            @endif
        @endforeach

        @foreach ($hseOptionalFields as $field)
            @php
                $value = trim((string) ($record[$field['camel']] ?? $record[$field['snake']] ?? ''));
            @endphp
            @if ($value !== '')
                <div class="divider"></div>
                <div class="text-block-label">{{ $field['label'] }}</div>
                <div class="text-block-value">{{ $value }}</div>
            @endif
        @endforeach
    </div>
</div>
@endif
