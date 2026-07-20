<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiHelperThread extends Model
{
    protected $fillable = [
        'user_id',
        'title',
        'conversation_purpose',
        'latest_route_context',
    ];

    protected $casts = [
        'latest_route_context' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(AiHelperMessage::class, 'thread_id');
    }

    public function responseReports(): HasMany
    {
        return $this->hasMany(AiHelperResponseReport::class, 'thread_id');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(AiHelperRun::class, 'thread_id');
    }
}
