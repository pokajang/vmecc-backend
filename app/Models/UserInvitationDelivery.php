<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserInvitationDelivery extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'recipient_email',
        'status',
        'attempts',
        'last_error',
        'sent_at',
    ];

    protected $casts = [
        'attempts' => 'integer',
        'sent_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
