@if ($isErAuxInspection && count($erAuxChecks) > 0)
<div class="card">
    <div class="card-head">ER Aux Equipment Checks</div>
    <div class="card-body">
        <div class="meta-grid" style="margin-bottom: 8px;">
            <div class="meta-cell" style="width: 50%;">
                <div class="meta-label">Inspected By</div>
                <div class="meta-value">{{ $erAuxInspectedBy !== '' ? $erAuxInspectedBy : '--' }}</div>
                @if ($inspectedByRole !== '')
                    <div class="person-meta">{{ $inspectedByRole }}</div>
                @endif
            </div>
            <div class="meta-cell" style="width: 50%;">
                <div class="meta-label">Inspection Date</div>
                <div class="meta-value">{{ $erAuxInspectionDate !== '' ? $erAuxInspectionDate : '--' }}</div>
            </div>
        </div>
        <table class="hydraulic-checks">
            <thead>
                <tr>
                    <th style="width: 28%;">Equipment</th>
                    <th style="width: 14%;">Location</th>
                    <th style="width: 12%;">Quantity</th>
                    <th style="width: 12%;">Condition</th>
                    <th style="width: 34%;">Remarks</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($erAuxChecks as $check)
                    @php
                        $equipmentDescription = trim((string) ($check['equipmentDescription'] ?? $check['equipment_description'] ?? ''));
                        $quantity = trim((string) ($check['quantity'] ?? $check['qty'] ?? $check['defaultQuantity'] ?? $check['default_quantity'] ?? ''));
                        $condition = trim((string) ($check['condition'] ?? ''));
                        $remarks = trim((string) ($check['remarks'] ?? $check['remark'] ?? ''));
                        $defectRemarks = trim((string) ($check['defectRemarks'] ?? $check['defect_remarks'] ?? ''));
                        $additionalNotes = trim((string) ($check['additionalNotes'] ?? $check['additional_notes'] ?? ''));
                        $defectPhotos = $filterInlinePhotos($check['defectPhotos'] ?? $check['defect_photos'] ?? []);
                        $additionalPhotos = $filterInlinePhotos($check['photos'] ?? []);
                        $erAuxEvidence = [];
                        if ($defectRemarks !== '') {
                            $erAuxEvidence[] = 'Defect: '.$defectRemarks;
                        }
                        if ($additionalNotes !== '') {
                            $erAuxEvidence[] = 'Additional: '.$additionalNotes;
                        } elseif ($remarks !== '') {
                            $erAuxEvidence[] = $remarks;
                        }
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
                        <td>{{ $quantity !== '' ? $quantity : '--' }}</td>
                        <td>{{ $condition !== '' ? $condition : '--' }}</td>
                        <td>{{ count($erAuxEvidence) > 0 ? implode('; ', $erAuxEvidence) : '--' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        @php
            $compactBlocks = [];
            $evidenceGroups = [];
        @endphp
        @foreach ($erAuxChecks as $check)
            @php
                $equipmentName = trim((string) ($check['equipment'] ?? '')) ?: 'ER Aux equipment';
                $defectRemarks = trim((string) ($check['defectRemarks'] ?? $check['defect_remarks'] ?? ''));
                $defectPhotos = $filterInlinePhotos($check['defectPhotos'] ?? $check['defect_photos'] ?? []);
                $additionalRemarks = trim((string) ($check['additionalNotes'] ?? $check['additional_notes'] ?? $check['remarks'] ?? $check['remark'] ?? ''));
                $additionalPhotos = $filterInlinePhotos($check['photos'] ?? []);
                $defectTitle = 'Defect Evidence: '.$equipmentName;
                $additionalTitle = 'Additional Evidence: '.$equipmentName;
                $compactDefectOnly = count($defectPhotos) === 0 && $isCompactText($defectRemarks);
                $compactAdditionalOnly = count($additionalPhotos) === 0 && $isCompactText($additionalRemarks);
                if ($compactDefectOnly) {
                    $compactBlocks[] = $compactBlock($defectTitle, 'Defect remarks', $defectRemarks);
                }
                if ($compactAdditionalOnly) {
                    $compactBlocks[] = $compactBlock($additionalTitle, 'General equipment remarks', $additionalRemarks);
                }
                if (count($defectPhotos) > 0) {
                    $evidenceGroups[] = [
                        'kind' => 'Defect',
                        'title' => $defectTitle,
                        'remarks' => $defectRemarks,
                        'photos' => $defectPhotos,
                        'alt' => 'ER Aux defect photo',
                    ];
                }
                if (count($additionalPhotos) > 0) {
                    $evidenceGroups[] = [
                        'kind' => 'Additional',
                        'title' => $additionalTitle,
                        'remarks' => $additionalRemarks,
                        'remarksLabel' => 'General equipment remarks',
                        'photos' => $additionalPhotos,
                        'alt' => 'ER Aux additional photo',
                    ];
                }
            @endphp
            @if (! $compactDefectOnly && count($defectPhotos) === 0 && $defectRemarks !== '')
                <div class="text-block-label" style="margin-top: 10px;">
                    {{ $defectTitle }}
                </div>
                <div class="text-block-value">{{ $defectRemarks }}</div>
            @endif
            @if (! $compactAdditionalOnly && count($additionalPhotos) === 0 && $additionalRemarks !== '')
                <div class="text-block-label" style="margin-top: 10px;">
                    {{ $additionalTitle }}
                </div>
                <div class="text-block-value">{{ $additionalRemarks }}</div>
            @endif
        @endforeach
        {!! $renderCompactBlocks($compactBlocks) !!}
        @include('pdf.inspection-report.partials.evidence-gallery', ['evidenceGroups' => $evidenceGroups])
    </div>
</div>
@endif
