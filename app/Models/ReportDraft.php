<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReportDraft extends Model
{
    protected $fillable = [
        'user_id',
        'draft_id',
        'report_type',
        'title',
        'origin_mode',
        'source_report_uid',
        'payload',
        'saved_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'saved_at' => 'datetime',
    ];
}
