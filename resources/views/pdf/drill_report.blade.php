<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Drill Report {{ (string) ($record['displayId'] ?? '') }}</title>
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
        .report-header {
            display: table;
            width: 100%;
            margin-bottom: 10px;
            border-bottom: 2px solid #007e7a;
            padding-bottom: 8px;
        }
        .report-header-left { display: table-cell; vertical-align: bottom; }
        .report-header-right { display: table-cell; vertical-align: bottom; text-align: right; }
        .report-type-label {
            font-size: 15px;
            font-weight: 700;
            color: #007e7a;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }
        .report-sub-label { font-size: 9px; color: #6b7280; margin-top: 1px; }
        .report-id { font-size: 13px; font-weight: 700; color: #111827; }
        .status-badge {
            display: inline-block;
            font-size: 8.5px;
            font-weight: 700;
            padding: 2px 7px;
            border-radius: 10px;
            margin-top: 3px;
            background: #d1fae5;
            color: #065f46;
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
        .meta-grid { display: table; width: 100%; table-layout: fixed; }
        .meta-cell {
            display: table-cell;
            width: 25%;
            padding: 0 4px 5px 0;
            vertical-align: top;
        }
        .meta-grid-3 .meta-cell { width: 33.333%; }
        .meta-label { font-size: 8.5px; color: #6b7280; margin-bottom: 1px; }
        .meta-value { font-size: 10px; font-weight: 600; word-break: break-word; }
        .text-block { margin-bottom: 6px; }
        .text-block-label { font-size: 8.5px; color: #6b7280; margin-bottom: 2px; }
        .text-block-value {
            font-size: 10px;
            line-height: 1.5;
            word-break: break-word;
            white-space: pre-wrap;
        }
        .scenario-title {
            font-size: 11px;
            font-weight: 700;
            color: #111827;
            line-height: 1.4;
        }
        .divider { height: 1px; background: #e5e7eb; margin: 6px 0; }
        table.chrono, table.signoff {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }
        .chrono th, .chrono td, .signoff th, .signoff td {
            border: 1px solid #d1d5db;
            padding: 5px 8px;
            vertical-align: top;
        }
        .chrono th, .signoff th {
            background: #f3f4f6;
            font-weight: 700;
            font-size: 8.5px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #374151;
            text-align: left;
        }
        .chrono td { font-size: 9.5px; }
        .chrono .col-time { width: 14%; }
        .chrono .col-action { width: 86%; }
        .signoff th, .signoff td { width: 33.333%; }
        .signoff td { height: 38px; font-size: 9px; }
        .pending { color: #9ca3af; font-style: italic; font-size: 8.5px; }
        .signer-name { font-weight: 600; font-size: 9.5px; color: #111827; }
        .signer-meta { font-size: 8.5px; color: #6b7280; margin-top: 2px; }
        .signer-remarks {
            font-size: 8.5px;
            color: #4b5563;
            margin-top: 3px;
            line-height: 1.35;
            white-space: pre-wrap;
            word-break: break-word;
        }
        .report-footer {
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
    $reportDate = (string) ($record['reportDate'] ?? $record['incidentDate'] ?? '');
    $reportTime = (string) ($record['reportTime'] ?? $record['incidentTime'] ?? '');
    $condition = (string) ($record['weather'] ?? '');
    $drillType = (string) ($record['incidentType'] ?? '');
    $location = trim((string) ($record['location'] ?? ''));
    $details = (string) ($record['details'] ?? $record['description'] ?? '');
    $summary = (string) ($record['summary'] ?? '');
    $chronology = is_array($record['chronology'] ?? null) ? $record['chronology'] : [];
    $chronology = array_filter($chronology, fn($r) => !empty($r['time']) || !empty($r['action']));

    $timeline = is_array($record['timeline'] ?? null) ? $record['timeline'] : [];
    $submittedEntry = collect($timeline)->first(fn($e) => strtolower((string)($e['action'] ?? '')) === 'submitted');
    $checkedEntry = collect($timeline)->first(fn($e) => in_array(strtolower((string)($e['action'] ?? '')), ['checked', 'reviewed', 'review'], true));
    $approvedEntry = collect($timeline)->first(fn($e) => in_array(strtolower((string)($e['action'] ?? '')), ['approved', 'approve'], true));
    $rejectedEntry = collect($timeline)->first(fn($e) => in_array(strtolower((string)($e['action'] ?? '')), ['rejected', 'reject'], true));

    $dateDisplay = '';
    if ($reportDate) {
        try {
            $dateDisplay = \Carbon\Carbon::parse($reportDate)->format('d M Y');
        } catch (\Throwable) {
            $dateDisplay = $reportDate;
        }
    }
    $timeDisplay = $reportTime ? substr($reportTime, 0, 5) : '';
    $dateTimeDisplay = trim($dateDisplay . ($timeDisplay ? ', ' . $timeDisplay : ''));
    $generatedAt = now()->format('d M Y, H:i');

    $renderSigner = function ($entry) {
        if (! $entry) {
            return '<span class="pending">Pending</span>';
        }
        $name = e(trim((string)($entry['by'] ?? '')));
        $remarks = trim((string)($entry['remarks'] ?? ''));
        $at = '';
        try {
            $at = \Carbon\Carbon::parse((string)($entry['at'] ?? ''))->format('d M Y, H:i');
        } catch (\Throwable) {
        }
        $html = $name ? '<div class="signer-name">'.$name.'</div>' : '';
        $html .= $at ? '<div class="signer-meta">'.e($at).'</div>' : '';
        $html .= $remarks !== '' ? '<div class="signer-remarks">Remarks: '.e($remarks).'</div>' : '';
        return $html ?: '<span class="pending">Pending</span>';
    };
@endphp

<div class="report-footer">
    <div class="footer-left">Drill Report - {{ $displayId }}</div>
    <div class="footer-right">Generated {{ $generatedAt }}</div>
</div>

<div class="report-header">
    <div class="report-header-left">
        <div class="report-type-label">Drill Report</div>
        <div class="report-sub-label">By Vale Mineral Malaysia Emergency Control Center (VMECC)</div>
    </div>
    <div class="report-header-right">
        <div class="report-id">{{ $displayId }}</div>
        <div><span class="status-badge">{{ $status }}</span></div>
    </div>
</div>

<div class="card">
    <div class="card-head">Drill Overview</div>
    <div class="card-body">
        <div class="meta-grid meta-grid-3">
            <div class="meta-cell">
                <div class="meta-label">Date &amp; Time</div>
                <div class="meta-value">{{ $dateTimeDisplay ?: '--' }}</div>
            </div>
            <div class="meta-cell">
                <div class="meta-label">Drill Type</div>
                <div class="meta-value">{{ $drillType ?: '--' }}</div>
            </div>
            <div class="meta-cell">
                <div class="meta-label">Condition</div>
                <div class="meta-value">{{ $condition ?: '--' }}</div>
            </div>
        </div>
        <div class="divider"></div>
        <div class="meta-grid meta-grid-3">
            <div class="meta-cell">
                <div class="meta-label">Location</div>
                <div class="meta-value">{{ $location ?: '--' }}</div>
            </div>
        </div>
    </div>
</div>

@if ($details || $summary)
<div class="card">
    <div class="card-head">Drill Details</div>
    <div class="card-body">
        @if ($details)
            <div class="text-block">
                <div class="text-block-label">Drill Scenario</div>
                <div class="scenario-title">{{ $details }}</div>
            </div>
        @endif
        @if ($summary)
            @if ($details)<div class="divider"></div>@endif
            <div class="text-block">
                <div class="text-block-label">Outcome Summary</div>
                <div class="text-block-value">{{ $summary }}</div>
            </div>
        @endif
    </div>
</div>
@endif

@if (count($chronology))
<div class="card">
    <div class="card-head">Chronology of Drill Actions</div>
    <div class="card-body" style="padding:0;">
        <table class="chrono">
            <thead>
                <tr>
                    <th class="col-time">Time</th>
                    <th class="col-action">Event / Action</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($chronology as $row)
                <tr>
                    <td class="col-time">{{ trim((string)($row['time'] ?? '')) ?: '--' }}</td>
                    <td class="col-action">{{ trim((string)($row['action'] ?? '')) ?: '--' }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endif

<div class="card">
    <div class="card-head">Sign-offs</div>
    <div class="card-body" style="padding:0;">
        <table class="signoff">
            <thead>
                <tr>
                    <th>Prepared By</th>
                    <th>Checked By</th>
                    <th>Approved By</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>{!! $renderSigner($submittedEntry) !!}</td>
                    <td>{!! $renderSigner($checkedEntry) !!}</td>
                    <td>{!! $renderSigner($approvedEntry) !!}</td>
                </tr>
            </tbody>
        </table>
        @if ($rejectedEntry)
            <div style="padding:8px;">
                <div class="text-block-label">Rejected By</div>
                {!! $renderSigner($rejectedEntry) !!}
            </div>
        @endif
    </div>
</div>
</body>
</html>
