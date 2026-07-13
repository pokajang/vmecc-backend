@if ($isFireExtinguisherInspection && count($fireExtinguisherChecks) > 0)
<div class="card">
    <div class="card-head">Fire Extinguisher Checks</div>
    <div class="card-body">
        <div class="meta-grid meta-grid-4" style="margin-bottom: 8px;">
            <div class="meta-cell">
                <div class="meta-label">Inspected By</div>
                <div class="meta-value">{{ $fireExtinguisherInspectedBy !== '' ? $fireExtinguisherInspectedBy : '--' }}</div>
                @if ($inspectedByRole !== '')
                    <div class="person-meta">{{ $inspectedByRole }}</div>
                @endif
            </div>
            <div class="meta-cell">
                <div class="meta-label">Inspection Date</div>
                <div class="meta-value">{{ $fireExtinguisherInspectionDate !== '' ? $fireExtinguisherInspectionDate : '--' }}</div>
            </div>
        </div>
        <table class="hydraulic-checks">
            <thead>
                <tr>
                    <th style="width: 14%;">ID / Barcode</th>
                    <th style="width: 12%;">Location</th>
                    <th style="width: 8%;">Type</th>
                    <th style="width: 10%;">Validity</th>
                    <th style="width: 9%;">Physical</th>
                    <th style="width: 9%;">Signage</th>
                    <th style="width: 7%;">Key</th>
                    <th style="width: 7%;">Glass</th>
                    <th style="width: 10%;">Operational</th>
                    <th style="width: 14%;">Remarks</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($fireExtinguisherChecks as $check)
                    <tr>
                        <td>
                            {{ trim((string) ($check['idLocNo'] ?? $check['id_loc_no'] ?? '')) ?: '--' }}
                            <div style="margin-top: 3px; color: #6b7280; font-size: 10px; line-height: 1.35;">
                                {{ trim((string) ($check['barcodeNo'] ?? $check['barcode_no'] ?? '')) ?: '--' }}
                            </div>
                        </td>
                        <td>{{ trim((string) ($check['subLocation'] ?? $check['sub_location'] ?? $check['mainLocation'] ?? $check['main_location'] ?? '')) ?: '--' }}</td>
                        <td>{{ trim((string) ($check['feType'] ?? $check['fe_type'] ?? '')) ?: '--' }}</td>
                        <td>{{ trim((string) ($check['certificationValidity'] ?? $check['certification_validity'] ?? $check['certificationValidityRaw'] ?? $check['certification_validity_raw'] ?? '')) ?: '--' }}</td>
                        <td>{{ trim((string) ($check['physicalCondition'] ?? $check['physical_condition'] ?? '')) ?: '--' }}</td>
                        <td>{{ trim((string) ($check['signageCondition'] ?? $check['signage_condition'] ?? '')) ?: '--' }}</td>
                        <td>{{ trim((string) ($check['boxKeyAvailability'] ?? $check['box_key_availability'] ?? '')) ?: '--' }}</td>
                        <td>{{ trim((string) ($check['boxGlassAvailability'] ?? $check['box_glass_availability'] ?? '')) ?: '--' }}</td>
                        <td>{{ trim((string) ($check['operationalCondition'] ?? $check['operational_condition'] ?? '')) ?: '--' }}</td>
                        <td>{{ trim((string) ($check['remarks'] ?? '')) ?: '--' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        @php
            $compactBlocks = [];
            $evidenceGroups = [];
        @endphp
        @foreach ($fireExtinguisherChecks as $check)
            @php
                $feName = trim((string) ($check['idLocNo'] ?? $check['id_loc_no'] ?? $check['barcodeNo'] ?? $check['barcode_no'] ?? '')) ?: 'Fire extinguisher';
                $fireFields = [
                    ['status' => 'physicalCondition', 'status_snake' => 'physical_condition', 'label' => 'FE Physical Condition', 'remarks' => 'physicalConditionRemarks', 'remarks_snake' => 'physical_condition_remarks', 'photos' => 'physicalConditionPhotos', 'photos_snake' => 'physical_condition_photos'],
                    ['status' => 'signageCondition', 'status_snake' => 'signage_condition', 'label' => 'FE Signage Condition', 'remarks' => 'signageConditionRemarks', 'remarks_snake' => 'signage_condition_remarks', 'photos' => 'signageConditionPhotos', 'photos_snake' => 'signage_condition_photos'],
                    ['status' => 'boxKeyAvailability', 'status_snake' => 'box_key_availability', 'label' => 'FE Box Key Availability', 'remarks' => 'boxKeyAvailabilityRemarks', 'remarks_snake' => 'box_key_availability_remarks', 'photos' => 'boxKeyAvailabilityPhotos', 'photos_snake' => 'box_key_availability_photos'],
                    ['status' => 'boxGlassAvailability', 'status_snake' => 'box_glass_availability', 'label' => 'FE Box Glass Availability', 'remarks' => 'boxGlassAvailabilityRemarks', 'remarks_snake' => 'box_glass_availability_remarks', 'photos' => 'boxGlassAvailabilityPhotos', 'photos_snake' => 'box_glass_availability_photos'],
                    ['status' => 'operationalCondition', 'status_snake' => 'operational_condition', 'label' => 'Operational Condition', 'remarks' => 'operationalConditionRemarks', 'remarks_snake' => 'operational_condition_remarks', 'photos' => 'operationalConditionPhotos', 'photos_snake' => 'operational_condition_photos'],
                ];
            @endphp
            @foreach ($fireFields as $field)
                @php
                    $statusValue = strtolower(trim((string) ($check[$field['status']] ?? $check[$field['status_snake']] ?? '')));
                    $remarksValue = trim((string) ($check[$field['remarks']] ?? $check[$field['remarks_snake']] ?? ''));
                    $defectPhotos = $filterInlinePhotos($check[$field['photos']] ?? $check[$field['photos_snake']] ?? []);
                    $defectTitle = 'Defect Evidence: '.$feName.' - '.$field['label'];
                    $hasDefect = in_array($statusValue, ['not good', 'no', 'not operational'], true);
                    $compactDefectOnly = $hasDefect
                        && count($defectPhotos) === 0
                        && $isCompactText($remarksValue);
                    if ($compactDefectOnly) {
                        $compactBlocks[] = $compactBlock($defectTitle, 'Defect remarks', $remarksValue);
                    }
                    if ($hasDefect && count($defectPhotos) > 0) {
                        $evidenceGroups[] = [
                            'kind' => 'Defect',
                            'title' => $defectTitle,
                            'remarks' => $remarksValue,
                            'photos' => $defectPhotos,
                            'alt' => 'Fire extinguisher defect photo',
                        ];
                    }
                @endphp
                @if ($hasDefect && ! $compactDefectOnly && count($defectPhotos) === 0 && $remarksValue !== '')
                    <div class="text-block-label" style="margin-top: 10px;">
                        {{ $defectTitle }}
                    </div>
                    <div class="text-block-value">{{ $remarksValue }}</div>
                @endif
            @endforeach
        @endforeach
        {!! $renderCompactBlocks($compactBlocks) !!}
        @include('pdf.inspection-report.partials.evidence-gallery', ['evidenceGroups' => $evidenceGroups])
    </div>
</div>
@endif
