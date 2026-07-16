@if ($isScbaInspection && $hasScbaChecks)
<div class="card">
    <div class="card-head">SCBA Checks</div>
    <div class="card-body">
        <div class="meta-grid" style="margin-bottom: 8px;">
            <div class="meta-cell" style="width: 50%;">
                <div class="meta-label">Inspected By</div>
                <div class="meta-value">{{ $scbaInspectedBy !== '' ? $scbaInspectedBy : '--' }}</div>
                @if ($inspectedByRole !== '')
                    <div class="person-meta">{{ $inspectedByRole }}</div>
                @endif
            </div>
            <div class="meta-cell" style="width: 50%;">
                <div class="meta-label">Inspection Date</div>
                <div class="meta-value">{{ $scbaInspectionDate !== '' ? $scbaInspectionDate : '--' }}</div>
            </div>
        </div>
        @foreach ($scbaSections as $section)
            @if (count($section['rows']) > 0)
                <div class="text-block-label" style="margin: {{ $loop->first ? '0' : '10px' }} 0 4px; font-weight: 700; color: #374151;">
                    {{ $section['title'] }}
                </div>
                <table class="hydraulic-checks">
                    <thead>
                        <tr>
                            @foreach ($section['columns'] as $column)
                                <th>{{ $column['label'] }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($section['rows'] as $check)
                            @php
                                $brand = trim((string) ($check['brand'] ?? ''));
                                $serialNo = trim((string) ($check['serialNo'] ?? $check['serial_no'] ?? ''));
                                $equipmentName = trim($brand.' '.$serialNo) ?: 'SCBA item';
                                $generalRemarks = trim((string) ($check['remarks'] ?? $check['remark'] ?? ''));
                                $additionalPhotos = $filterInlinePhotos($check['photos'] ?? []);
                                $checkCompactBlocks = [];
                                $checkTextBlocks = [];
                                $checkEvidenceGroups = [];
                                if (count($additionalPhotos) > 0) {
                                    $checkEvidenceGroups[] = ['kind' => 'Equipment', 'title' => 'Equipment Evidence: '.$equipmentName, 'remarks' => $generalRemarks, 'remarksLabel' => 'General equipment remarks', 'photos' => $additionalPhotos, 'alt' => 'SCBA equipment photo'];
                                } elseif ($isCompactText($generalRemarks)) {
                                    $checkCompactBlocks[] = $compactBlock('Equipment Evidence: '.$equipmentName, 'General equipment remarks', $generalRemarks);
                                } elseif ($generalRemarks !== '') {
                                    $checkTextBlocks[] = ['title' => 'Equipment Evidence: '.$equipmentName, 'label' => 'General equipment remarks', 'value' => $generalRemarks];
                                }
                                foreach ($section['status_fields'] as $field) {
                                    $statusValue = trim((string) ($check[$field['status']] ?? $check[$field['status_snake']] ?? ''));
                                    $issueRemarks = trim((string) ($check[$field['remarks']] ?? $check[$field['remarks_snake']] ?? ''));
                                    $issuePhotos = $filterInlinePhotos($check[$field['photos']] ?? $check[$field['photos_snake']] ?? []);
                                    $issueTitle = 'Issue Evidence: '.$equipmentName.' - '.$field['label'];
                                    if (strcasecmp($statusValue, 'Not Good') !== 0) {
                                        continue;
                                    }
                                    if (count($issuePhotos) > 0) {
                                        $checkEvidenceGroups[] = ['kind' => 'Issue', 'title' => $issueTitle, 'remarks' => $issueRemarks, 'photos' => $issuePhotos, 'alt' => 'SCBA issue photo'];
                                    } elseif ($isCompactText($issueRemarks)) {
                                        $checkCompactBlocks[] = $compactBlock($issueTitle, 'Issue remarks', $issueRemarks);
                                    } elseif ($issueRemarks !== '') {
                                        $checkTextBlocks[] = ['title' => $issueTitle, 'label' => 'Issue remarks', 'value' => $issueRemarks];
                                    }
                                }
                            @endphp
                            <tr>
                                @foreach ($section['columns'] as $column)
                                    @php
                                        $value = trim((string) ($check[$column['camel']] ?? $check[$column['snake']] ?? ''));
                                    @endphp
                                    <td>{{ $value !== '' ? $value : '--' }}</td>
                                @endforeach
                            </tr>
                            @include('pdf.inspection-report.partials.inline-check-evidence', ['checkEvidenceColspan' => count($section['columns'])])
                        @endforeach
                    </tbody>
                </table>
            @endif
        @endforeach
    </div>
</div>
@endif
