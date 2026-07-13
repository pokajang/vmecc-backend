@if ($isFrtInspection && $hasFrtChecks)
<div class="card">
    <div class="card-head">Fire Truck Daily Readiness</div>
    <div class="card-body">
        <div class="meta-grid meta-grid-4" style="margin-bottom: 8px;">
            <div class="meta-cell">
                <div class="meta-label">Inspected By</div>
                <div class="meta-value">{{ $frtInspectedBy !== '' ? $frtInspectedBy : '--' }}</div>
                @if ($inspectedByRole !== '')
                    <div class="person-meta">{{ $inspectedByRole }}</div>
                @endif
            </div>
            <div class="meta-cell">
                <div class="meta-label">Inspection Date</div>
                <div class="meta-value">{{ $frtInspectionDate !== '' ? $frtInspectionDate : '--' }}</div>
            </div>
            <div class="meta-cell">
                <div class="meta-label">Plate No</div>
                <div class="meta-value">{{ $frtTruckPlate !== '' ? $frtTruckPlate : '--' }}</div>
            </div>
            <div class="meta-cell">
                <div class="meta-label">Truck</div>
                <div class="meta-value">{{ trim((string) ($frtTruckReference['name'] ?? $frtTruckReference['truckName'] ?? $frtTruckReference['truck_name'] ?? '')) ?: '--' }}</div>
            </div>
        </div>
        <div class="meta-grid meta-grid-4" style="margin-bottom: 8px;">
            <div class="meta-cell">
                <div class="meta-label">Truck Details</div>
                <div class="meta-value">Daily readiness</div>
            </div>
            <div class="meta-cell">
                <div class="meta-label">Road Tax Expiry</div>
                <div class="meta-value">{{ trim((string) ($frtTruckReference['roadTaxExpiry'] ?? $frtTruckReference['road_tax_expiry'] ?? '')) ?: '--' }}</div>
            </div>
            <div class="meta-cell">
                <div class="meta-label">Insurance Expiry</div>
                <div class="meta-value">{{ trim((string) ($frtTruckReference['insuranceExpiry'] ?? $frtTruckReference['insurance_expiry'] ?? '')) ?: '--' }}</div>
            </div>
            <div class="meta-cell">
                <div class="meta-label">Puspakom Expiry</div>
                <div class="meta-value">{{ trim((string) ($frtTruckReference['puspakomExpiry'] ?? $frtTruckReference['puspakom_expiry'] ?? '')) ?: '--' }}</div>
            </div>
        </div>

        @if (count($frtDailyChecks) > 0)
            <div class="text-block-label" style="margin: 0 0 4px; font-weight: 700; color: #374151;">
                Daily Readiness Roster
            </div>
            @foreach ($frtDailyGroups as $group)
                <table class="hydraulic-checks">
                    <colgroup>
                        <col style="width: 8%;">
                        <col style="width: 31%;">
                        <col style="width: 10%;">
                        <col style="width: 12%;">
                        <col style="width: 12%;">
                        <col style="width: 12%;">
                        <col style="width: 15%;">
                    </colgroup>
                    <thead>
                        <tr>
                            <th style="width: 8%;">Row</th>
                            <th style="width: 31%;">{{ $group['title'] }}<br>Equipment</th>
                            <th style="width: 10%;">Qty</th>
                            <th style="width: 12%;">Kind</th>
                            <th style="width: 12%;">Status</th>
                            <th style="width: 12%;">Reading</th>
                            <th style="width: 15%;">Remarks</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($group['rows'] as $check)
                            @php
                                $rowKind = trim((string) ($check['rowKind'] ?? $check['row_kind'] ?? 'status')) ?: 'status';
                                $rowStatus = trim((string) ($check['status'] ?? ''));
                                $readingValue = trim((string) ($check['readingValue'] ?? $check['reading_value'] ?? ''));
                            @endphp
                            <tr>
                                <td>{{ trim((string) ($check['rowNumber'] ?? $check['row_number'] ?? '')) ?: '--' }}</td>
                                <td>{{ trim((string) ($check['equipment'] ?? '')) ?: '--' }}</td>
                                <td>{{ trim((string) ($check['quantity'] ?? '')) ?: '--' }}</td>
                                <td>{{ ucfirst($rowKind) }}</td>
                                <td>{{ $rowKind === 'reading' ? '--' : ($rowStatus !== '' ? $rowStatus : '--') }}</td>
                                <td>{{ $rowKind === 'reading' ? ($readingValue !== '' ? $readingValue : '--') : '--' }}</td>
                                <td>{{ trim((string) ($check['remarks'] ?? '')) ?: '--' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                @php
                    $compactBlocks = [];
                    $evidenceGroups = [];
                @endphp
                @foreach ($group['rows'] as $check)
                    @php
                        $rowStatus = trim((string) ($check['status'] ?? ''));
                        $issuePhotos = strcasecmp($rowStatus, 'Issue') === 0 ? $filterInlinePhotos($check['photos'] ?? []) : [];
                        $issueRemarks = trim((string) ($check['remarks'] ?? ''));
                        $additionalNotes = trim((string) ($check['additionalNotes'] ?? $check['additional_notes'] ?? ''));
                        $additionalPhotos = $filterInlinePhotos($check['additionalPhotos'] ?? $check['additional_photos'] ?? []);
                        $issueTitle = 'Issue Evidence - Row '.(trim((string) ($check['rowNumber'] ?? $check['row_number'] ?? '')) ?: '--').': '.(trim((string) ($check['equipment'] ?? '')) ?: '--');
                        $additionalTitle = 'Additional Info - Row '.(trim((string) ($check['rowNumber'] ?? $check['row_number'] ?? '')) ?: '--').': '.(trim((string) ($check['equipment'] ?? '')) ?: '--');
                        $compactAdditionalOnly = count($additionalPhotos) === 0 && $isCompactText($additionalNotes);
                        if ($compactAdditionalOnly) {
                            $compactBlocks[] = $compactBlock($additionalTitle, 'General equipment remarks', $additionalNotes);
                        }
                        if (count($issuePhotos) > 0) {
                            $evidenceGroups[] = ['kind' => 'Issue', 'title' => $issueTitle, 'remarks' => $issueRemarks, 'photos' => $issuePhotos, 'alt' => 'FRT issue photo'];
                        }
                        if (count($additionalPhotos) > 0) {
                            $evidenceGroups[] = ['kind' => 'Additional', 'title' => $additionalTitle, 'remarks' => $additionalNotes, 'remarksLabel' => 'General equipment remarks', 'photos' => $additionalPhotos, 'alt' => 'FRT additional photo'];
                        }
                    @endphp
                    @if (! $compactAdditionalOnly && count($additionalPhotos) === 0 && $additionalNotes !== '')
                        <div class="text-block-label" style="margin-top: 6px; font-weight: 700; color: #374151;">
                            {{ $additionalTitle }}
                        </div>
                        <div class="text-block-label">General equipment remarks</div>
                        <div class="text-block-value">{{ $additionalNotes }}</div>
                    @endif
                @endforeach
                {!! $renderCompactBlocks($compactBlocks) !!}
                @include('pdf.inspection-report.partials.evidence-gallery', ['evidenceGroups' => $evidenceGroups])
            @endforeach
            @if ($frtDailyRemarks !== '')
                <div class="text-block-label" style="margin-top: 10px;">Daily Remarks</div>
                <div class="text-block-value">{{ $frtDailyRemarks }}</div>
            @endif
        @endif

        @if (count($frtOneOffChecks) > 0)
            <div class="text-block-label" style="margin: {{ count($frtDailyChecks) > 0 ? '10px' : '0' }} 0 4px; font-weight: 700; color: #374151;">
                One-Off Readiness Checklist
            </div>
            @foreach ($frtOneOffGroups as $group)
                <table class="hydraulic-checks">
                    <colgroup>
                        <col style="width: 8%;">
                        <col style="width: 47%;">
                        <col style="width: 15%;">
                        <col style="width: 30%;">
                    </colgroup>
                    <thead>
                        <tr>
                            <th style="width: 8%;">Row</th>
                            <th style="width: 47%;">{{ $group['title'] }}<br>Equipment</th>
                            <th style="width: 15%;">Condition</th>
                            <th style="width: 30%;">Remarks</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($group['rows'] as $check)
                            <tr>
                                <td>{{ trim((string) ($check['rowNumber'] ?? $check['row_number'] ?? '')) ?: '--' }}</td>
                                <td>{{ trim((string) ($check['equipment'] ?? '')) ?: '--' }}</td>
                                <td>{{ trim((string) ($check['condition'] ?? '')) ?: '--' }}</td>
                                <td>{{ trim((string) ($check['remarks'] ?? '')) ?: '--' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                @php
                    $compactBlocks = [];
                    $evidenceGroups = [];
                @endphp
                @foreach ($group['rows'] as $check)
                    @php
                        $condition = trim((string) ($check['condition'] ?? ''));
                        $issuePhotos = strcasecmp($condition, 'Not Good') === 0 ? $filterInlinePhotos($check['photos'] ?? []) : [];
                        $issueRemarks = trim((string) ($check['remarks'] ?? ''));
                        $additionalNotes = trim((string) ($check['additionalNotes'] ?? $check['additional_notes'] ?? ''));
                        $additionalPhotos = $filterInlinePhotos($check['additionalPhotos'] ?? $check['additional_photos'] ?? []);
                        $issueTitle = 'Issue Evidence - Row '.(trim((string) ($check['rowNumber'] ?? $check['row_number'] ?? '')) ?: '--').': '.(trim((string) ($check['equipment'] ?? '')) ?: '--');
                        $additionalTitle = 'Additional Info - Row '.(trim((string) ($check['rowNumber'] ?? $check['row_number'] ?? '')) ?: '--').': '.(trim((string) ($check['equipment'] ?? '')) ?: '--');
                        $compactAdditionalOnly = count($additionalPhotos) === 0 && $isCompactText($additionalNotes);
                        if ($compactAdditionalOnly) {
                            $compactBlocks[] = $compactBlock($additionalTitle, 'General equipment remarks', $additionalNotes);
                        }
                        if (count($issuePhotos) > 0) {
                            $evidenceGroups[] = ['kind' => 'Issue', 'title' => $issueTitle, 'remarks' => $issueRemarks, 'photos' => $issuePhotos, 'alt' => 'FRT one-off issue photo'];
                        }
                        if (count($additionalPhotos) > 0) {
                            $evidenceGroups[] = ['kind' => 'Additional', 'title' => $additionalTitle, 'remarks' => $additionalNotes, 'remarksLabel' => 'General equipment remarks', 'photos' => $additionalPhotos, 'alt' => 'FRT one-off additional photo'];
                        }
                    @endphp
                    @if (! $compactAdditionalOnly && count($additionalPhotos) === 0 && $additionalNotes !== '')
                        <div class="text-block-label" style="margin-top: 6px; font-weight: 700; color: #374151;">
                            {{ $additionalTitle }}
                        </div>
                        <div class="text-block-label">General equipment remarks</div>
                        <div class="text-block-value">{{ $additionalNotes }}</div>
                    @endif
                @endforeach
                {!! $renderCompactBlocks($compactBlocks) !!}
                @include('pdf.inspection-report.partials.evidence-gallery', ['evidenceGroups' => $evidenceGroups])
            @endforeach
            @if ($frtOneOffRemarks !== '')
                <div class="text-block-label" style="margin-top: 10px;">One-off Remarks</div>
                <div class="text-block-value">{{ $frtOneOffRemarks }}</div>
            @endif
        @endif
    </div>
</div>
@endif
