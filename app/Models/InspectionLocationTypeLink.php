<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InspectionLocationTypeLink extends Model
{
    protected $fillable = [
        'inspection_location_id',
        'inspection_type_key',
        'inspection_type_label',
        'is_default',
        'sort_order',
    ];

    protected $casts = [
        'is_default' => 'boolean',
    ];

    public function location(): BelongsTo
    {
        return $this->belongsTo(InspectionLocation::class, 'inspection_location_id');
    }
}
