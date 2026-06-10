<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalaryAssignmentDraft extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'draft_name',
        'source_assignment_id',
        'payload',
        'saved_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'saved_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sourceAssignment(): BelongsTo
    {
        return $this->belongsTo(SalaryAssignment::class, 'source_assignment_id');
    }
}
