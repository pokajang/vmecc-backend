@if ($reportEvidence['visible'] && ! ($hse['consumesReportEvidence'] ?? false))
    <div class="card">
        <div class="card-head">Additional report evidence</div>
        <div class="card-body">
            @if ($reportEvidence['remarks'] !== '')
                <div class="text-block-label">Additional report remarks</div>
                <div class="text-block-value">{{ $reportEvidence['remarks'] }}</div>
            @endif

            @if (count($reportEvidence['photos']) > 0)
                <div class="text-block-label report-evidence-photos{{ $reportEvidence['remarks'] !== '' ? ' report-evidence-photos--spaced' : '' }}">
                    Report photographs ({{ count($reportEvidence['photos']) }})
                </div>
                @include('pdf.inspection-report.partials.evidence-gallery', ['evidenceGroups' => $reportEvidence['groups']])
            @endif
        </div>
    </div>
@endif
