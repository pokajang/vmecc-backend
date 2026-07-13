<div class="card card--compact">
    <div class="card-head">Workflow Sign-offs</div>
    <div class="card-body workflow-card-body">
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
                                $preparedRole = $entryActorRole($submittedEntry) ?: $submittedByRole;
                            @endphp
                            @if ($preparedBy !== '')
                                <div class="person-name">{{ $preparedBy }}</div>
                            @endif
                            @if ($preparedRole !== '')
                                <div class="person-meta">{{ $preparedRole }}</div>
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
                            @if ($submittedByRole !== '')
                                <div class="person-meta">{{ $submittedByRole }}</div>
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
                                $reviewedRole = $entryActorRole($reviewedEntry);
                            @endphp
                            @if ($reviewedBy !== '')
                                <div class="person-name">{{ $reviewedBy }}</div>
                            @endif
                            @if ($reviewedRole !== '')
                                <div class="person-meta">{{ $reviewedRole }}</div>
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
                                $approvedRole = $entryActorRole($approvedEntry);
                            @endphp
                            @if ($approvedBy !== '')
                                <div class="person-name">{{ $approvedBy }}</div>
                            @endif
                            @if ($approvedRole !== '')
                                <div class="person-meta">{{ $approvedRole }}</div>
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
