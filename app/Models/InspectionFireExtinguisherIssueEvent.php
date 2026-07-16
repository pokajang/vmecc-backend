<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InspectionFireExtinguisherIssueEvent extends Model
{
    protected $fillable = [
        'issue_id', 'event_type', 'actor_user_id', 'from_status', 'to_status', 'note', 'metadata',
    ];

    protected $casts = ['metadata' => 'array'];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
