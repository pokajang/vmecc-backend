<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FitnessShadowReconciliation extends Model
{
    protected $fillable = [
        'report_id',
        'report_uid',
        'report_revision',
        'report_version',
        'payload_hash',
        'projection_hash',
        'status',
        'mismatch_types',
        'mismatch_details',
        'run_at',
        'resolved_at',
    ];

    protected $casts = [
        'mismatch_types' => 'array',
        'mismatch_details' => 'array',
        'run_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }
}
