@php
    $statusLabel = trim((string) ($status ?? '')) ?: 'Unknown';
    $statusKey = strtolower($statusLabel);
    $statusTone = match ($statusKey) {
        'approved' => 'approved',
        'completed' => 'completed',
        'submitted', 'resubmitted' => 'submitted',
        'reviewed' => 'reviewed',
        'checked' => 'checked',
        'rejected' => 'rejected',
        'draft' => 'draft',
        default => 'neutral',
    };
@endphp
<span class="status-badge status-badge--{{ $statusTone }}">{{ $statusLabel }}</span>
