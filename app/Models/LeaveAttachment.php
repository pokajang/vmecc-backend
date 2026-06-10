<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveAttachment extends Model
{
    protected $fillable = [
        'user_id',
        'leave_id',
        'original_name',
        'mime_type',
        'size',
        'original_size',
        'was_compressed',
        'storage_path',
    ];

    protected $casts = [
        'size' => 'integer',
        'original_size' => 'integer',
        'was_compressed' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function leave(): BelongsTo
    {
        return $this->belongsTo(Leave::class);
    }
}
