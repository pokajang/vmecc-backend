<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Report extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'report_uid',
        'display_id',
        'submission_key',
        'owner_user_id',
        'report_type',
        'status',
        'workflow_stage',
        'workflow_snapshot',
        'next_action_role',
        'approval_history',
        'scope_team_id',
        'version',
        'revision',
        'payload',
        'inspection_checklist_item_ids',
        'inspection_checklist_item_labels',
        'inspection_has_checklist',
        'submitted_at',
        'reviewed_at',
        'approved_at',
        'rejected_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'workflow_snapshot' => 'array',
        'approval_history' => 'array',
        'inspection_checklist_item_ids' => 'array',
        'inspection_checklist_item_labels' => 'array',
        'inspection_has_checklist' => 'boolean',
        'submitted_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function timelineEntries(): HasMany
    {
        return $this->hasMany(ReportTimelineEntry::class)->orderBy('created_at');
    }
}
