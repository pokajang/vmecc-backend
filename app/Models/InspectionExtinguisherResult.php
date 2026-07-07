<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InspectionExtinguisherResult extends Model
{
    protected $fillable = [
        'inspection_session_id',
        'canonical_asset_key',
        'fire_extinguisher_id',
        'zone',
        'main_location',
        'sub_location',
        'id_loc_no',
        'barcode_no',
        'status',
        'check_payload',
        'client_result_id',
        'checked_by_user_id',
        'checked_at',
        'lock_owner_user_id',
        'lock_expires_at',
        'version',
    ];

    protected $casts = [
        'check_payload' => 'array',
        'checked_at' => 'datetime',
        'lock_expires_at' => 'datetime',
        'version' => 'integer',
    ];

    public function inspectionSession(): BelongsTo
    {
        return $this->belongsTo(InspectionSession::class);
    }

    public function fireExtinguisher(): BelongsTo
    {
        return $this->belongsTo(InspectionFireExtinguisher::class);
    }

    public function checkedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_by_user_id');
    }
}
