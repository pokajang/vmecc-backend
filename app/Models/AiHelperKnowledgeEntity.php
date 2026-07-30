<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiHelperKnowledgeEntity extends Model
{
    protected $fillable = [
        'knowledge_entry_id',
        'source_chunk_id',
        'canonical_name',
        'normalized_name',
        'entity_type',
        'confidence',
        'ingestion_version',
        'active',
    ];

    protected $casts = [
        'confidence' => 'float',
        'ingestion_version' => 'integer',
        'active' => 'boolean',
    ];

    public function knowledgeEntry(): BelongsTo
    {
        return $this->belongsTo(AiHelperKnowledgeEntry::class, 'knowledge_entry_id');
    }

    public function sourceChunk(): BelongsTo
    {
        return $this->belongsTo(AiHelperKnowledgeChunk::class, 'source_chunk_id');
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(AiHelperKnowledgeEntityAlias::class, 'entity_id');
    }
}
