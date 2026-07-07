<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowEmailDelivery extends Model
{
    use HasFactory;

    protected $fillable = [
        'notification_id',
        'user_id',
        'recipient_email',
        'delivery_kind',
        'digest_window_start',
        'digest_window_end',
        'status',
        'attempts',
        'last_error',
        'sent_at',
    ];

    protected $casts = [
        'attempts' => 'integer',
        'digest_window_start' => 'datetime',
        'digest_window_end' => 'datetime',
        'sent_at' => 'datetime',
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
