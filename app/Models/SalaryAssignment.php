<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SalaryAssignment extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'reference_id',
        'employee_user_id',
        'status',
        'effective_from',
        'basic_salary',
        'allowance_total',
        'allowances',
        'employee_contributions',
        'employer_contributions',
        'notes_history',
        'updated_by',
    ];

    protected $casts = [
        'effective_from' => 'date',
        'basic_salary' => 'decimal:2',
        'allowance_total' => 'decimal:2',
        'allowances' => 'array',
        'employee_contributions' => 'array',
        'employer_contributions' => 'array',
        'notes_history' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_user_id');
    }

    public function histories(): HasMany
    {
        return $this->hasMany(SalaryAssignmentHistory::class);
    }
}
