<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InspectionFireExtinguisher extends Model
{
    protected $fillable = [
        'source_row_number',
        'zone',
        'main_location_name',
        'sub_location_name',
        'id_loc_no',
        'barcode_no',
        'active_identity_key',
        'fe_type',
        'certification_validity',
        'source',
        'created_by',
        'updated_by',
        'is_active',
        'lifecycle_status',
        'out_of_service_at',
        'out_of_service_by',
        'out_of_service_reason',
        'retired_at',
        'retired_by',
        'retirement_reason',
        'restored_at',
        'restored_by',
        'lock_version',
        'sort_order',
    ];

    protected $casts = [
        'source_row_number' => 'integer',
        'certification_validity' => 'date:Y-m-d',
        'is_active' => 'boolean',
        'out_of_service_at' => 'datetime',
        'retired_at' => 'datetime',
        'restored_at' => 'datetime',
        'lock_version' => 'integer',
        'sort_order' => 'integer',
    ];

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function issues(): HasMany
    {
        return $this->hasMany(InspectionFireExtinguisherIssue::class, 'fire_extinguisher_id');
    }
}
