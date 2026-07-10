<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiHelperKnowledgeChunk extends Model
{
    protected $fillable = [
        'knowledge_entry_id',
        'chunk_index',
        'content',
        'content_hash',
        'token_estimate',
        'module_key',
        'route_key',
        'active',
        'page_start',
        'page_end',
        'extraction_mode',
        'ingestion_version',
    ];

    protected $casts = [
        'active' => 'boolean',
        'chunk_index' => 'integer',
        'token_estimate' => 'integer',
        'page_start' => 'integer',
        'page_end' => 'integer',
        'ingestion_version' => 'integer',
    ];

    public function knowledgeEntry(): BelongsTo
    {
        return $this->belongsTo(AiHelperKnowledgeEntry::class, 'knowledge_entry_id');
    }
}
