<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $data['title'] ?? 'Fire Extinguisher Exception Report' }}</title>
    @include('pdf.inspection-report.styles')
    <style>
        .exception-header { margin-bottom: 10px; border-bottom: 2px solid #0b948f; padding-bottom: 8px; }
        .exception-title { color: #0b948f; font-size: 15px; font-weight: 700; text-transform: uppercase; letter-spacing: .035em; }
        .exception-subtitle { color: #6b7280; font-size: 8.5px; margin-top: 2px; }
        .summary-table { border-collapse: collapse; table-layout: fixed; width: 100%; margin: 8px 0; }
        .summary-table th, .summary-table td { border: 1px solid #d1d5db; padding: 5px 7px; text-align: center; width: 25%; }
        .summary-table th { background: #f3f4f6; color: #4b5563; font-size: 8px; text-transform: uppercase; }
        .summary-table td { color: #111827; font-size: 12px; font-weight: 700; }
        .filter-line { color: #4b5563; font-size: 8px; margin: 5px 0 10px; }
        .group-zone { color: #0b948f; font-size: 12px; font-weight: 700; margin: 12px 0 4px; page-break-after: avoid; text-transform: uppercase; }
        .group-location { color: #374151; font-size: 10px; font-weight: 700; margin: 7px 0 4px; page-break-after: avoid; }
        .exception-item { border: 1px solid #d1d5db; margin-bottom: 9px; page-break-inside: auto; }
        .exception-item-head { background: #f3f4f6; border-bottom: 1px solid #d1d5db; padding: 5px 7px; page-break-after: avoid; }
        .exception-item-title { display: inline-block; font-size: 10px; font-weight: 700; width: 68%; }
        .exception-badges { display: inline-block; text-align: right; width: 30%; }
        .exception-badge { display: inline-block; background: #fee4e2; border: 1px solid #fecdca; border-radius: 8px; color: #b42318; font-size: 7px; font-weight: 700; margin-left: 3px; padding: 1px 5px; }
        .exception-item-body { padding: 6px 7px; }
        .exception-meta { border-collapse: collapse; table-layout: fixed; width: 100%; page-break-inside: avoid; }
        .exception-meta td { padding: 2px 6px 3px 0; vertical-align: top; width: 33.333%; }
        .exception-register { border-collapse: collapse; table-layout: fixed; width: 100%; margin-top: 8px; }
        .exception-register thead { display: table-header-group; }
        .exception-register th, .exception-register td { border: 1px solid #d1d5db; padding: 4px 4px; vertical-align: top; word-break: break-word; }
        .exception-register th { background: #e5e7eb; color: #374151; font-size: 6.7px; font-weight: 700; line-height: 1.2; text-align: left; text-transform: uppercase; }
        .exception-register td { color: #111827; font-size: 7.2px; line-height: 1.3; }
        .exception-register-parent { page-break-inside: avoid; }
        .exception-register-parent.has-detail { page-break-after: avoid; }
        .exception-register-parent:nth-child(even) td { background: #fafafa; }
        .exception-register-index { color: #6b7280; text-align: center; }
        .exception-register-value { font-weight: 600; }
        .exception-register-secondary { color: #6b7280; font-size: 6.7px; margin-top: 1px; }
        .exception-register-status { margin-top: 2px; }
        .exception-register-detail { page-break-inside: avoid; }
        .exception-register-detail.has-evidence { page-break-after: avoid; }
        .exception-register-detail > td { background: #f8fafc; border-top: 0; padding: 0; }
        .exception-register-defect { border-left: 3px solid #b42318; padding: 6px 8px 7px 10px; page-break-inside: avoid; }
        .exception-register-evidence { border-left: 3px solid #b42318; padding: 1px 8px 5px 10px; page-break-inside: avoid; }
        .exception-defect { background: #f8fafc; border-left: 3px solid #b42318; margin-top: 7px; padding: 6px 7px; page-break-inside: auto; }
        .exception-defect-title { font-size: 9px; font-weight: 700; margin-bottom: 3px; page-break-after: avoid; }
        .exception-defect-status { color: #b42318; font-size: 7px; font-weight: 700; margin-left: 4px; }
        .exception-finding { color: #374151; font-size: 8.5px; line-height: 1.4; page-break-inside: avoid; white-space: pre-wrap; }
        .exception-empty { border: 1px solid #d1d5db; color: #6b7280; padding: 16px; text-align: center; }
    </style>
</head>
<body>
@php
    $summary = is_array($data['summary'] ?? null) ? $data['summary'] : [];
    $filters = is_array($data['appliedFilters'] ?? null) ? $data['appliedFilters'] : [];
    $items = is_array($data['items'] ?? null) ? $data['items'] : [];
    $layoutMode = in_array(($data['layoutMode'] ?? ''), ['issues', 'expired', 'combined'], true)
        ? $data['layoutMode']
        : (in_array('expired', (array) ($data['categories'] ?? []), true) ? 'expired' : 'issues');
    $lastZone = null;
    $lastLocation = null;
@endphp

<div class="exception-header">
    <div class="exception-title">{{ $data['title'] ?? 'Fire Extinguisher Exception Report' }}</div>
    <div class="exception-subtitle">
        Generated {{ $data['generatedAtDisplay'] ?? '' }} by {{ $data['generatedBy'] ?? '' }}.
        Certification status as of {{ $data['asOfDateDisplay'] ?? '' }}.
    </div>
</div>

<table class="summary-table">
    <tr><th>Unique</th><th>Issues</th><th>Expired</th><th>Both</th></tr>
    <tr>
        <td>{{ (int) ($summary['total'] ?? 0) }}</td>
        <td>{{ (int) ($summary['issues'] ?? 0) }}</td>
        <td>{{ (int) ($summary['expired'] ?? 0) }}</td>
        <td>{{ (int) ($summary['overlap'] ?? 0) }}</td>
    </tr>
</table>

@if (count($filters) > 0)
    <div class="filter-line"><strong>Applied filters:</strong>
        {{ implode(' | ', array_map(fn ($filter) => (string) ($filter['label'] ?? ''), $filters)) }}
    </div>
@endif

@if ($layoutMode !== 'issues')
    <table class="exception-register">
        <colgroup>
            <col style="width: 4.4%">
            <col style="width: 8.3%">
            <col style="width: 10%">
            <col style="width: 11.7%">
            <col style="width: 10%">
            <col style="width: 7.8%">
            <col style="width: 16.7%">
            <col style="width: 11.1%">
            <col style="width: 7.2%">
            <col style="width: 12.8%">
        </colgroup>
        <thead>
            <tr>
                <th>#</th>
                <th>Zone</th>
                <th>Location</th>
                <th>
                    ID Loc No.
                    @if ($layoutMode === 'combined')
                        / Status
                    @endif
                </th>
                <th>Sub-location</th>
                <th>FE type</th>
                <th>Barcode / S/N</th>
                <th>Certification validity</th>
                <th>Days expired</th>
                <th>Latest inspection / Inspector</th>
            </tr>
        </thead>
        @forelse ($items as $item)
            <tbody class="exception-register-record">
            <tr class="exception-register-parent{{ $layoutMode === 'combined' && ($item['isIssue'] ?? false) ? ' has-detail' : '' }}">
                <td class="exception-register-index">{{ $loop->iteration }}</td>
                <td>{{ trim((string) ($item['zone'] ?? '')) ?: '-' }}</td>
                <td>{{ trim((string) ($item['location'] ?? '')) ?: '-' }}</td>
                <td>
                    <div class="exception-register-value">{{ trim((string) ($item['idLocNo'] ?? '')) ?: '-' }}</div>
                    @if ($layoutMode === 'combined')
                        <div class="exception-register-status">
                            @if ($item['isExpired'] ?? false)<span class="exception-badge">EXPIRED</span>@endif
                            @if ($item['isIssue'] ?? false)<span class="exception-badge">ISSUE</span>@endif
                        </div>
                    @endif
                </td>
                <td>{{ trim((string) ($item['subLocation'] ?? '')) ?: '-' }}</td>
                <td>{{ trim((string) ($item['feType'] ?? '')) ?: '-' }}</td>
                <td>{{ trim((string) ($item['barcodeNo'] ?? '')) ?: '-' }}</td>
                <td>{{ trim((string) ($item['certificationValidity'] ?? '')) ?: '-' }}</td>
                <td>{{ ($item['isExpired'] ?? false) ? (int) ($item['daysExpired'] ?? 0) : '-' }}</td>
                <td>
                    <div>{{ !empty($item['latestInspectionAt']) ? \Illuminate\Support\Carbon::parse($item['latestInspectionAt'])->format('d M Y, H:i') : '-' }}</div>
                    <div class="exception-register-secondary">{{ trim((string) ($item['inspectedBy'] ?? '')) ?: '-' }}</div>
                </td>
            </tr>
            @if ($layoutMode === 'combined' && ($item['isIssue'] ?? false))
                @forelse ((array) ($item['defects'] ?? []) as $check)
                    @php
                        $checkPhotos = array_values(array_filter(
                            is_array($check['photos'] ?? null) ? $check['photos'] : [],
                            fn ($photo) => is_array($photo)
                                && (($photo['imageUnavailable'] ?? false) === true
                                    || preg_match('/^data:image\/[a-z0-9.+-]+;base64,/i', trim((string) ($photo['url'] ?? ''))) === 1),
                        ));
                    @endphp
                    <tr class="exception-register-detail{{ count($checkPhotos) > 0 ? ' has-evidence' : '' }}">
                        <td colspan="10">
                            <div class="exception-register-defect">
                                <div class="exception-defect-title">{{ $check['label'] ?? 'Inspection check' }}<span class="exception-defect-status">ISSUE</span></div>
                                <div class="meta-label">Finding</div>
                                <div class="exception-finding">{{ trim((string) ($check['remarks'] ?? '')) ?: 'No finding description provided.' }}</div>
                            </div>
                        </td>
                    </tr>
                    @foreach (array_chunk($checkPhotos, 2) as $photoRow)
                        <tr class="exception-register-detail exception-register-detail--evidence">
                            <td colspan="10">
                                <div class="exception-register-evidence">
                                    @include('pdf.inspection-report.partials.evidence-gallery', [
                                        'evidenceGroups' => [[
                                            'kind' => 'Defect evidence',
                                            'title' => (string) ($check['label'] ?? 'Inspection check'),
                                            'remarks' => '',
                                            'photos' => $photoRow,
                                            'alt' => 'Fire extinguisher defect evidence',
                                        ]],
                                    ])
                                </div>
                            </td>
                        </tr>
                    @endforeach
                @empty
                    <tr class="exception-register-detail">
                        <td colspan="10"><div class="exception-register-defect exception-finding">Issue details are unavailable.</div></td>
                    </tr>
                @endforelse
            @endif
            </tbody>
        @empty
            <tbody><tr><td colspan="10" class="exception-empty">No extinguishers matched the selected export categories.</td></tr></tbody>
        @endforelse
    </table>
@else
    @forelse ($items as $item)
        @php
            $zone = trim((string) ($item['zone'] ?? '')) ?: 'Unspecified zone';
            $location = trim((string) ($item['location'] ?? '')) ?: 'Unspecified location';
        @endphp
        @if ($zone !== $lastZone)
            <div class="group-zone">{{ $zone }}</div>
            @php $lastZone = $zone; $lastLocation = null; @endphp
        @endif
        @if ($location !== $lastLocation)
            <div class="group-location">{{ $location }}</div>
            @php $lastLocation = $location; @endphp
        @endif

        <div class="exception-item">
            <div class="exception-item-head">
                <span class="exception-item-title">{{ trim((string) ($item['idLocNo'] ?? '')) ?: 'Unnumbered extinguisher' }}</span>
                <span class="exception-badges"><span class="exception-badge">ISSUE</span></span>
            </div>
            <div class="exception-item-body">
                <table class="exception-meta">
                    <tr>
                        <td><div class="meta-label">Sub-location</div><div class="meta-value">{{ $item['subLocation'] ?: '-' }}</div></td>
                        <td><div class="meta-label">FE type</div><div class="meta-value">{{ $item['feType'] ?: '-' }}</div></td>
                        <td><div class="meta-label">Barcode / S/N</div><div class="meta-value">{{ $item['barcodeNo'] ?: '-' }}</div></td>
                    </tr>
                    <tr>
                        <td><div class="meta-label">Certification validity</div><div class="meta-value">{{ $item['certificationValidity'] ?: '-' }}</div></td>
                        <td><div class="meta-label">Latest inspection</div><div class="meta-value">{{ $item['latestInspectionAt'] ? \Illuminate\Support\Carbon::parse($item['latestInspectionAt'])->format('d M Y, H:i') : '-' }}</div></td>
                        <td><div class="meta-label">Inspector</div><div class="meta-value">{{ $item['inspectedBy'] ?: '-' }}</div></td>
                    </tr>
                </table>

                @foreach ((array) ($item['defects'] ?? []) as $check)
                    <div class="exception-defect">
                        <div class="exception-defect-title">{{ $check['label'] ?? 'Inspection check' }}<span class="exception-defect-status">ISSUE</span></div>
                        <div class="meta-label">Finding</div>
                        <div class="exception-finding">{{ trim((string) ($check['remarks'] ?? '')) ?: 'No finding description provided.' }}</div>
                        @include('pdf.inspection-report.partials.evidence-gallery', [
                            'evidenceGroups' => [[
                                'kind' => 'Defect evidence',
                                'title' => (string) ($check['label'] ?? 'Inspection check'),
                                'remarks' => '',
                                'photos' => is_array($check['photos'] ?? null) ? $check['photos'] : [],
                                'alt' => 'Fire extinguisher defect evidence',
                            ]],
                        ])
                    </div>
                @endforeach
            </div>
        </div>
    @empty
        <div class="exception-empty">No extinguishers matched the selected export categories.</div>
    @endforelse
@endif
</body>
</html>
