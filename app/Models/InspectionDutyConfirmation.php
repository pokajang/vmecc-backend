<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InspectionDutyConfirmation extends Model
{
    protected $fillable = [
        'token_id', 'token_hash', 'user_id', 'operation', 'context_version', 'source_version',
        'context_hash', 'context_snapshot', 'form_id', 'record_id', 'idempotency_key',
        'request_id', 'ip_address', 'user_agent', 'reason', 'expires_at', 'consumed_at', 'revoked_at',
    ];

    protected $hidden = ['token_hash'];

    protected $casts = [
        'context_snapshot' => 'array',
        'expires_at' => 'datetime',
        'consumed_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];
}
