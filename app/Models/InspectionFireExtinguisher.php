<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InspectionFireExtinguisher extends Model
{
    protected $fillable = [
        'source_row_number',
        'zone',
        'main_location_name',
        'sub_location_name',
        'id_loc_no',
        'barcode_no',
        'fe_type',
        'certification_validity',
        'certification_validity_raw',
        'days_left_to_expire',
        'source',
        'created_by',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'source_row_number' => 'integer',
        'certification_validity' => 'date:Y-m-d',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
