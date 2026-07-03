<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InspectionScbaCatalogSection extends Model
{
    protected $fillable = [
        'key',
        'title',
        'short_label',
        'fields',
        'source',
        'is_active',
        'sort_order',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'fields' => 'array',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(InspectionScbaCatalogItem::class, 'section_id');
    }
}
