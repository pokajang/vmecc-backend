<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserSession extends Model
{
    use HasFactory;

    protected $table = 'user_sessions';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'user_id',
        'ip_address',
        'user_agent',
        'device_id',
        'expires_at',
        'logged_out_at',
        'last_seen_at',
        'revoked_at',
        'revoked_by',
        'revoke_reason',
        'csrf_token_hash',
        'remember_token_hash',
        'remember_expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'logged_out_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'revoked_at' => 'datetime',
        'remember_expires_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
