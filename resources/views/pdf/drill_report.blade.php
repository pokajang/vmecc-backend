<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Drill Report {{ (string) ($record['displayId'] ?? '') }}</title>
    <style>
        @page { size: A4; margin: 13mm 13mm 17mm 13mm; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            color: #111827;
            font-family: Helvetica, Arial, sans-serif;
            font-size: 9.5px;
            line-height: 1.38;
        }
        .report-header {
            display: table;
            table-layout: fixed;
            width: 100%;
            margin-bottom: 9px;
            padding-bottom: 7px;
            border-bottom: 2px solid #007e7a;
        }
        .report-header-left, .report-header-right { display: table-cell; vertical-align: bottom; }
        .report-header-right { text-align: right; }
        .report-type-label {
            color: #007e7a;
            font-size: 15px;
            font-weight: 700;
            letter-spacing: .04em;
            text-transform: uppercase;
        }
        .report-sub-label { margin-top: 1px; color: #6b7280; font-size: 8px; }
        .report-id { color: #111827; font-size: 12px; font-weight: 700; }
        .status-badge, .value-badge {
            display: inline-block;
            border-radius: 10px;
            font-weight: 700;
        }
        .status-badge {
            margin-top: 3px;
            padding: 2px 7px;
            background: #d1fae5;
            color: #065f46;
            font-size: 8px;
            letter-spacing: .05em;
            text-transform: uppercase;
        }
        .value-badge {
            margin: 0 3px 3px 0;
            padding: 2px 6px;
            border: 1px solid #cbd5e1;
            background: #f8fafc;
            color: #334155;
            font-size: 8.5px;
            font-weight: 400;
        }
        .card { margin-bottom: 7px; border: 1px solid #d1d5db; }
        .keep-together { page-break-inside: avoid; }
        .card-head {
            padding: 4px 7px;
            border-bottom: 1px solid #d1d5db;
            background: #f3f4f6;
            color: #374151;
            font-size: 8.5px;
            font-weight: 700;
            letter-spacing: .05em;
            text-transform: uppercase;
        }
        .card-body { padding: 6px 7px; }
        .meta-grid { display: table; width: 100%; table-layout: fixed; }
        .meta-cell { display: table-cell; width: 25%; padding: 0 5px 4px 0; vertical-align: top; }
        .meta-grid-3 .meta-cell { width: 33.333%; }
        .meta-label, .text-block-label { margin-bottom: 1px; color: #6b7280; font-size: 8px; }
        .meta-value { font-size: 9.5px; font-weight: 600; overflow-wrap: break-word; }
        .text-block { margin-bottom: 6px; }
        .text-block:last-child { margin-bottom: 0; }
        .text-block-value { white-space: pre-wrap; overflow-wrap: break-word; }
        .scenario-title { font-size: 10.5px; font-weight: 700; line-height: 1.4; }
        .divider { height: 1px; margin: 5px 0; background: #e5e7eb; }
        ul.compact-list { margin: 2px 0 0; padding-left: 17px; }
        ul.compact-list li { margin-bottom: 2px; }
        table.data-table, table.signoff, table.photo-grid {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }
        .data-table thead { display: table-header-group; }
        .data-table tr { page-break-inside: avoid; }
        .data-table th, .data-table td, .signoff th, .signoff td {
            padding: 4px 6px;
            border: 1px solid #d1d5db;
            vertical-align: top;
            overflow-wrap: break-word;
        }
        .data-table th, .signoff th {
            background: #f3f4f6;
            color: #374151;
            font-size: 8px;
            font-weight: 700;
            letter-spacing: .04em;
            text-align: left;
            text-transform: uppercase;
        }
        .chronology-time { width: 14%; }
        .person-name { width: 24%; }
        .person-role { width: 24%; }
        .person-exercise-role { width: 16%; }
        .signoff th, .signoff td { width: 33.333%; }
        .signoff td { height: 38px; font-size: 8.5px; }
        .pending { color: #9ca3af; font-size: 8px; font-style: italic; }
        .signer-name { color: #111827; font-size: 9px; font-weight: 600; }
        .signer-meta { margin-top: 2px; color: #6b7280; font-size: 8px; }
        .signer-remarks { margin-top: 3px; color: #4b5563; font-size: 8px; white-space: pre-wrap; }
        .photo-grid td {
            width: 50%;
            padding: 4px;
            border: 1px solid #e5e7eb;
            vertical-align: top;
        }
        .photo-section { page-break-before: always; }
        .photo-unit { page-break-inside: avoid; }
        .photo-image {
            display: block;
            width: 100%;
            max-height: 72mm;
            object-fit: contain;
            border: 1px solid #d1d5db;
            background: #f8fafc;
        }
        .photo-description { margin-top: 3px; color: #374151; font-size: 8px; white-space: pre-wrap; }
        .report-footer {
            position: fixed;
            right: 0;
            bottom: -11mm;
            left: 0;
            display: block;
            width: 100%;
            height: 9mm;
            padding-top: 3px;
            border-top: 1px solid #e5e7eb;
            color: #9ca3af;
            font-size: 7px;
        }
        .footer-content { text-align: right; }
        .page-number::after { content: counter(page); }
    </style>
</head>
<body>
@php
    $text = function ($value): string {
        if (is_array($value)) {
            return implode(', ', array_values(array_filter(array_map(
                fn ($item) => trim((string) $item),
                $value,
            ))));
        }
        return trim((string) ($value ?? ''));
    };
    $textList = function ($value) use ($text): array {
        if (! is_array($value)) {
            return $text($value) !== '' ? [$text($value)] : [];
        }
        return array_values(array_filter(array_map(function ($item) use ($text): string {
            return is_array($item) ? $text($item['text'] ?? $item['value'] ?? '') : $text($item);
        }, $value), fn ($item) => $item !== ''));
    };
    $formatDate = function ($value) use ($text): string {
        $value = $text($value);
        if ($value === '') return '';
        try {
            return \Carbon\Carbon::parse($value)->format('d M Y');
        } catch (\Throwable) {
            return $value;
        }
    };
    $formatDateTime = function ($value) use ($text): string {
        $value = $text($value);
        if ($value === '') return '';
        try {
            return \Carbon\Carbon::parse($value)->format('d M Y, H:i');
        } catch (\Throwable) {
            return $value;
        }
    };

    $displayId = $text($record['displayId'] ?? '-');
    $status = $text($record['status'] ?? 'Submitted');
    $reportDate = $formatDate($record['reportDate'] ?? $record['incidentDate'] ?? '');
    $reportTime = substr($text($record['reportTime'] ?? $record['incidentTime'] ?? ''), 0, 5);
    $issuanceDate = $formatDate($record['reportIssuanceDate'] ?? $record['report_issuance_date'] ?? '');
    $condition = $text($record['weather'] ?? '');
    $drillType = $text($record['incidentType'] ?? '');
    $categories = $textList($record['exerciseCategories'] ?? $record['exercise_categories'] ?? []);
    $location = $text($record['location'] ?? '');
    $exerciseTitle = $text($record['exerciseTitle'] ?? $record['exercise_title'] ?? '');
    $details = $text($record['details'] ?? $record['description'] ?? '');
    $summary = $text($record['summary'] ?? '');
    $objectives = $textList($record['exerciseObjectives'] ?? $record['exercise_objectives'] ?? []);

    $erpReferences = array_values(array_filter(
        is_array($record['erpReferences'] ?? null) ? $record['erpReferences'] : [],
        fn ($row) => is_array($row) && ($text($row['annexNumber'] ?? '') !== '' || $text($row['title'] ?? '') !== ''),
    ));

    $respondingTeam = is_array($record['respondingTeam'] ?? null) ? $record['respondingTeam'] : [];
    $personnel = is_array($respondingTeam['attendance'] ?? null)
        ? array_values(array_filter($respondingTeam['attendance'], fn ($row) => is_array($row)))
        : [];
    if ($personnel === []) {
        if ($text($record['sc'] ?? '') !== '') $personnel[] = ['name' => $text($record['sc']), 'exerciseRole' => 'SC'];
        if ($text($record['asc'] ?? '') !== '') $personnel[] = ['name' => $text($record['asc']), 'exerciseRole' => 'ASC'];
    }

    $chronology = array_values(array_filter(
        is_array($record['chronology'] ?? null) ? $record['chronology'] : [],
        fn ($row) => is_array($row) && ($text($row['time'] ?? '') !== '' || $text($row['action'] ?? '') !== ''),
    ));
    $analysis = is_array($record['postIncidentAnalysis'] ?? null) ? $record['postIncidentAnalysis'] : [];
    $strengths = $textList($analysis['strengths'] ?? []);
    $resources = $textList($analysis['resourcesMobilised'] ?? $analysis['resourcesMobilized'] ?? []);
    $improvements = $textList($analysis['improvementOpportunities'] ?? $analysis['improvements'] ?? []);
    $photoSource = is_array($analysis['photos'] ?? null) && $analysis['photos'] !== []
        ? $analysis['photos']
        : (is_array($record['photos'] ?? null) ? $record['photos'] : []);
    $photos = array_values(array_filter($photoSource, function ($photo) use ($text): bool {
        return is_array($photo) && str_starts_with($text($photo['url'] ?? ''), 'data:image/');
    }));

    $timeline = is_array($record['timeline'] ?? null) ? $record['timeline'] : [];
    $currentRevision = isset($record['revision']) ? (int) $record['revision'] : null;
    $revisionTimeline = $currentRevision === null
        ? $timeline
        : array_values(array_filter($timeline, fn ($entry) => (int) ($entry['revision'] ?? 0) === $currentRevision));
    $latestAction = function (array $actions) use ($revisionTimeline) {
        $matches = array_values(array_filter($revisionTimeline, function ($entry) use ($actions): bool {
            return is_array($entry) && in_array(strtolower(trim((string) ($entry['action'] ?? ''))), $actions, true);
        }));
        return $matches === [] ? null : $matches[array_key_last($matches)];
    };
    $submittedEntry = $latestAction(['submitted', 'resubmitted']);
    $reviewedEntry = $latestAction(['checked', 'reviewed', 'review']);
    $approvedEntry = $latestAction(['approved', 'approve']);
    $rejectedEntry = $latestAction(['rejected', 'reject']);

    $renderSigner = function ($entry) use ($formatDateTime, $text): string {
        if (! is_array($entry)) return '<span class="pending">Pending</span>';
        $name = e($text($entry['by'] ?? ''));
        $at = e($formatDateTime($entry['at'] ?? ''));
        $remarks = e($text($entry['remarks'] ?? ''));
        $html = $name !== '' ? '<div class="signer-name">'.$name.'</div>' : '';
        $html .= $at !== '' ? '<div class="signer-meta">'.$at.'</div>' : '';
        $html .= $remarks !== '' ? '<div class="signer-remarks">Remarks: '.$remarks.'</div>' : '';
        return $html !== '' ? $html : '<span class="pending">Pending</span>';
    };
@endphp

<div class="report-footer">
    <div class="footer-content">Page <span class="page-number"></span></div>
</div>

<div class="report-header">
    <div class="report-header-left">
        <div class="report-type-label">Drill Report</div>
        <div class="report-sub-label">Vale Mineral Malaysia Emergency Control Center (VMECC)</div>
    </div>
    <div class="report-header-right">
        <div class="report-id">{{ $displayId }}</div>
        <span class="status-badge">{{ $status }}</span>
    </div>
</div>

<div class="card keep-together">
    <div class="card-head">Exercise Overview</div>
    <div class="card-body">
        <div class="meta-grid">
            <div class="meta-cell"><div class="meta-label">Exercise Date</div><div class="meta-value">{{ $reportDate ?: '--' }}</div></div>
            <div class="meta-cell"><div class="meta-label">Start Time</div><div class="meta-value">{{ $reportTime ?: '--' }}</div></div>
            <div class="meta-cell"><div class="meta-label">Report Issuance Date</div><div class="meta-value">{{ $issuanceDate ?: '--' }}</div></div>
            <div class="meta-cell"><div class="meta-label">Condition</div><div class="meta-value">{{ $condition ?: '--' }}</div></div>
        </div>
        <div class="divider"></div>
        <div class="meta-grid meta-grid-3">
            <div class="meta-cell"><div class="meta-label">Primary Drill Type</div><div class="meta-value">{{ $drillType ?: '--' }}</div></div>
            <div class="meta-cell"><div class="meta-label">Location / Area</div><div class="meta-value">{{ $location ?: '--' }}</div></div>
            <div class="meta-cell"><div class="meta-label">Responding Team</div><div class="meta-value">{{ $text($respondingTeam['name'] ?? '') ?: '--' }}{{ $text($respondingTeam['shift'] ?? '') !== '' ? ' / '.$text($respondingTeam['shift']) : '' }}</div></div>
        </div>
        @if ($categories !== [])
            <div class="divider"></div>
            <div class="meta-label">Exercise Categories</div>
            <div>@foreach ($categories as $category)<span class="value-badge">{{ $category }}</span>@endforeach</div>
        @endif
    </div>
</div>

@if ($exerciseTitle !== '' || $details !== '' || $objectives !== [] || $erpReferences !== [])
<div class="card">
    <div class="card-head">Exercise Details</div>
    <div class="card-body">
        @if ($exerciseTitle !== '')
            <div class="text-block"><div class="text-block-label">Exercise Title</div><div class="scenario-title">{{ $exerciseTitle }}</div></div>
        @endif
        @if ($details !== '')
            <div class="text-block"><div class="text-block-label">Scenario / Details</div><div class="text-block-value">{{ $details }}</div></div>
        @endif
        @if ($objectives !== [])
            <div class="text-block"><div class="text-block-label">Exercise Objectives</div><ul class="compact-list">@foreach ($objectives as $objective)<li>{{ $objective }}</li>@endforeach</ul></div>
        @endif
        @if ($erpReferences !== [])
            <div class="text-block">
                <div class="text-block-label">ERP / Annex References</div>
                <table class="data-table">
                    <thead><tr><th style="width:28%">ERP / Annex No.</th><th>Annex Title</th></tr></thead>
                    <tbody>@foreach ($erpReferences as $reference)<tr><td>{{ $text($reference['annexNumber'] ?? '') ?: '--' }}</td><td>{{ $text($reference['title'] ?? '') ?: '--' }}</td></tr>@endforeach</tbody>
                </table>
            </div>
        @endif
    </div>
</div>
@endif

@if ($personnel !== [])
<div class="card">
    <div class="card-head">Exercise Personnel</div>
    <div class="card-body" style="padding:0">
        <table class="data-table">
            <thead><tr><th class="person-name">Name</th><th class="person-role">Organisation Role</th><th class="person-exercise-role">Exercise Role</th><th>Team / Organisation</th></tr></thead>
            <tbody>
                @foreach ($personnel as $person)
                <tr>
                    <td>{{ $text($person['name'] ?? '') ?: '--' }}</td>
                    <td>{{ $text($person['role'] ?? '') ?: '--' }}</td>
                    <td>{{ $text($person['exerciseRole'] ?? $person['exercise_role'] ?? '') ?: '--' }}</td>
                    <td>{{ $text($person['teamName'] ?? $person['team_name'] ?? '') ?: '--' }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endif

@if ($summary !== '')
<div class="card">
    <div class="card-head">Summary of Exercise</div>
    <div class="card-body"><div class="text-block-value">{{ $summary }}</div></div>
</div>
@endif

@if ($chronology !== [])
<div class="card">
    <div class="card-head">Chronology of Drill Events</div>
    <div class="card-body" style="padding:0">
        <table class="data-table">
            <thead><tr><th class="chronology-time">Time</th><th>Event / Action</th></tr></thead>
            <tbody>@foreach ($chronology as $row)<tr><td>{{ $text($row['time'] ?? '') ?: '--' }}</td><td>{{ $text($row['action'] ?? '') ?: '--' }}</td></tr>@endforeach</tbody>
        </table>
    </div>
</div>
@endif

@if ($strengths !== [] || $resources !== [] || $improvements !== [])
<div class="card">
    <div class="card-head">Post-Exercise Analysis</div>
    <div class="card-body">
        @if ($strengths !== [])<div class="text-block"><div class="text-block-label">Strengths</div><ul class="compact-list">@foreach ($strengths as $item)<li>{{ $item }}</li>@endforeach</ul></div>@endif
        @if ($resources !== [])<div class="text-block"><div class="text-block-label">Resources, Equipment &amp; Consumables Mobilised</div><ul class="compact-list">@foreach ($resources as $item)<li>{{ $item }}</li>@endforeach</ul></div>@endif
        @if ($improvements !== [])<div class="text-block"><div class="text-block-label">Improvement Opportunities</div><ul class="compact-list">@foreach ($improvements as $item)<li>{{ $item }}</li>@endforeach</ul></div>@endif
    </div>
</div>
@endif

@if ($photos !== [])
<div class="card photo-section">
    <div class="card-head">Photographs</div>
    <div class="card-body">
        <table class="photo-grid">
            @foreach (array_chunk($photos, 2) as $photoPair)
            <tr>
                @foreach ($photoPair as $photo)
                <td><div class="photo-unit"><img class="photo-image" src="{{ $text($photo['url'] ?? '') }}" alt="Report photograph"><div class="photo-description">{{ $text($photo['description'] ?? '') }}</div></div></td>
                @endforeach
                @if (count($photoPair) === 1)<td></td>@endif
            </tr>
            @endforeach
        </table>
    </div>
</div>
@endif

<div class="card keep-together">
    <div class="card-head">Workflow Sign-Off</div>
    <div class="card-body" style="padding:0">
        <table class="signoff">
            <thead><tr><th>Prepared By</th><th>Station Commander Review</th><th>VMM Review</th></tr></thead>
            <tbody><tr><td>{!! $renderSigner($submittedEntry) !!}</td><td>{!! $renderSigner($reviewedEntry) !!}</td><td>{!! $renderSigner($approvedEntry) !!}</td></tr></tbody>
        </table>
        @if ($rejectedEntry)<div style="padding:7px"><div class="text-block-label">Rejected By</div>{!! $renderSigner($rejectedEntry) !!}</div>@endif
    </div>
</div>
</body>
</html>
