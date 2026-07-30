<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class SalaryAssignment extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'public_id',
        'reference_id',
        'employee_user_id',
        'status',
        'effective_from',
        'effective_date_key',
        'basic_salary',
        'allowance_total',
        'allowances',
        'employee_contributions',
        'employer_contributions',
        'notes_history',
        'updated_by',
        'version',
    ];

    protected $casts = [
        'effective_from' => 'date',
        'effective_date_key' => 'date',
        'basic_salary' => 'decimal:2',
        'allowance_total' => 'decimal:2',
        'allowances' => 'array',
        'employee_contributions' => 'array',
        'employer_contributions' => 'array',
        'notes_history' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
        'version' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (SalaryAssignment $assignment) {
            $assignment->public_id = $assignment->public_id ?: (string) Str::ulid();
            $assignment->effective_date_key = $assignment->effective_date_key
                ?: $assignment->effective_from;
            $assignment->version = max(1, (int) ($assignment->version ?: 1));
        });
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_user_id');
    }

    public function histories(): HasMany
    {
        return $this->hasMany(SalaryAssignmentHistory::class);
    }
}
