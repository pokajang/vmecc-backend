@if ($isGeneralInspection && count($inspectionIssues) > 0)
<div class="card">
    <div class="card-head">Findings ({{ count($inspectionIssues) }})</div>
    <div class="card-body">
        @foreach ($inspectionIssues as $issueIndex => $issue)
            @php
                $issuePhotos = $issue['photos'];
                $findingEvidence = count($issuePhotos) > 0 ? [[
                    'kind' => 'Finding',
                    'title' => 'Finding Photos - Finding '.($issueIndex + 1),
                    'remarks' => $issue['description'],
                    'photos' => $issuePhotos,
                    'alt' => 'Inspection finding photo',
                ]] : [];
                $compactFinding = count($issuePhotos) === 0
                    && $issue['description'] !== ''
                    && $issue['actionRequired'] !== ''
                    && $isCompactText($issue['description'])
                    && $isCompactText($issue['actionRequired']);
            @endphp
            <div class="issue-block">
                <div class="issue-title">Finding {{ $issueIndex + 1 }}</div>
                @if ($compactFinding)
                    {!! $renderCompactBlocks([
                        $compactBlock('Description', '', $issue['description']),
                        $compactBlock('Action Required', '', $issue['actionRequired']),
                    ]) !!}
                @else
                    @if ($issue['description'] !== '')
                        <div class="text-block-label">Description</div>
                        <div class="text-block-value">{{ $issue['description'] }}</div>
                    @endif
                    @if ($issue['actionRequired'] !== '')
                        <div class="divider"></div>
                        <div class="text-block-label">Action Required</div>
                        <div class="text-block-value">{{ $issue['actionRequired'] }}</div>
                    @endif
                @endif
                @if (count($issuePhotos) > 0)
                    <div class="divider"></div>
                    @include('pdf.inspection-report.partials.evidence-gallery', ['evidenceGroups' => $findingEvidence])
                @endif
            </div>
        @endforeach
    </div>
</div>
@endif
