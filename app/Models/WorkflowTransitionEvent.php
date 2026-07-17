<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class WorkflowTransitionEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'event_uid',
        'history_entry_id',
        'record_type',
        'record_id',
        'record_display_id',
        'action',
        'from_status',
        'to_status',
        'from_stage',
        'to_stage',
        'actor_user_id',
        'actor_name',
        'actor_role',
        'remarks',
        'metadata',
        'occurred_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'occurred_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Workflow transition events are append-only.'));
        static::deleting(fn () => throw new LogicException('Workflow transition events are append-only.'));
    }
}
