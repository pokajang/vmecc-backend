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
        'version',
        'revision',
        'payload',
        'submitted_at',
        'reviewed_at',
        'approved_at',
        'rejected_at',
    ];

    protected $casts = [
        'payload' => 'array',
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
