<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowNotificationDismissal extends Model
{
    protected $fillable = ['notification_id', 'user_id', 'dismissed_at'];

    protected $casts = ['dismissed_at' => 'datetime'];

    public function notification(): BelongsTo
    {
        return $this->belongsTo(WorkflowNotification::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
