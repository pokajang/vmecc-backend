<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InspectionFireTruck extends Model
{
    protected $fillable = [
        'plate_no',
        'normalized_plate_no',
        'name',
        'road_tax_expiry',
        'insurance_expiry',
        'puspakom_expiry',
        'source',
        'created_by',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'road_tax_expiry' => 'date:Y-m-d',
        'insurance_expiry' => 'date:Y-m-d',
        'puspakom_expiry' => 'date:Y-m-d',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
