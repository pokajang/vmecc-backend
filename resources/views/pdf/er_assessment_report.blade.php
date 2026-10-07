<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $record['displayId'] ?? 'ER Assessment' }}</title>
    <style>
        @page { size: A4; margin: 14mm 14mm 20mm; }
        * { box-sizing: border-box; }
        body { color: #182b2b; font-family: "DejaVu Sans", sans-serif; font-size: 10px; line-height: 1.35; margin: 0; }
        .header { border: 1px solid #295f5b; border-collapse: collapse; margin-bottom: 12px; width: 100%; }
        .header td { border: 1px solid #295f5b; padding: 7px 9px; vertical-align: middle; }
        .brand { color: #176b63; font-size: 19px; font-weight: 700; letter-spacing: .4px; }
        .title { font-size: 15px; font-weight: 700; text-align: center; }
        .meta { font-size: 8px; line-height: 1.5; width: 145px; }
        h2 { background: #e8f3f1; border-left: 4px solid #27877d; font-size: 11px; margin: 13px 0 7px; padding: 5px 8px; page-break-after: avoid; text-transform: uppercase; }
        .grid, .checklist, .signoff { border-collapse: collapse; width: 100%; }
        .grid td, .checklist th, .checklist td, .signoff td { border: 1px solid #a9bfbd; padding: 6px 7px; vertical-align: top; }
        .grid .label { background: #f2f7f6; color: #45615f; font-size: 8px; font-weight: 700; text-transform: uppercase; width: 19%; }
        .checklist th { background: #dcecea; color: #214f4b; font-size: 8px; text-align: left; text-transform: uppercase; }
        .checklist thead { display: table-header-group; }
        .checklist tr { page-break-inside: avoid; }
        .checklist .number { text-align: center; width: 24px; }
        .checklist .response { font-weight: 700; text-align: center; width: 55px; }
        .muted { color: #657b79; }
        .scenario { background: #fff7df; border: 1px solid #ead28e; margin-top: 7px; padding: 7px 9px; }
        .layout { border: 1px solid #a9bfbd; margin-top: 7px; padding: 8px; page-break-inside: avoid; text-align: center; }
        .layout img { max-height: 310px; max-width: 100%; }
        .evidence { margin-top: 7px; }
        .evidence img { border: 1px solid #a9bfbd; margin: 4px 5px 0 0; max-height: 115px; max-width: 150px; page-break-inside: avoid; vertical-align: top; }
        .evidence-caption { color: #657b79; font-size: 8px; margin-top: 2px; }
        ul { margin: 4px 0 0 18px; padding: 0; }
        .signature { font-size: 13px; font-style: italic; font-weight: 700; min-height: 28px; padding-top: 8px; }
        .signoff-block { page-break-inside: avoid; }
        .footer { border-top: 1px solid #dce4e3; bottom: -12mm; color: #708482; font-size: 8px; left: 0; padding-top: 4px; position: fixed; right: 0; text-align: center; }
        .page-number::after { content: counter(page); }
    </style>
</head>
<body>
@php
    $document = $record['document'] ?? [];
    $layout = $record['rescueAccessLayout'] ?? [];
    $responses = collect($record['responses'] ?? [])->values();
    $responsesByPriority = $responses->partition(
        fn($response): bool => strtolower((string) ($response['response'] ?? '')) === 'no',
    );
    $orderedResponses = $responsesByPriority[0]
        ->concat($responsesByPriority[1])
        ->values()
        ->all();
@endphp
<table class="header">
    <tr>
        <td class="brand">VMECC</td>
        <td class="title">{{ $document['title'] ?? 'Emergency Response Assessment' }}</td>
        <td class="meta">
            <strong>Document:</strong> {{ $document['code'] ?? 'VMECC-OPS-016' }}<br>
            <strong>Revision:</strong> {{ $document['revision'] ?? '0' }}<br>
            <strong>Report:</strong> {{ $record['displayId'] ?? '--' }}<br>
            <strong>Status:</strong> {{ $record['status'] ?? '--' }}
        </td>
    </tr>
</table>

    <h2>Assessment details</h2>
    <table class="grid">
    <tr><td class="label">Company being assessed</td><td>{{ $record['company'] ?? '--' }}</td><td class="label">Assessment date</td><td>{{ $record['assessmentDate'] ?? '--' }}</td></tr>
    <tr><td class="label">Location</td><td>{{ $record['location'] ?? '--' }}</td><td class="label">Work activity being assessed</td><td>{{ $record['assessmentTypeLabel'] ?? '--' }}</td></tr>
    <tr><td class="label">Work activity being assessed (details)</td><td colspan="3">{!! nl2br(e($record['scopeOfWork'] ?? '--')) !!}</td></tr>
</table>
<div class="scenario"><strong>Worst-case scenario:</strong> {{ $record['worstCaseScenario'] ?? '--' }}</div>

<h2>Emergency response readiness</h2>
<table class="checklist">
    <thead><tr><th class="number">No.</th><th>Requirement</th><th class="response">Response</th><th>Gap and immediate action</th></tr></thead>
    <tbody>
    @foreach(($orderedResponses ?? []) as $index => $response)
        @php
            $isEscapeRoute = str_ends_with((string) ($response['requirementId'] ?? ''), '.escape-routes');
            $remarks = $response['remarks'] ?? '';
            $assemblyArea = $response['assemblyArea'] ?? '';
            $photos = collect($response['photos'] ?? [])
                ->filter(fn($photo): bool => is_array($photo) && ! empty($photo['url']))
                ->values();
        @endphp
        <tr>
            <td class="number">{{ $index + 1 }}</td>
            <td>{{ $response['requirement'] ?? '--' }}</td>
            <td class="response">{{ $response['response'] ?? '--' }}</td>
            <td>
                {{ $remarks ?: '--' }}
                @if($isEscapeRoute && $assemblyArea)
                    <br><br><strong>Assembly area (AA):</strong> {{ $assemblyArea }}
                @endif
                @if(strtolower((string) ($response['response'] ?? '')) === 'no' && $photos->isNotEmpty())
                    <div class="evidence">
                        <strong>Supporting evidence</strong><br>
                        @foreach($photos as $photo)
                            @php
                                $description = trim((string) ($photo['description'] ?? ''));
                                $showDescription = $description !== ''
                                    && strcasecmp($description, trim((string) $remarks)) !== 0
                                    && strcasecmp($description, trim((string) ($response['requirement'] ?? ''))) !== 0;
                            @endphp
                            <div style="display: inline-block; vertical-align: top;">
                                <img src="{{ $photo['thumbnailUrl'] ?? $photo['url'] }}" alt="Supporting evidence for {{ $response['requirement'] ?? 'readiness finding' }}">
                                @if($showDescription)
                                    <div class="evidence-caption">{{ $description }}</div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
            </td>
        </tr>
    @endforeach
    </tbody>
</table>

<h2>Rescue planning</h2>
<table class="grid"><tr><td class="label">Rescue plan</td><td>{!! nl2br(e($record['rescuePlan'] ?? '--')) !!}</td></tr></table>
@if(!empty($layout['url']))
    <div class="layout">
        <strong>Rescue access layout</strong><br><br>
        <img src="{{ $layout['url'] }}" alt="Rescue access layout">
    </div>
@endif

<h2>Rescue equipment</h2>
@if(!empty($record['rescueEquipment']))
    <ul>@foreach($record['rescueEquipment'] as $item)<li>{{ $item }}</li>@endforeach</ul>
@else
    <span class="muted">No rescue equipment recorded.</span>
@endif

<div class="signoff-block">
    <h2>Sign-off</h2>
    <table class="signoff">
        <tr>
            <td width="50%"><strong>Inspected by</strong><div class="signature">{{ data_get($record, 'inspectedBy.signature', '--') }}</div>{{ data_get($record, 'inspectedBy.name', '--') }}<br><span class="muted">{{ data_get($record, 'inspectedBy.company', '--') }}</span></td>
            <td width="50%"><strong>Job leader</strong><div class="signature">{{ data_get($record, 'jobLeader.signature', '--') }}</div>{{ data_get($record, 'jobLeader.name', '--') }}<br><span class="muted">{{ data_get($record, 'jobLeader.company', '--') }}</span></td>
        </tr>
    </table>
</div>

<div class="footer">{{ $document['code'] ?? 'VMECC-OPS-016' }} &middot; Revision {{ $document['revision'] ?? '0' }} &middot; {{ $record['displayId'] ?? '' }} &middot; Page <span class="page-number"></span></div>
</body>
</html>
