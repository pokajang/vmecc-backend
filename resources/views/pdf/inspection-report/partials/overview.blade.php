<div class="card card--compact">
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
                @if ($submittedByRole !== '')
                    <div class="person-meta">{{ $submittedByRole }}</div>
                @endif
            </div>
        </div>
    </div>
</div>
