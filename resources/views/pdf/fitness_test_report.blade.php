<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Fitness Test Report {{ $record['displayId'] ?? ($record['id'] ?? 'fitness-test-report') }}</title>
    <style>
        body {
            font-family: Arial, Helvetica, sans-serif;
            color: #111827;
            margin: 24px;
            font-size: 12px;
        }

        h1,
        h2,
        h3 {
            margin: 0 0 8px 0;
            font-weight: 600;
        }

        h1 {
            font-size: 22px;
            margin-bottom: 12px;
        }

        h2 {
            font-size: 16px;
            margin-top: 22px;
        }

        p {
            margin: 2px 0;
        }

        .meta {
            margin-bottom: 12px;
        }

        .meta p {
            margin: 4px 0;
        }

        .row {
            display: flex;
            gap: 24px;
            flex-wrap: wrap;
        }

        .cell {
            min-width: 220px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }

        th,
        td {
            border: 1px solid #cbd5e1;
            padding: 6px;
            vertical-align: top;
            text-align: left;
        }

        th {
            background: #f8fafc;
            font-weight: 600;
        }

        .muted {
            color: #475569;
            font-size: 11px;
        }

        .section {
            margin-top: 16px;
            padding-top: 8px;
            border-top: 1px solid #e2e8f0;
        }
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
    $formatDate = function ($value) {
        $value = trim((string) $value);
        return $value === '' ? '-' : $value;
    };
    $safeNumber = function ($value) {
        return $value === null || $value === '' ? 0 : (int) $value;
    };
@endphp

<h1>Fitness Test Report - {{ $displayId }}</h1>
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
</body>
</html>
