<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowNotificationRecipientState extends Model
{
    use HasFactory;

    protected $fillable = [
        'notification_id',
        'user_id',
        'read_at',
        'dismissed_at',
        'emailed_immediate_at',
        'emailed_digest_at',
        'last_reminder_at',
        'channel_policy',
        'resolved_at',
    ];

    protected $casts = [
        'read_at' => 'datetime',
        'dismissed_at' => 'datetime',
        'emailed_immediate_at' => 'datetime',
        'emailed_digest_at' => 'datetime',
        'last_reminder_at' => 'datetime',
        'resolved_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function notification(): BelongsTo
    {
        return $this->belongsTo(WorkflowNotification::class, 'notification_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
