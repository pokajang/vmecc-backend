<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PayrollClaim extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'display_id',
        'submission_key',
        'claim_type',
        'category',
        'period',
        'period_value',
        'amount',
        'approved_overtime_payout',
        'adjustments_total',
        'projected_net_payout',
        'status',
        'submitted_at',
        'submitted_by',
        'submitted_by_name',
        'updated_by',
        'updated_by_name',
        'workflow_stage',
        'workflow_snapshot',
        'next_action_role',
        'approval_history',
        'payroll_snapshot',
        'overtime_rows',
        'overtime_rate_snapshot',
        'payslip_snapshot',
        'payment_date',
        'paid_at',
        'paid_by_user_id',
        'payment_reference',
        'payment_note',
        'notes',
        'attachment_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'approved_overtime_payout' => 'decimal:2',
        'adjustments_total' => 'decimal:2',
        'projected_net_payout' => 'decimal:2',
        'submitted_at' => 'datetime',
        'workflow_snapshot' => 'array',
        'approval_history' => 'array',
        'payroll_snapshot' => 'array',
        'overtime_rows' => 'array',
        'overtime_rate_snapshot' => 'array',
        'payslip_snapshot' => 'array',
        'payment_date' => 'date',
        'paid_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PayrollClaimItem::class);
    }

    public function attachment(): BelongsTo
    {
        return $this->belongsTo(WorkflowAttachment::class, 'attachment_id');
    }

    public function paidByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by_user_id');
    }
}
