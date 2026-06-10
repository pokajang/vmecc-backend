<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeaveAssignment extends Model
{
    protected $fillable = [
        'user_id',
        'year',
        'leave_type',
        'entitlement',
        'used',
        'pending',
    ];

    protected $casts = [
        'year' => 'integer',
        'entitlement' => 'decimal:1',
        'used' => 'decimal:1',
        'pending' => 'decimal:1',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function histories(): HasMany
    {
        return $this->hasMany(LeaveAssignmentHistory::class, 'assignment_id');
    }
}
