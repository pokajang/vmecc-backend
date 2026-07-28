<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkflowNotificationOutbox extends Model
{
    protected $table = 'workflow_notification_outbox';

    protected $fillable = [
        'notification_id',
        'event_version',
        'status',
        'attempts',
        'available_at',
        'processing_at',
        'processed_at',
        'failed_at',
        'last_error',
    ];

    protected $casts = [
        'event_version' => 'integer',
        'attempts' => 'integer',
        'available_at' => 'datetime',
        'processing_at' => 'datetime',
        'processed_at' => 'datetime',
        'failed_at' => 'datetime',
    ];
}
