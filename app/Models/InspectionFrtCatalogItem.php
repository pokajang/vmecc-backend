<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InspectionFrtCatalogItem extends Model
{
    protected $fillable = ['fire_truck_id', 'checklist_kind', 'compartment', 'name', 'normalized_name', 'quantity', 'asset_tag', 'manufacturer', 'model', 'serial_no', 'description', 'metadata', 'source', 'is_active', 'sort_order', 'created_by', 'updated_by'];
    protected $casts = ['metadata' => 'array', 'is_active' => 'boolean'];
    public function truck(): BelongsTo { return $this->belongsTo(InspectionFireTruck::class, 'fire_truck_id'); }
}
