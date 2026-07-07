<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkflowNotification extends Model
{
    use HasFactory;

    public $timestamps = true;

    protected $fillable = [
        'module',
        'event_type',
        'record_type',
        'record_id',
        'record_display_id',
        'owner_user_id',
        'actor_data',
        'recipient_user_ids',
        'action_required',
        'category',
        'severity',
        'channel_policy',
        'dedupe_key',
        'resolved_at',
        'title',
        'message',
        'metadata',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'actor_data' => 'array',
        'recipient_user_ids' => 'array',
        'action_required' => 'boolean',
        'category' => 'string',
        'severity' => 'string',
        'channel_policy' => 'string',
        'dedupe_key' => 'string',
        'resolved_at' => 'datetime',
        'metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function reads(): HasMany
    {
        return $this->hasMany(WorkflowNotificationRead::class, 'notification_id');
    }

    public function recipientStates(): HasMany
    {
        return $this->hasMany(WorkflowNotificationRecipientState::class, 'notification_id');
    }

    public function emailDeliveries(): HasMany
    {
        return $this->hasMany(WorkflowEmailDelivery::class, 'notification_id');
    }
}
