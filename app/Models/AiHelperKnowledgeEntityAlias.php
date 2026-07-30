<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiHelperKnowledgeEntityAlias extends Model
{
    protected $fillable = [
        'entity_id',
        'alias',
        'normalized_alias',
        'alias_type',
        'language',
    ];

    public function entity(): BelongsTo
    {
        return $this->belongsTo(AiHelperKnowledgeEntity::class, 'entity_id');
    }
}
