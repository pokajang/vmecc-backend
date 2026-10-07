<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Fitness Test Report {{ $record['displayId'] ?? ($record['id'] ?? 'fitness-test-report') }}</title>
    <style>
        @page { size: A4; margin: 14mm 14mm 20mm; }
        * { box-sizing: border-box; }
        body {
            font-family: "DejaVu Sans", sans-serif;
            color: #111827;
            margin: 0;
            font-size: 9.5px;
            line-height: 1.38;
        }

        h2,
        h3 {
            margin: 0 0 8px 0;
            font-weight: 600;
        }

        .report-header {
            border-bottom: 2px solid #007e7a;
            display: table;
            margin-bottom: 10px;
            padding-bottom: 7px;
            table-layout: fixed;
            width: 100%;
        }

        .report-header-left,
        .report-header-right {
            display: table-cell;
            vertical-align: bottom;
        }

        .report-header-right { text-align: right; }

        .report-title {
            color: #007e7a;
            font-size: 15px;
            font-weight: 700;
            letter-spacing: .035em;
            margin: 0;
            word-break: break-word;
        }

        .report-subtitle { color: #6b7280; font-size: 8px; margin-top: 1px; }
        .report-id { font-size: 11.5px; font-weight: 700; word-break: break-word; }
        @include('pdf.shared.status-badge-styles')

        h2 {
            background: #f3f4f6;
            border-bottom: 1px solid #d1d5db;
            color: #374151;
            font-size: 8.5px;
            letter-spacing: .05em;
            margin: 0;
            padding: 4px 7px;
            page-break-after: avoid;
        }

        p {
            margin: 2px 0;
        }

        .meta {
            border: 1px solid #d1d5db;
            margin-bottom: 10px;
            padding: 6px 7px 2px;
        }

        .meta p {
            margin: 4px 0;
        }

        .row {
            display: table;
            table-layout: fixed;
            width: 100%;
        }

        .cell {
            display: table-cell;
            padding: 0 7px 4px 0;
            vertical-align: top;
            width: 33.333%;
            word-break: break-word;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 6px;
        }

        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }

        th,
        td {
            border: 1px solid #cbd5e1;
            font-size: 8px;
            padding: 4px 5px;
            vertical-align: top;
            text-align: left;
        }

        th {
            background: #f8fafc;
            color: #374151;
            font-size: 7.5px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .muted {
            color: #475569;
            font-size: 8px;
        }

        .section {
            border: 1px solid #d1d5db;
            margin-top: 8px;
        }

        .section > .row { padding: 6px 7px 2px; }
        .section > table { margin-top: 0; }
        .photo-section { page-break-before: auto; }

        .photo-grid {
            table-layout: fixed;
        }

        .photo-grid td {
            width: 50%;
            padding: 5px;
            page-break-inside: avoid;
        }

        .photo-image {
            display: block;
            width: auto;
            height: auto;
            max-width: 100%;
            max-height: 72mm;
            margin: 0 auto;
            background: #f8fafc;
        }

        .photo-description {
            margin-top: 4px;
            color: #374151;
            font-size: 8px;
            white-space: pre-wrap;
            word-break: break-word;
        }

        .report-footer {
            border-top: 1px solid #e5e7eb;
            bottom: -12mm;
            color: #9ca3af;
            font-size: 7px;
            left: 0;
            padding-top: 4px;
            position: fixed;
            right: 0;
            text-align: right;
        }

        .page-number::after { content: counter(page); }
    </style>
</head>
<body>
@php
    $data = is_array($record ?? null) ? $record : [];
    $displayId = trim((string) ($data['displayId'] ?? ($data['id'] ?? 'fitness-test-report')));
    $reportingMonth = trim((string) ($data['reportingMonth'] ?? ''));
    $documentReference = trim((string) ($data['documentReference'] ?? ''));
    $protocolRevision = trim((string) ($data['protocolRevision'] ?? ''));
    $shiftGroups = is_array($data['shiftGroups'] ?? null) ? $data['shiftGroups'] : [];
    $stats = is_array($data['completionStatistics'] ?? null) ? $data['completionStatistics'] : [];
    $signoff = is_array($data['signoff'] ?? null) ? $data['signoff'] : [];
    $status = trim((string) ($data['status'] ?? ''));
    $reportType = trim((string) ($data['reportType'] ?? 'fitness-test'));
    $photos = array_values(array_filter(
        is_array($data['photos'] ?? null) ? $data['photos'] : [],
        fn ($photo): bool => is_array($photo)
            && str_starts_with(trim((string) ($photo['url'] ?? '')), 'data:image/'),
    ));
    $formatDate = function ($value) {
        $value = trim((string) $value);
        return $value === '' ? '-' : $value;
    };
    $safeNumber = function ($value) {
        return $value === null || $value === '' ? 0 : (int) $value;
    };
@endphp

@if ($isPdf ?? false)
    <div class="report-footer">{{ $displayId }} &middot; Page <span class="page-number"></span></div>
@endif

<div class="report-header">
    <div class="report-header-left">
        <h1 class="report-title">Fitness Test Report</h1>
        <div class="report-subtitle">By Vale Mineral Malaysia Emergency Control Center (VMECC)</div>
    </div>
    <div class="report-header-right">
        <div class="report-id">{{ $displayId }}</div>
        @include('pdf.shared.status-badge', ['status' => $status])
    </div>
</div>
<div class="meta">
    <div class="row">
        <div class="cell"><strong>Report Type:</strong> {{ $reportType }}</div>
        <div class="cell"><strong>Status:</strong> {{ $status !== '' ? $status : '-' }}</div>
        <div class="cell"><strong>Version:</strong> {{ (int) ($data['version'] ?? 0) }} / r{{ (int) ($data['revision'] ?? 0) }}</div>
    </div>
    <div class="row">
        <div class="cell"><strong>Reporting Month:</strong> {{ $reportingMonth !== '' ? $reportingMonth : '-' }}</div>
        <div class="cell"><strong>Document Ref:</strong> {{ $documentReference !== '' ? $documentReference : '-' }}</div>
        <div class="cell"><strong>Protocol Rev:</strong> {{ $protocolRevision !== '' ? $protocolRevision : '-' }}</div>
    </div>
    <div class="row">
        <div class="cell"><strong>Submitted:</strong> {{ $formatDate($signoff['submittedAt'] ?? null) }}</div>
        <div class="cell"><strong>Reviewed:</strong> {{ $formatDate($signoff['reviewedAt'] ?? null) }}</div>
        <div class="cell"><strong>Approved:</strong> {{ $formatDate($signoff['approvedAt'] ?? null) }}</div>
    </div>
</div>

<div class="section">
    <h2>Completion Statistics</h2>
    <table>
        <thead>
        <tr>
            <th>Participant Count</th>
            <th>Passed</th>
            <th>Failed</th>
            <th>Incomplete</th>
        </tr>
        </thead>
        <tbody>
        <tr>
            <td>{{ $safeNumber($stats['participantCount'] ?? 0) }}</td>
            <td>{{ $safeNumber($stats['passedAssessmentCount'] ?? 0) }}</td>
            <td>{{ $safeNumber($stats['failedAssessmentCount'] ?? 0) }}</td>
            <td>{{ $safeNumber($stats['incompleteAssessmentCount'] ?? 0) }}</td>
        </tr>
        </tbody>
    </table>
</div>

@foreach ($shiftGroups as $groupIndex => $group)
    @php
        $group = is_array($group) ? $group : [];
        $groupId = trim((string) ($group['id'] ?? 'group-'.((string) ((int) $groupIndex + 1))));
        $shiftName = trim((string) ($group['shiftName'] ?? '-'));
        $teamName = trim((string) ($group['teamName'] ?? '-'));
        $assessor = is_array($group['assessor'] ?? null) ? $group['assessor'] : [];
        $assessorName = trim((string) (($assessor['name'] ?? '') ?: 'Unassigned'));
        $participants = is_array($group['participants'] ?? null) ? $group['participants'] : [];
    @endphp
    <div class="section">
        <h2>Shift Group {{ ((int) $groupIndex + 1) }} ({{ $groupId }})</h2>
        <div class="row">
            <div class="cell"><strong>Shift:</strong> {{ $shiftName }}</div>
            <div class="cell"><strong>Team:</strong> {{ $teamName }}</div>
            <div class="cell"><strong>Assessor:</strong> {{ $assessorName }}</div>
        </div>

        <table>
            <thead>
            <tr>
                <th>Participant</th>
                <th>Role</th>
                <th>Source</th>
                <th>Age</th>
                <th>Fitness</th>
                <th>Proficiency</th>
                <th>Assessment</th>
                <th>CP Checkpoints</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($participants as $participant)
                @php
                    $participant = is_array($participant) ? $participant : [];
                    $participantName = trim((string) ($participant['name'] ?? 'Unknown'));
                    $fitness = is_array($participant['fitness'] ?? null) ? $participant['fitness'] : [];
                    $proficiency = is_array($participant['proficiency'] ?? null) ? $participant['proficiency'] : [];
                    $checkpoints = is_array($proficiency['checkpoints'] ?? null) ? $proficiency['checkpoints'] : [];
                    $fitnessResult = trim((string) ($fitness['result'] ?? ''));
                    $proficiencyResult = trim((string) ($proficiency['result'] ?? ''));
                    $assessment = trim((string) ($participant['assessmentStatus'] ?? ''));
                    $fitnessMetrics = [
                        ($fitness['sitUps'] ?? '-') . ' sit-ups',
                        ($fitness['jumpingJacks'] ?? '-') . ' JJs',
                        ($fitness['pushUps'] ?? '-') . ' push-ups',
                    ];
                @endphp
                <tr>
                    <td>{{ $participantName }}</td>
                    <td>{{ trim((string) ($participant['role'] ?? '-')) }}</td>
                    <td>{{ trim((string) ($participant['source'] ?? '-')) }}</td>
                    <td>{{ trim((string) ($participant['ageSnapshot'] ?? '-')) }}</td>
                    <td>
                        {{ $formatDate($fitness['testedOn'] ?? null) }}<br>
                        <span class="muted">Result:</span> {{ $fitnessResult !== '' ? $fitnessResult : '-' }}<br>
                        {{ implode(', ', $fitnessMetrics) }}
                    </td>
                    <td>
                        {{ $formatDate($proficiency['testedOn'] ?? null) }}<br>
                        <span class="muted">Duration:</span> {{ trim((string) ($proficiency['durationSeconds'] ?? '-')) }}s<br>
                        <span class="muted">Result:</span> {{ $proficiencyResult !== '' ? $proficiencyResult : '-' }}
                    </td>
                    <td>{{ $assessment !== '' ? $assessment : '-' }}</td>
                    <td>
                        @if (count($checkpoints) === 0)
                            -
                        @else
                            @foreach ($checkpoints as $checkpoint)
                                @php
                                    $checkpoint = is_array($checkpoint) ? $checkpoint : [];
                                    $checkpointCode = trim((string) ($checkpoint['checkpointCode'] ?? ''));
                                    $completed = (bool) ($checkpoint['completed'] ?? false);
                                    $duration = trim((string) ($checkpoint['durationSeconds'] ?? ''));
                                    $attempts = trim((string) ($checkpoint['attempts'] ?? ''));
                                @endphp
                                <div>{{ $checkpointCode !== '' ? $checkpointCode : '-' }}: {{ $completed ? 'Completed' : 'Missed' }}@if ($duration !== '') ({{ $duration }}s)@endif @if ($attempts !== '') [{{ $attempts }} attempts]@endif</div>
                            @endforeach
                        @endif
                    </td>
                </tr>
            @endforeach
            @if (count($participants) === 0)
                <tr>
                    <td colspan="8" class="muted">No participants in this shift group.</td>
                </tr>
            @endif
            </tbody>
        </table>
    </div>
@endforeach

@if (count($shiftGroups) === 0)
    <div class="section muted">No grouped participants found for this report.</div>
@endif

@if ($photos !== [])
    <div class="section photo-section">
        <h2>Fitness Test Photographs</h2>
        <table class="photo-grid">
            @foreach (array_chunk($photos, 2) as $photoPair)
                <tr>
                    @foreach ($photoPair as $photo)
                        <td>
                            <img
                                class="photo-image"
                                src="{{ trim((string) ($photo['url'] ?? '')) }}"
                                alt="Fitness test photograph"
                            >
                            <div class="photo-description">{{ trim((string) ($photo['description'] ?? '')) }}</div>
                        </td>
                    @endforeach
                    @if (count($photoPair) === 1)
                        <td></td>
                    @endif
                </tr>
            @endforeach
        </table>
    </div>
@endif
</body>
</html>
