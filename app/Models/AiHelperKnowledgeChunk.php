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
    ];

    protected $casts = [
        'active' => 'boolean',
        'chunk_index' => 'integer',
        'token_estimate' => 'integer',
    ];

    public function knowledgeEntry(): BelongsTo
    {
        return $this->belongsTo(AiHelperKnowledgeEntry::class, 'knowledge_entry_id');
    }
}
