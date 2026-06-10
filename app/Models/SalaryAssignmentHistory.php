<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalaryAssignmentHistory extends Model
{
    use HasFactory;

    protected $fillable = [
        'salary_assignment_id',
        'event_type',
        'before_data',
        'after_data',
        'actor_name',
        'occurred_at',
    ];

    protected $casts = [
        'before_data' => 'array',
        'after_data' => 'array',
        'occurred_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(SalaryAssignment::class, 'salary_assignment_id');
    }
}
