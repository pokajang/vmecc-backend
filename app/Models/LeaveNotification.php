<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeaveNotification extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'event_type',
        'leave_id',
        'leave_display_id',
        'owner_user_id',
        'actor_data',
        'recipient_user_ids',
        'action_required',
        'resolved_at',
        'title',
        'message',
        'metadata',
        'created_at',
    ];

    protected $casts = [
        'actor_data' => 'array',
        'recipient_user_ids' => 'array',
        'action_required' => 'boolean',
        'resolved_at' => 'datetime',
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    public function leave(): BelongsTo
    {
        return $this->belongsTo(Leave::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function reads(): HasMany
    {
        return $this->hasMany(LeaveNotificationRead::class, 'notification_id');
    }
}
