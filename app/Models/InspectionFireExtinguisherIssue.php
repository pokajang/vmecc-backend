<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class InspectionFireExtinguisherIssue extends Model
{
    use SoftDeletes;

    public const ACTIVE_STATUSES = ['open', 'in_progress', 'pending_verification'];

    protected $fillable = [
        'public_id', 'fire_extinguisher_id', 'check_key', 'check_name', 'status', 'severity',
        'title', 'description', 'assigned_to_user_id', 'due_at', 'first_detected_at',
        'last_detected_at', 'corrective_action', 'resolution_notes', 'resolved_at',
        'resolved_by_user_id', 'verified_at', 'verified_by_user_id', 'closed_at',
        'closed_by_user_id', 'active_key', 'lock_version',
    ];

    protected $casts = [
        'due_at' => 'datetime',
        'first_detected_at' => 'datetime',
        'last_detected_at' => 'datetime',
        'resolved_at' => 'datetime',
        'verified_at' => 'datetime',
        'closed_at' => 'datetime',
        'lock_version' => 'integer',
    ];

    public function extinguisher(): BelongsTo
    {
        return $this->belongsTo(InspectionFireExtinguisher::class, 'fire_extinguisher_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function occurrences(): HasMany
    {
        return $this->hasMany(InspectionFireExtinguisherIssueOccurrence::class, 'issue_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(InspectionFireExtinguisherIssueEvent::class, 'issue_id');
    }

    public function resolutionMediaLinks(): HasMany
    {
        return $this->hasMany(ReportMediaLink::class, 'parent_key', 'public_id')
            ->where('parent_type', 'fire_extinguisher_issue_resolution');
    }
}
