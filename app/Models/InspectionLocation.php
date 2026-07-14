<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;

class InspectionLocation extends Model
{
    protected $fillable = [
        'parent_id',
        'active_identity_key',
        'name',
        'normalized_name',
        'description',
        'icon_key',
        'source',
        'created_by',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $location): void {
            if (! Schema::hasColumn($location->getTable(), 'active_identity_key')) {
                return;
            }
            $location->active_identity_key = $location->is_active
                ? self::activeIdentityKey($location->parent_id, $location->normalized_name)
                : null;
        });
    }

    public static function activeIdentityKey(?int $parentId, string $normalizedName): string
    {
        $identityName = trim($normalizedName);
        if ($parentId === null) {
            $identityName = preg_replace('/^zone\s+/i', '', $identityName) ?? $identityName;
        }

        return hash('sha256', ($parentId ?: 'root').'|'.$identityName);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')
            ->orderBy('sort_order')
            ->orderBy('name');
    }

    public function activeChildren(): HasMany
    {
        return $this->children()->where('is_active', true);
    }

    public function typeLinks(): HasMany
    {
        return $this->hasMany(InspectionLocationTypeLink::class);
    }
}
