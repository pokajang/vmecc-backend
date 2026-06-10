<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Emergency Response Call Out Report {{ (string) ($record['displayId'] ?? '') }}</title>
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

        /* ── Header ── */
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
        .report-sub-label {
            font-size: 9px;
            color: #6b7280;
            margin-top: 1px;
        }
        .report-id {
            font-size: 13px;
            font-weight: 700;
            color: #111827;
        }
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

        /* ── Cards ── */
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

        /* ── Meta grid ── */
        .meta-grid {
            display: table;
            width: 100%;
            table-layout: fixed;
        }
        .meta-cell {
            display: table-cell;
            width: 25%;
            padding: 0 4px 5px 0;
            vertical-align: top;
        }
        .meta-cell:last-child { padding-right: 0; }
        .meta-label { font-size: 8.5px; color: #6b7280; margin-bottom: 1px; }
        .meta-value { font-size: 10px; font-weight: 600; word-break: break-word; }

        .meta-grid-3 .meta-cell { width: 33.333%; }
        .meta-grid-2 .meta-cell { width: 50%; }

        /* ── Text blocks ── */
        .text-block { margin-bottom: 6px; }
        .text-block-label { font-size: 8.5px; color: #6b7280; margin-bottom: 2px; }
        .text-block-value {
            font-size: 10px;
            line-height: 1.5;
            word-break: break-word;
            white-space: pre-wrap;
        }
        .incident-title {
            font-size: 11px;
            font-weight: 700;
            color: #111827;
            line-height: 1.4;
        }

        /* ── Chronology table ── */
        table.chrono {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }
        .chrono th, .chrono td {
            border: 1px solid #d1d5db;
            padding: 4px 6px;
            vertical-align: top;
            font-size: 9.5px;
        }
        .chrono th {
            background: #f9fafb;
            font-weight: 700;
            font-size: 8.5px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: #374151;
        }
        .chrono .col-time { width: 14%; }
        .chrono .col-action { width: 86%; }
        .chrono tr:nth-child(even) td { background: #f9fafb; }

        /* ── Attendance table ── */
        table.attendance {
            width: 100%;
            border-collapse: collapse;
            margin-top: 5px;
        }
        .attendance th, .attendance td {
            border: 1px solid #d1d5db;
            padding: 3px 6px;
            font-size: 9.5px;
            vertical-align: top;
        }
        .attendance th {
            background: #f9fafb;
            font-weight: 700;
            font-size: 8.5px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: #374151;
        }
        .attendance .col-no { width: 8%; text-align: center; }
        .attendance .col-name { width: 52%; }
        .attendance .col-role { width: 40%; }

        /* ── Bullet list ── */
        .bullet-list { margin: 0; padding-left: 14px; }
        .bullet-list li { font-size: 9.5px; margin-bottom: 2px; line-height: 1.4; }

        /* ── Pills ── */
        .pill-wrap { margin-top: 2px; }
        .pill {
            display: inline-block;
            border: 1px solid #007e7a;
            color: #007e7a;
            font-size: 8.5px;
            padding: 1px 7px;
            border-radius: 12px;
            margin: 2px 3px 2px 0;
            font-weight: 600;
        }

        /* ── Sign-offs ── */
        table.signoff {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }
        .signoff th, .signoff td {
            border: 1px solid #d1d5db;
            padding: 5px 8px;
            vertical-align: top;
            width: 33.333%;
        }
        .signoff th {
            background: #f3f4f6;
            font-weight: 700;
            font-size: 8.5px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #374151;
            text-align: left;
        }
        .signoff td { height: 38px; font-size: 9px; }
        .signoff .pending { color: #9ca3af; font-style: italic; font-size: 8.5px; }
        .signoff .signer-name { font-weight: 600; font-size: 9.5px; color: #111827; }
        .signoff .signer-meta { font-size: 8.5px; color: #6b7280; margin-top: 2px; }
        .signoff .signer-remarks {
            font-size: 8.5px;
            color: #4b5563;
            margin-top: 3px;
            line-height: 1.35;
            white-space: pre-wrap;
            word-break: break-word;
        }

        /* ── Photos ── */
        .photo-list { margin: 0; padding-left: 14px; }
        .photo-list li { font-size: 9px; margin-bottom: 2px; color: #374151; }
        .photo-note { font-size: 8.5px; color: #9ca3af; font-style: italic; margin-top: 4px; }
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
        .photo-image-wrap {
            height: auto;
            background: transparent;
            text-align: left;
            line-height: normal;
            overflow: hidden;
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
            text-align: left;
        }
        .photo-file {
            font-size: 8.8px;
            font-weight: 600;
            color: #111827;
            word-break: break-word;
        }
        .photo-description {
            margin-top: 2px;
            font-size: 8.5px;
            color: #4b5563;
            line-height: 1.35;
            white-space: pre-wrap;
            word-break: break-word;
            text-align: left;
        }
        .photo-description.no-description {
            font-style: italic;
        }

        /* ── Footer ── */
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

        .divider { height: 1px; background: #e5e7eb; margin: 6px 0; }

        @media print {
            .page-break-before { page-break-before: always; }
        }
    </style>
</head>
<body>

@php
    $displayId     = (string) ($record['displayId'] ?? '-');
    $status        = (string) ($record['status'] ?? 'Submitted');
    $incidentDate  = (string) ($record['incidentDate'] ?? $record['reportDate'] ?? '');
    $incidentTime  = (string) ($record['incidentTime'] ?? $record['reportTime'] ?? '');
    $weather       = (string) ($record['weather'] ?? '');
    $incidentType  = (string) ($record['incidentType'] ?? '');
    // actionOwner is a workflow routing field — not rendered in the report body
    $details       = (string) ($record['details'] ?? '');
    $summary       = (string) ($record['summary'] ?? '');

    // Location: array or pipe-separated string
    $locationRaw = $record['location'] ?? '';
    if (is_array($locationRaw)) {
        $location = implode(' | ', array_filter(array_map('trim', $locationRaw)));
    } else {
        $location = trim((string) $locationRaw);
    }

    // Responding team
    $respondingTeam = is_array($record['respondingTeam'] ?? null) ? $record['respondingTeam'] : [];
    $teamName    = trim((string) ($respondingTeam['name'] ?? ''));
    $teamShift   = trim((string) ($respondingTeam['shift'] ?? ''));
    $attendance  = is_array($respondingTeam['attendance'] ?? null) ? $respondingTeam['attendance'] : [];
    $aicInCharge = '';
    foreach ($attendance as $member) {
        $memberRole = strtolower(trim((string) ($member['role'] ?? '')));
        if (str_contains($memberRole, 'assistant incident commander') || preg_match('/\baic\b/i', $memberRole)) {
            $aicInCharge = trim((string) ($member['name'] ?? ''));
            if ($aicInCharge !== '') {
                break;
            }
        }
    }
    if ($aicInCharge === '') {
        foreach ($attendance as $member) {
            $memberRole = trim((string) ($member['role'] ?? ''));
            if ($memberRole !== '') {
                $aicInCharge = trim((string) ($member['name'] ?? ''));
                if ($aicInCharge !== '') {
                    break;
                }
            }
        }
    }

    // Chronology
    $chronology = is_array($record['chronology'] ?? null) ? $record['chronology'] : [];
    $chronology = array_filter($chronology, fn($r) => !empty($r['time']) || !empty($r['action']));

    // Post-incident analysis
    $analysis = is_array($record['postIncidentAnalysis'] ?? null) ? $record['postIncidentAnalysis'] : [];
    $strengths     = is_array($analysis['strengths'] ?? null) ? array_filter($analysis['strengths']) : [];
    $resources     = is_array($analysis['resourcesMobilised'] ?? null) ? array_filter($analysis['resourcesMobilised']) : [];
    $improvements  = is_array($analysis['improvementOpportunities'] ?? null) ? array_filter($analysis['improvementOpportunities']) : [];
    $photos        = is_array($analysis['photos'] ?? null) ? array_filter($analysis['photos'], fn($p) => !empty($p['url'])) : [];

    // Timeline / sign-offs
    $timeline = is_array($record['timeline'] ?? null) ? $record['timeline'] : [];
    $submittedEntry = collect($timeline)->first(fn($e) => strtolower((string)($e['action'] ?? '')) === 'submitted');
    $checkedEntry   = collect($timeline)->first(fn($e) => in_array(strtolower((string)($e['action'] ?? '')), ['checked', 'reviewed', 'review'], true));
    $approvedEntry  = collect($timeline)->first(fn($e) => in_array(strtolower((string)($e['action'] ?? '')), ['approved', 'approve'], true));
    $rejectedEntry  = collect($timeline)->first(fn($e) => in_array(strtolower((string)($e['action'] ?? '')), ['rejected', 'reject'], true));

    // Format date-time display
    $dateDisplay = '';
    if ($incidentDate) {
        try {
            $dateDisplay = \Carbon\Carbon::parse($incidentDate)->format('d M Y');
        } catch (\Throwable) {
            $dateDisplay = $incidentDate;
        }
    }
    $timeDisplay = $incidentTime ? substr($incidentTime, 0, 5) : '';
    $dateTimeDisplay = trim($dateDisplay . ($timeDisplay ? ', ' . $timeDisplay : ''));

    $generatedAt = now()->format('d M Y, H:i');

    $hasAnalysis = count($strengths) || count($resources) || count($improvements) || count($photos);
@endphp

{{-- Footer (fixed, renders on all pages) --}}
<div class="report-footer">
    <div class="footer-left">Emergency Response Call Out Report &mdash; {{ $displayId }}</div>
    <div class="footer-right">Generated {{ $generatedAt }}</div>
</div>

{{-- ═══════════ HEADER ═══════════ --}}
<div class="report-header">
    <div class="report-header-left">
        <div class="report-type-label">Emergency Response Call Out Report</div>
        <div class="report-sub-label">By Vale Mineral Malaysia Emergency Control Center (VMECC)</div>
    </div>
    <div class="report-header-right">
        <div class="report-id">{{ $displayId }}</div>
        <div><span class="status-badge">{{ $status }}</span></div>
    </div>
</div>

{{-- ═══════════ INCIDENT OVERVIEW ═══════════ --}}
<div class="card">
    <div class="card-head">Incident Overview</div>
    <div class="card-body">
        <div class="meta-grid meta-grid-3">
            <div class="meta-cell">
                <div class="meta-label">Date &amp; Time</div>
                <div class="meta-value">{{ $dateTimeDisplay ?: '--' }}</div>
            </div>
            <div class="meta-cell">
                <div class="meta-label">Incident Type</div>
                <div class="meta-value">{{ $incidentType ?: '--' }}</div>
            </div>
            <div class="meta-cell">
                <div class="meta-label">Weather</div>
                <div class="meta-value">{{ $weather ?: '--' }}</div>
            </div>
        </div>
        @if ($location || $teamName || $aicInCharge)
            <div class="divider"></div>
            <div class="meta-grid meta-grid-3">
                <div class="meta-cell">
                    <div class="meta-label">Location</div>
                    <div class="meta-value">{{ $location ?: '--' }}</div>
                </div>
                <div class="meta-cell">
                    <div class="meta-label">Team</div>
                    <div class="meta-value">{{ $teamName ?: '--' }}</div>
                </div>
                <div class="meta-cell">
                    <div class="meta-label">AIC In Charge</div>
                    <div class="meta-value">{{ $aicInCharge ?: '--' }}</div>
                </div>
            </div>
        @endif
    </div>
</div>

{{-- ═══════════ INCIDENT DETAILS ═══════════ --}}
@if ($details || $summary)
<div class="card">
    <div class="card-head">Incident Details</div>
    <div class="card-body">
        @if ($details)
            <div class="text-block">
                <div class="text-block-label">Incident Title</div>
                <div class="incident-title">{{ $details }}</div>
            </div>
        @endif
        @if ($summary)
            @if ($details)<div class="divider"></div>@endif
            <div class="text-block">
                <div class="text-block-label">Summary</div>
                <div class="text-block-value">{{ $summary }}</div>
            </div>
        @endif
    </div>
</div>
@endif

{{-- ═══════════ RESPONDING TEAM ═══════════ --}}
@if ($teamName || count($attendance))
<div class="card">
    <div class="card-head">Responding Team</div>
    <div class="card-body">
        <div class="meta-grid meta-grid-2">
            @if ($teamName)
            <div class="meta-cell">
                <div class="meta-label">Team</div>
                <div class="meta-value">{{ $teamName }}</div>
            </div>
            @endif
            @if ($teamShift)
            <div class="meta-cell">
                <div class="meta-label">Shift</div>
                <div class="meta-value">{{ $teamShift }}</div>
            </div>
            @endif
        </div>
        @if (count($attendance))
            <table class="attendance" style="margin-top:6px;">
                <thead>
                    <tr>
                        <th class="col-no">#</th>
                        <th class="col-name">Name</th>
                        <th class="col-role">Role</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($attendance as $i => $member)
                    <tr>
                        <td class="col-no" style="text-align:center;">{{ $i + 1 }}</td>
                        <td class="col-name">{{ trim((string)($member['name'] ?? '')) ?: '--' }}</td>
                        <td class="col-role">{{ trim((string)($member['role'] ?? '')) ?: '--' }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</div>
@endif

{{-- ═══════════ CHRONOLOGY ═══════════ --}}
@if (count($chronology))
<div class="card">
    <div class="card-head">Chronology of Events</div>
    <div class="card-body" style="padding: 0;">
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

{{-- ═══════════ POST-INCIDENT ANALYSIS ═══════════ --}}
@if ($hasAnalysis)
<div class="card">
    <div class="card-head">Post-Incident Analysis</div>
    <div class="card-body">

        {{-- Resources Mobilised --}}
        @if (count($resources))
        <div class="text-block">
            <div class="text-block-label">Resources, Equipment &amp; Consumables Mobilised</div>
            <div class="pill-wrap">
                @foreach ($resources as $r)
                <span class="pill">{{ $r }}</span>
                @endforeach
            </div>
        </div>
        @endif

        {{-- Strengths --}}
        @if (count($strengths))
        @if (count($resources))<div class="divider"></div>@endif
        <div class="text-block">
            <div class="text-block-label">Strengths</div>
            <ul class="bullet-list">
                @foreach ($strengths as $s)
                <li>{{ $s }}</li>
                @endforeach
            </ul>
        </div>
        @endif

        {{-- Improvement Opportunities --}}
        @if (count($improvements))
        @if (count($resources) || count($strengths))<div class="divider"></div>@endif
        <div class="text-block">
            <div class="text-block-label">Improvement Opportunities</div>
            <ul class="bullet-list">
                @foreach ($improvements as $s)
                <li>{{ $s }}</li>
                @endforeach
            </ul>
        </div>
        @endif

        {{-- Photographs --}}
        @if (count($photos))
        @if ($hasAnalysis && (count($resources) || count($strengths) || count($improvements)))<div class="divider"></div>@endif
        <div class="text-block">
            <div class="text-block-label">Photographs ({{ count($photos) }})</div>
            @php $figureIndex = 0; @endphp
            <table class="photo-grid">
                @foreach (array_chunk($photos, 2) as $photoRow)
                <tr>
                    @foreach ($photoRow as $photo)
                    @php
                        $figureIndex = ($figureIndex ?? 0) + 1;
                        $description = trim((string) ($photo['description'] ?? ''));
                        $isNoDescription = false;
                        $photoUrl = trim((string) ($photo['url'] ?? ''));
                        if ($description === '') {
                            $descriptionText = 'Image description not provided by user';
                            $isNoDescription = true;
                        } else {
                            $description = preg_replace('/\s+/u', ' ', $description);
                            $descriptionLower = mb_strtolower($description, 'UTF-8');
                            $descriptionText = mb_strtoupper(mb_substr($descriptionLower, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($descriptionLower, 1, null, 'UTF-8');
                        }
                        if (! preg_match('/[.!?]$/u', $descriptionText)) {
                            $descriptionText .= '.';
                        }
                    @endphp
                    <td>
                        <div class="photo-card">
                            <div class="photo-image-wrap">
                                <img class="photo-image" src="{{ $photoUrl }}" alt="Figure {{ $figureIndex }}">
                            </div>
                            <div class="photo-caption">
                                <div class="photo-description{{ $isNoDescription ? ' no-description' : '' }}">Figure {{ $figureIndex }}. {{ $descriptionText }}</div>
                            </div>
                        </div>
                    </td>
                    @endforeach
                    @if (count($photoRow) === 1)
                        <td></td>
                    @endif
                </tr>
                @endforeach
            </table>
        </div>
        @endif

    </div>
</div>
@endif

{{-- ═══════════ SIGN-OFFS ═══════════ --}}
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
                    {{-- Prepared By = submitter from timeline --}}
                    <td>
                        @if ($submittedEntry)
                            @php
                                $submitterName = trim((string)($submittedEntry['by'] ?? ''));
                                $submittedAt   = '';
                                $submittedRemarks = trim((string)($submittedEntry['remarks'] ?? ''));
                                try {
                                    $submittedAt = \Carbon\Carbon::parse((string)($submittedEntry['at'] ?? ''))->format('d M Y, H:i');
                                } catch (\Throwable) {}
                            @endphp
                            @if ($submitterName)
                                <div class="signer-name">{{ $submitterName }}</div>
                            @endif
                            @if ($submittedAt)
                                <div class="signer-meta">{{ $submittedAt }}</div>
                            @endif
                            @if ($submittedRemarks !== '')
                                <div class="signer-remarks">Remarks: {{ $submittedRemarks }}</div>
                            @endif
                        @else
                            <span class="pending">Pending</span>
                        @endif
                    </td>
                    {{-- Checked By --}}
                    <td>
                        @if ($checkedEntry)
                            @php
                                $checkerName = trim((string)($checkedEntry['by'] ?? ''));
                                $checkedAt   = '';
                                $checkedRemarks = trim((string)($checkedEntry['remarks'] ?? ''));
                                try {
                                    $checkedAt = \Carbon\Carbon::parse((string)($checkedEntry['at'] ?? ''))->format('d M Y, H:i');
                                } catch (\Throwable) {}
                            @endphp
                            @if ($checkerName)
                                <div class="signer-name">{{ $checkerName }}</div>
                            @endif
                            @if ($checkedAt)
                                <div class="signer-meta">{{ $checkedAt }}</div>
                            @endif
                            @if ($checkedRemarks !== '')
                                <div class="signer-remarks">Remarks: {{ $checkedRemarks }}</div>
                            @endif
                        @else
                            <span class="pending">Pending</span>
                        @endif
                    </td>
                    {{-- Approved By --}}
                    <td>
                        @if ($approvedEntry)
                            @php
                                $approverName = trim((string)($approvedEntry['by'] ?? ''));
                                $approvedAt   = '';
                                $approvedRemarks = trim((string)($approvedEntry['remarks'] ?? ''));
                                try {
                                    $approvedAt = \Carbon\Carbon::parse((string)($approvedEntry['at'] ?? ''))->format('d M Y, H:i');
                                } catch (\Throwable) {}
                            @endphp
                            @if ($approverName)
                                <div class="signer-name">{{ $approverName }}</div>
                            @endif
                            @if ($approvedAt)
                                <div class="signer-meta">{{ $approvedAt }}</div>
                            @endif
                            @if ($approvedRemarks !== '')
                                <div class="signer-remarks">Remarks: {{ $approvedRemarks }}</div>
                            @endif
                        @else
                            <span class="pending">Pending</span>
                        @endif
                    </td>
                </tr>
            </tbody>
        </table>
        @if ($rejectedEntry)
            @php
                $rejectedBy = trim((string)($rejectedEntry['by'] ?? ''));
                $rejectedAt = '';
                $rejectedRemarks = trim((string)($rejectedEntry['remarks'] ?? ''));
                try {
                    $rejectedAt = \Carbon\Carbon::parse((string)($rejectedEntry['at'] ?? ''))->format('d M Y, H:i');
                } catch (\Throwable) {}
            @endphp
            <div style="padding: 8px;">
                <div class="text-block-label">Rejected By</div>
                <div class="text-block-value">
                    {{ $rejectedBy ?: '--' }}
                    @if ($rejectedAt)
                        ({{ $rejectedAt }})
                    @endif
                </div>
                @if ($rejectedRemarks !== '')
                    <div class="signer-remarks">Remarks: {{ $rejectedRemarks }}</div>
                @endif
            </div>
        @endif
    </div>
</div>

</body>
</html>
