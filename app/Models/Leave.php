<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Leave extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'display_id',
        'leave_type',
        'status',
        'start_date',
        'end_date',
        'days',
        'work_shift',
        'start_time_slot',
        'end_time_slot',
        'reason',
        'cover_by',
        'applied_at',
        'workflow_stage',
        'workflow_snapshot',
        'next_action_role',
        'applicant_roles',
        'approval_history',
        'submitted_by',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'applied_at' => 'datetime',
        'days' => 'decimal:1',
        'workflow_snapshot' => 'array',
        'applicant_roles' => 'array',
        'approval_history' => 'array',
        'deleted_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function attachment(): HasOne
    {
        return $this->hasOne(LeaveAttachment::class);
    }
}
