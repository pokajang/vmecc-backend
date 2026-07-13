@if ($isHydraulicInspection && count($hydraulicChecks) > 0)
<div class="card">
    <div class="card-head">Hydraulic Equipment Checks</div>
    <div class="card-body">
        <table class="hydraulic-checks">
            <thead>
                <tr>
                    <th style="width: 22%;">Equipment</th>
                    <th style="width: 10%;">Location</th>
                    <th style="width: 12%;">Physical</th>
                    <th style="width: 12%;">Mechanical</th>
                    <th style="width: 12%;">Leakage</th>
                    <th style="width: 12%;">Function</th>
                    <th style="width: 20%;">Remarks</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($hydraulicChecks as $check)
                    @php
                        $equipmentDescription = trim((string) ($check['equipmentDescription'] ?? $check['equipment_description'] ?? ''));
                    @endphp
                    <tr>
                        <td>
                            {{ trim((string) ($check['equipment'] ?? '')) ?: '--' }}
                            @if (($check['equipmentSource'] ?? $check['equipment_source'] ?? '') === 'custom' || ($check['isCustomEquipment'] ?? $check['is_custom_equipment'] ?? false))
                                <span class="pill">Custom</span>
                            @endif
                            @if ($equipmentDescription !== '')
                                <div style="margin-top: 3px; color: #6b7280; font-size: 10px; line-height: 1.35;">{{ $equipmentDescription }}</div>
                            @endif
                        </td>
                        <td>{{ trim((string) ($check['location'] ?? '')) ?: '--' }}</td>
                        <td>{{ trim((string) ($check['physicalCondition'] ?? $check['physical_condition'] ?? '')) ?: '--' }}</td>
                        <td>{{ trim((string) ($check['mechanicalCondition'] ?? $check['mechanical_condition'] ?? '')) ?: '--' }}</td>
                        <td>{{ trim((string) ($check['noLeakage'] ?? $check['no_leakage'] ?? '')) ?: '--' }}</td>
                        <td>{{ trim((string) ($check['functionTest'] ?? $check['function_test'] ?? '')) ?: '--' }}</td>
                        <td>{{ trim((string) ($check['remarks'] ?? '')) ?: '--' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        @php
            $compactBlocks = [];
            $evidenceGroups = [];
        @endphp
        @foreach ($hydraulicChecks as $check)
            @php
                $equipmentName = trim((string) ($check['equipment'] ?? '')) ?: 'Hydraulic equipment';
                $equipmentPhotos = $filterInlinePhotos($check['photos'] ?? []);
                $equipmentRemarks = trim((string) ($check['remarks'] ?? ''));
            @endphp
            @foreach ($hydraulicCheckFields as $field)
                @php
                    $statusValue = trim((string) ($check[$field['status']] ?? $check[$field['status_snake']] ?? ''));
                    $defectRemarks = trim((string) ($check[$field['remarks']] ?? $check[$field['remarks_snake']] ?? ''));
                    $defectPhotos = $filterInlinePhotos($check[$field['photos']] ?? $check[$field['photos_snake']] ?? []);
                    $defectTitle = 'Defect Evidence: '.$equipmentName.' - '.$field['label'];
                    $naTitle = 'N/A Reason: '.$equipmentName.' - '.$field['label'];
                    $compactDefectOnly = strcasecmp($statusValue, 'Defect') === 0 && count($defectPhotos) === 0 && $isCompactText($defectRemarks);
                    $compactNaOnly = strcasecmp($statusValue, 'N/A') === 0 && $isCompactText($defectRemarks);
                    if ($compactDefectOnly) {
                        $compactBlocks[] = $compactBlock($defectTitle, 'Defect remarks', $defectRemarks);
                    }
                    if ($compactNaOnly) {
                        $compactBlocks[] = $compactBlock($naTitle, 'Reason', $defectRemarks);
                    }
                    if (strcasecmp($statusValue, 'Defect') === 0 && count($defectPhotos) > 0) {
                        $evidenceGroups[] = [
                            'kind' => 'Defect',
                            'title' => $defectTitle,
                            'remarks' => $defectRemarks,
                            'photos' => $defectPhotos,
                            'alt' => 'Hydraulic defect photo',
                        ];
                    }
                @endphp
                @if (strcasecmp($statusValue, 'Defect') === 0 && ! $compactDefectOnly && count($defectPhotos) === 0 && $defectRemarks !== '')
                    <div class="text-block-label" style="margin-top: 10px;">
                        {{ $defectTitle }}
                    </div>
                    <div class="text-block-value">{{ $defectRemarks }}</div>
                @endif
                @if (strcasecmp($statusValue, 'N/A') === 0 && ! $compactNaOnly && $defectRemarks !== '')
                    <div class="text-block-label" style="margin-top: 10px;">
                        {{ $naTitle }}
                    </div>
                    <div class="text-block-value">{{ $defectRemarks }}</div>
                @endif
            @endforeach
            @php
                if (count($equipmentPhotos) > 0) {
                    $evidenceGroups[] = [
                        'kind' => 'Equipment',
                        'title' => 'Equipment Evidence: '.$equipmentName,
                        'remarks' => $equipmentRemarks,
                        'remarksLabel' => 'General equipment remarks',
                        'photos' => $equipmentPhotos,
                        'alt' => 'Hydraulic equipment photo',
                    ];
                }
            @endphp
        @endforeach
        {!! $renderCompactBlocks($compactBlocks) !!}
        @include('pdf.inspection-report.partials.evidence-gallery', ['evidenceGroups' => $evidenceGroups])
    </div>
</div>
@endif
