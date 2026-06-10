<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollClaimItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'payroll_claim_id',
        'line_no',
        'item_type',
        'title',
        'claim_date',
        'amount',
        'notes',
        'item_meta',
        'attachment_id',
    ];

    protected $casts = [
        'line_no' => 'integer',
        'claim_date' => 'date',
        'amount' => 'decimal:2',
        'item_meta' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function claim(): BelongsTo
    {
        return $this->belongsTo(PayrollClaim::class, 'payroll_claim_id');
    }

    public function attachment(): BelongsTo
    {
        return $this->belongsTo(WorkflowAttachment::class, 'attachment_id');
    }
}
