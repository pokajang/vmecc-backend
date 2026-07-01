<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InspectionEquipment extends Model
{
    protected $table = 'inspection_equipment';

    protected $fillable = [
        'inspection_type_key',
        'inspection_type_label',
        'main_location_id',
        'main_location_name',
        'name',
        'normalized_name',
        'description',
        'source',
        'created_by',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function mainLocation(): BelongsTo
    {
        return $this->belongsTo(InspectionLocation::class, 'main_location_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
