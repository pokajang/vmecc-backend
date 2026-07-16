@if ($isHighAngleInspection && count($highAngleChecks) > 0)
<div class="card">
    <div class="card-head">High Angle Rescue Equipment Checks</div>
    <div class="card-body">
        <div class="meta-grid" style="margin-bottom: 8px;">
            <div class="meta-cell" style="width: 50%;">
                <div class="meta-label">Inspected By</div>
                <div class="meta-value">{{ $highAngleInspectedBy !== '' ? $highAngleInspectedBy : '--' }}</div>
                @if ($inspectedByRole !== '')
                    <div class="person-meta">{{ $inspectedByRole }}</div>
                @endif
            </div>
            <div class="meta-cell" style="width: 50%;">
                <div class="meta-label">Inspection Date</div>
                <div class="meta-value">{{ $highAngleInspectionDate !== '' ? $highAngleInspectionDate : '--' }}</div>
            </div>
        </div>
        @foreach ($highAngleGroups as $group)
            <div class="text-block-label" style="margin: {{ $loop->first ? '0' : '10px' }} 0 4px; font-weight: 700; color: #374151;">
                {{ $group['title'] }}
            </div>
            <table class="hydraulic-checks">
                <thead>
                    <tr>
                        <th style="width: 8%;">Row</th>
                        <th style="width: 16%;">Storage</th>
                        <th style="width: 16%;">Compartment</th>
                        <th style="width: 28%;">Equipment</th>
                        <th style="width: 10%;">Quantity</th>
                        <th style="width: 10%;">Condition</th>
                        <th style="width: 12%;">Remarks</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($group['rows'] as $check)
                        @php
                            $condition = trim((string) ($check['condition'] ?? ''));
                            $issueRemarks = trim((string) ($check['conditionRemarks'] ?? $check['condition_remarks'] ?? $check['remarks'] ?? ''));
                            $issuePhotos = $filterInlinePhotos($check['conditionPhotos'] ?? $check['condition_photos'] ?? []);
                            if (count($issuePhotos) === 0) {
                                $issuePhotos = $filterInlinePhotos($check['photos'] ?? []);
                            }
                            $equipmentName = trim((string) ($check['equipment'] ?? '')) ?: 'High Angle equipment';
                            $additionalNotes = trim((string) ($check['additionalNotes'] ?? $check['additional_notes'] ?? ''));
                            $additionalPhotos = $filterInlinePhotos($check['additionalPhotos'] ?? $check['additional_photos'] ?? []);
                            $hasIssue = strcasecmp($condition, 'Not Good') === 0;
                            $issueTitle = 'Issue Evidence: '.$equipmentName;
                            $additionalTitle = 'Additional Info: '.$equipmentName;
                            $checkCompactBlocks = [];
                            $checkTextBlocks = [];
                            $checkEvidenceGroups = [];
                            if ($hasIssue && count($issuePhotos) > 0) {
                                $checkEvidenceGroups[] = ['kind' => 'Issue', 'title' => $issueTitle, 'remarks' => $issueRemarks, 'photos' => $issuePhotos, 'alt' => 'High Angle issue photo'];
                            } elseif ($hasIssue && $isCompactText($issueRemarks)) {
                                $checkCompactBlocks[] = $compactBlock($issueTitle, 'Condition remarks', $issueRemarks);
                            } elseif ($hasIssue && $issueRemarks !== '') {
                                $checkTextBlocks[] = ['title' => $issueTitle, 'label' => 'Condition remarks', 'value' => $issueRemarks];
                            }
                            if (count($additionalPhotos) > 0) {
                                $checkEvidenceGroups[] = ['kind' => 'Additional', 'title' => $additionalTitle, 'remarks' => $additionalNotes, 'remarksLabel' => 'General equipment remarks', 'photos' => $additionalPhotos, 'alt' => 'High Angle additional photo'];
                            } elseif ($isCompactText($additionalNotes)) {
                                $checkCompactBlocks[] = $compactBlock($additionalTitle, 'General equipment remarks', $additionalNotes);
                            } elseif ($additionalNotes !== '') {
                                $checkTextBlocks[] = ['title' => $additionalTitle, 'label' => 'General equipment remarks', 'value' => $additionalNotes];
                            }
                        @endphp
                        <tr>
                            <td>{{ trim((string) ($check['rowNumber'] ?? $check['row_number'] ?? '')) ?: '--' }}</td>
                            <td>{{ trim((string) ($check['location'] ?? '')) ?: '--' }}</td>
                            <td>{{ trim((string) ($check['subLocation'] ?? $check['sub_location'] ?? '')) ?: '--' }}</td>
                            <td>{{ trim((string) ($check['equipment'] ?? '')) ?: '--' }}</td>
                            <td>{{ trim((string) ($check['quantity'] ?? '')) ?: '--' }}</td>
                            <td>{{ trim((string) ($check['condition'] ?? '')) ?: '--' }}</td>
                            <td>{{ trim((string) ($check['conditionRemarks'] ?? $check['condition_remarks'] ?? $check['remarks'] ?? '')) ?: '--' }}</td>
                        </tr>
                        @include('pdf.inspection-report.partials.inline-check-evidence', ['checkEvidenceColspan' => 7])
                    @endforeach
                </tbody>
            </table>
        @endforeach
    </div>
</div>
@endif
