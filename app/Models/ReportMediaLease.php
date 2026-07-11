<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportMediaLease extends Model
{
    protected $fillable = [
        'lease_uid',
        'report_media_id',
        'user_id',
        'context_key',
        'expires_at',
        'absolute_expires_at',
        'renewed_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'absolute_expires_at' => 'datetime',
        'renewed_at' => 'datetime',
    ];

    public function media(): BelongsTo
    {
        return $this->belongsTo(ReportMedia::class, 'report_media_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
