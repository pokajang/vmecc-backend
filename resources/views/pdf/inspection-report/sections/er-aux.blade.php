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
                        $equipmentName = trim((string) ($check['equipment'] ?? '')) ?: 'ER Aux equipment';
                        $defectTitle = 'Defect Evidence: '.$equipmentName;
                        $additionalTitle = 'Additional Evidence: '.$equipmentName;
                        $additionalRemarks = $additionalNotes !== '' ? $additionalNotes : $remarks;
                        $checkCompactBlocks = [];
                        $checkTextBlocks = [];
                        $checkEvidenceGroups = [];
                        if (count($defectPhotos) > 0) {
                            $checkEvidenceGroups[] = ['kind' => 'Defect', 'title' => $defectTitle, 'remarks' => $defectRemarks, 'photos' => $defectPhotos, 'alt' => 'ER Aux defect photo'];
                        } elseif ($isCompactText($defectRemarks)) {
                            $checkCompactBlocks[] = $compactBlock($defectTitle, 'Defect remarks', $defectRemarks);
                        } elseif ($defectRemarks !== '') {
                            $checkTextBlocks[] = ['title' => $defectTitle, 'label' => 'Defect remarks', 'value' => $defectRemarks];
                        }
                        if (count($additionalPhotos) > 0) {
                            $checkEvidenceGroups[] = ['kind' => 'Additional', 'title' => $additionalTitle, 'remarks' => $additionalRemarks, 'remarksLabel' => 'General equipment remarks', 'photos' => $additionalPhotos, 'alt' => 'ER Aux additional photo'];
                        } elseif ($isCompactText($additionalRemarks)) {
                            $checkCompactBlocks[] = $compactBlock($additionalTitle, 'General equipment remarks', $additionalRemarks);
                        } elseif ($additionalRemarks !== '') {
                            $checkTextBlocks[] = ['title' => $additionalTitle, 'label' => 'General equipment remarks', 'value' => $additionalRemarks];
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
                        <td>{{ trim((string) ($check['displayLocation'] ?? $check['display_location'] ?? $check['location'] ?? '')) ?: '--' }}</td>
                        <td>{{ $quantity !== '' ? $quantity : '--' }}</td>
                        <td>{{ $condition !== '' ? $condition : '--' }}</td>
                        <td>{{ count($erAuxEvidence) > 0 ? implode('; ', $erAuxEvidence) : '--' }}</td>
                    </tr>
                    @include('pdf.inspection-report.partials.inline-check-evidence', ['checkEvidenceColspan' => 5])
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endif
