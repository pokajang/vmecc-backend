<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveDraft extends Model
{
    protected $fillable = [
        'user_id',
        'draft_data',
        'saved_at',
    ];

    protected $casts = [
        'draft_data' => 'array',
        'saved_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
