<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InspectionSessionEvent extends Model
{
    protected $fillable = [
        'inspection_session_id',
        'inspection_extinguisher_result_id',
        'event_type',
        'actor_user_id',
        'payload',
    ];

    protected $casts = [
        'payload' => 'array',
    ];

    public function inspectionSession(): BelongsTo
    {
        return $this->belongsTo(InspectionSession::class);
    }

    public function extinguisherResult(): BelongsTo
    {
        return $this->belongsTo(InspectionExtinguisherResult::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
