<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Inspection Report {{ (string) ($record['displayId'] ?? '') }}</title>
    <style>
        @page { size: A4; margin: 14mm 14mm 16mm 14mm; }
        * { box-sizing: border-box; }
        body {
            font-family: Helvetica, Arial, sans-serif;
            color: #111827;
            font-size: 10px;
            line-height: 1.35;
            margin: 0;
        }
        .header {
            display: table;
            width: 100%;
            margin-bottom: 10px;
            border-bottom: 2px solid #0b948f;
            padding-bottom: 8px;
        }
        .header-left { display: table-cell; vertical-align: bottom; }
        .header-right { display: table-cell; vertical-align: bottom; text-align: right; }
        .report-title {
            font-size: 15px;
            font-weight: 700;
            color: #0b948f;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }
        .report-subtitle { font-size: 9px; color: #6b7280; margin-top: 1px; }
        .report-id { font-size: 13px; font-weight: 700; color: #111827; }
        .status-badge {
            display: inline-block;
            font-size: 8.5px;
            font-weight: 700;
            padding: 2px 7px;
            border-radius: 10px;
            margin-top: 3px;
            background: #dbeafe;
            color: #1e40af;
            text-transform: uppercase;
            letter-spacing: 0.06em;
        }
        .card {
            border: 1px solid #d1d5db;
            margin-bottom: 8px;
            page-break-inside: avoid;
        }
        .card-head {
            background: #f3f4f6;
            border-bottom: 1px solid #d1d5db;
            font-weight: 700;
            font-size: 9px;
            padding: 4px 8px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #374151;
        }
        .card-body { padding: 7px 8px; }
        .meta-grid {
            display: table;
            width: 100%;
            table-layout: fixed;
        }
        .meta-cell {
            display: table-cell;
            width: 33.333%;
            padding: 0 4px 5px 0;
            vertical-align: top;
        }
        .meta-cell:last-child { padding-right: 0; }
        .meta-grid-4 .meta-cell { width: 25%; }
        .meta-label { font-size: 8.5px; color: #6b7280; margin-bottom: 1px; }
        .meta-value { font-size: 10px; font-weight: 600; word-break: break-word; }
        .text-block-label { font-size: 8.5px; color: #6b7280; margin-bottom: 2px; }
        .text-block-value {
            font-size: 10px;
            line-height: 1.5;
            word-break: break-word;
            white-space: pre-wrap;
        }
        .divider { height: 1px; background: #e5e7eb; margin: 6px 0; }
        table.workflow {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }
        .workflow th, .workflow td {
            border: 1px solid #d1d5db;
            padding: 5px 8px;
            vertical-align: top;
        }
        .workflow th {
            background: #f3f4f6;
            font-weight: 700;
            font-size: 8.5px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #374151;
            text-align: left;
            width: 33.333%;
        }
        .workflow td { min-height: 36px; font-size: 9px; }
        .pending { color: #9ca3af; font-style: italic; font-size: 8.5px; }
        .person-name { font-weight: 600; font-size: 9.5px; color: #111827; }
        .person-meta { font-size: 8.5px; color: #6b7280; margin-top: 2px; }
        .person-remarks {
            font-size: 8.5px;
            color: #4b5563;
            margin-top: 3px;
            line-height: 1.35;
            white-space: pre-wrap;
            word-break: break-word;
        }
        .photo-grid {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
            margin-top: 2px;
        }
        .photo-grid td {
            width: 50%;
            vertical-align: top;
            padding: 4px;
        }
        .photo-card {
            border: none;
            border-radius: 0;
            overflow: visible;
            page-break-inside: avoid;
        }
        .photo-figure {
            display: inline-block;
            max-width: 100%;
        }
        .photo-image-wrap {
            background: transparent;
            text-align: left;
            padding: 6px 0 0;
        }
        .photo-image {
            max-width: 100%;
            max-height: 180px;
            width: auto;
            height: auto;
            display: block;
            margin: 0;
            vertical-align: top;
        }
        .photo-caption {
            padding: 5px 0 0;
            border-top: none;
        }
        .photo-description {
            margin-top: 2px;
            font-size: 8.5px;
            color: #4b5563;
            line-height: 1.35;
            white-space: pre-wrap;
            word-break: break-word;
        }
        .footer {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            height: 12mm;
            border-top: 1px solid #e5e7eb;
            display: table;
            width: 100%;
            padding: 4px 14mm;
        }
        .footer-left { display: table-cell; vertical-align: middle; font-size: 7.5px; color: #9ca3af; }
        .footer-right { display: table-cell; vertical-align: middle; text-align: right; font-size: 7.5px; color: #9ca3af; }
    </style>
</head>
<body>

@php
    $displayId = (string) ($record['displayId'] ?? '-');
    $status = (string) ($record['status'] ?? 'Submitted');
    $inspectionType = trim((string) ($record['incidentType'] ?? ''));
    $location = trim((string) ($record['location'] ?? $record['selectedLocation'] ?? ''));
    $description = (string) ($record['description'] ?? '');
    $submittedBy = trim((string) ($record['submittedBy'] ?? ''));
    $submittedAtRaw = trim((string) ($record['submittedAt'] ?? ''));
    $timeline = is_array($record['timeline'] ?? null) ? $record['timeline'] : [];
    $photos = is_array($record['photos'] ?? null) ? $record['photos'] : [];

    $photos = array_values(array_filter($photos, function ($photo) {
        if (!is_array($photo)) return false;
        $url = trim((string) ($photo['url'] ?? ''));
        if ($url === '') return false;
        return (bool) preg_match('/^data:image\/[a-z0-9.+-]+;base64,/i', $url);
    }));
    $photoColumns = count($photos) > 1 ? 2 : 1;

    $submittedEntry = collect($timeline)->first(function ($entry) {
        $action = strtolower(trim((string) ($entry['action'] ?? '')));
        return $action === 'submitted' || $action === 'resubmitted';
    });
    $reviewedEntry = collect($timeline)->first(function ($entry) {
        $action = strtolower(trim((string) ($entry['action'] ?? '')));
        return in_array($action, ['reviewed', 'review', 'checked'], true);
    });
    $approvedEntry = collect($timeline)->first(function ($entry) {
        $action = strtolower(trim((string) ($entry['action'] ?? '')));
        return in_array($action, ['approved', 'approve'], true);
    });

    $fmtDateTime = function ($value) {
        $raw = trim((string) $value);
        if ($raw === '') return '';
        try {
            return \Carbon\Carbon::parse($raw)->format('d M Y, H:i');
        } catch (\Throwable) {
            return $raw;
        }
    };

    $submittedAt = $submittedAtRaw !== '' ? $fmtDateTime($submittedAtRaw) : '';
    if ($submittedAt === '' && is_array($submittedEntry)) {
        $submittedAt = $fmtDateTime($submittedEntry['at'] ?? '');
    }
    if ($submittedBy === '' && is_array($submittedEntry)) {
        $submittedBy = trim((string) ($submittedEntry['by'] ?? ''));
    }

    $generatedAt = now()->format('d M Y, H:i');
@endphp

<div class="footer">
    <div class="footer-left">Inspection Report - {{ $displayId }}</div>
    <div class="footer-right">Generated {{ $generatedAt }}</div>
</div>

<div class="header">
    <div class="header-left">
        <div class="report-title">Inspection Report</div>
        <div class="report-subtitle">By Vale Mineral Malaysia Emergency Control Center (VMECC)</div>
    </div>
    <div class="header-right">
        <div class="report-id">{{ $displayId }}</div>
        <div><span class="status-badge">{{ $status }}</span></div>
    </div>
</div>

<div class="card">
    <div class="card-head">Inspection Overview</div>
    <div class="card-body">
        <div class="meta-grid meta-grid-4">
            <div class="meta-cell">
                <div class="meta-label">Inspection Type</div>
                <div class="meta-value">{{ $inspectionType ?: '--' }}</div>
            </div>
            <div class="meta-cell">
                <div class="meta-label">Location</div>
                <div class="meta-value">{{ $location ?: '--' }}</div>
            </div>
            <div class="meta-cell">
                <div class="meta-label">Submitted</div>
                <div class="meta-value">{{ $submittedAt ?: '--' }}</div>
            </div>
            <div class="meta-cell">
                <div class="meta-label">Submitted By</div>
                <div class="meta-value">{{ $submittedBy ?: '--' }}</div>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-head">Inspection Description</div>
    <div class="card-body">
        <div class="text-block-label">Summary</div>
        <div class="text-block-value">{{ trim($description) !== '' ? $description : 'No description provided.' }}</div>
    </div>
</div>

<div class="card">
    <div class="card-head">Photographs ({{ count($photos) }})</div>
    <div class="card-body">
        @if (!count($photos))
            <div class="text-block-value">No photos uploaded.</div>
        @else
            @php $figureIndex = 0; @endphp
            <table class="photo-grid">
                @foreach (array_chunk($photos, $photoColumns) as $photoRow)
                    <tr>
                        @foreach ($photoRow as $photo)
                            @php
                                $figureIndex++;
                                $description = trim((string) ($photo['description'] ?? ''));
                                if ($description === '') {
                                    $description = 'Image description not provided by user';
                                }
                                $description = preg_replace('/\s+/u', ' ', trim($description));
                                if ($description !== '') {
                                    $descriptionLower = mb_strtolower($description, 'UTF-8');
                                    $description = mb_strtoupper(mb_substr($descriptionLower, 0, 1, 'UTF-8'), 'UTF-8')
                                        . mb_substr($descriptionLower, 1, null, 'UTF-8');
                                }
                                if (!preg_match('/[.!?]$/u', $description)) {
                                    $description .= '.';
                                }
                            @endphp
                            <td style="width: {{ $photoColumns === 1 ? '100%' : '50%' }};">
                                <div class="photo-card">
                                    <div class="photo-figure">
                                        <div class="photo-image-wrap">
                                            <img class="photo-image" src="{{ trim((string) ($photo['url'] ?? '')) }}" alt="Inspection photo">
                                        </div>
                                        <div class="photo-caption">
                                            <div class="photo-description">Figure {{ $figureIndex }}. {{ $description }}</div>
                                        </div>
                                    </div>
                                </div>
                            </td>
                        @endforeach
                        @if ($photoColumns === 2 && count($photoRow) === 1)
                            <td></td>
                        @endif
                    </tr>
                @endforeach
            </table>
        @endif
    </div>
</div>

<div class="card">
    <div class="card-head">Workflow Sign-offs</div>
    <div class="card-body" style="padding:0;">
        <table class="workflow">
            <thead>
                <tr>
                    <th>Prepared By</th>
                    <th>Reviewed By</th>
                    <th>Approved By</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>
                        @if (is_array($submittedEntry))
                            @php
                                $preparedBy = trim((string) ($submittedEntry['by'] ?? $submittedBy));
                                $preparedAt = $fmtDateTime($submittedEntry['at'] ?? $submittedAtRaw);
                                $preparedRemarks = trim((string) ($submittedEntry['remarks'] ?? ''));
                            @endphp
                            @if ($preparedBy !== '')
                                <div class="person-name">{{ $preparedBy }}</div>
                            @endif
                            @if ($preparedAt !== '')
                                <div class="person-meta">{{ $preparedAt }}</div>
                            @endif
                            @if ($preparedRemarks !== '')
                                <div class="person-remarks">Remarks: {{ $preparedRemarks }}</div>
                            @endif
                        @elseif ($submittedBy !== '' || $submittedAt !== '')
                            @if ($submittedBy !== '')
                                <div class="person-name">{{ $submittedBy }}</div>
                            @endif
                            @if ($submittedAt !== '')
                                <div class="person-meta">{{ $submittedAt }}</div>
                            @endif
                        @else
                            <span class="pending">Pending</span>
                        @endif
                    </td>
                    <td>
                        @if (is_array($reviewedEntry))
                            @php
                                $reviewedBy = trim((string) ($reviewedEntry['by'] ?? ''));
                                $reviewedAt = $fmtDateTime($reviewedEntry['at'] ?? '');
                                $reviewedRemarks = trim((string) ($reviewedEntry['remarks'] ?? ''));
                            @endphp
                            @if ($reviewedBy !== '')
                                <div class="person-name">{{ $reviewedBy }}</div>
                            @endif
                            @if ($reviewedAt !== '')
                                <div class="person-meta">{{ $reviewedAt }}</div>
                            @endif
                            @if ($reviewedRemarks !== '')
                                <div class="person-remarks">Remarks: {{ $reviewedRemarks }}</div>
                            @endif
                        @else
                            <span class="pending">Pending</span>
                        @endif
                    </td>
                    <td>
                        @if (is_array($approvedEntry))
                            @php
                                $approvedBy = trim((string) ($approvedEntry['by'] ?? ''));
                                $approvedAt = $fmtDateTime($approvedEntry['at'] ?? '');
                                $approvedRemarks = trim((string) ($approvedEntry['remarks'] ?? ''));
                            @endphp
                            @if ($approvedBy !== '')
                                <div class="person-name">{{ $approvedBy }}</div>
                            @endif
                            @if ($approvedAt !== '')
                                <div class="person-meta">{{ $approvedAt }}</div>
                            @endif
                            @if ($approvedRemarks !== '')
                                <div class="person-remarks">Remarks: {{ $approvedRemarks }}</div>
                            @endif
                        @else
                            <span class="pending">Pending</span>
                        @endif
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

</body>
</html>
