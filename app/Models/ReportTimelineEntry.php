<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportTimelineEntry extends Model
{
    protected $fillable = [
        'report_id',
        'revision',
        'action',
        'from_status',
        'to_status',
        'by_user_id',
        'by_name_snapshot',
        'remarks',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }
}

