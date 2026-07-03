<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InspectionScbaCatalogItem extends Model
{
    protected $fillable = [
        'section_id',
        'location',
        'main_location',
        'brand',
        'serial_no',
        'display_name',
        'details',
        'source',
        'is_active',
        'sort_order',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function section(): BelongsTo
    {
        return $this->belongsTo(InspectionScbaCatalogSection::class, 'section_id');
    }
}
