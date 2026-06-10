<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollClaimPaymentEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'claim_id',
        'action',
        'payment_date',
        'payment_reference',
        'note',
        'reason',
        'acted_by_user_id',
    ];

    protected $casts = [
        'payment_date' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function claim(): BelongsTo
    {
        return $this->belongsTo(PayrollClaim::class, 'claim_id');
    }

    public function actedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acted_by_user_id');
    }
}
