<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InspectionExtinguisherOperation extends Model
{
    protected $fillable = [
        'operation_uid',
        'inspection_session_id',
        'canonical_asset_key',
        'operation_type',
        'actor_user_id',
        'base_version',
        'result_version',
        'payload_hash',
        'status',
        'outcome_code',
        'response_payload',
    ];

    protected $casts = [
        'base_version' => 'integer',
        'result_version' => 'integer',
        'response_payload' => 'array',
    ];

    public function inspectionSession(): BelongsTo
    {
        return $this->belongsTo(InspectionSession::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
