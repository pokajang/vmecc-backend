<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiHelperKnowledgePage extends Model
{
    protected $fillable = [
        'knowledge_entry_id',
        'ingestion_version',
        'page_number',
        'outcome',
        'native_character_count',
        'native_word_count',
        'ocr_character_count',
        'ocr_word_count',
        'image_count',
        'ocr_attempted',
        'ocr_succeeded',
        'findings',
    ];

    protected $casts = [
        'ingestion_version' => 'integer',
        'page_number' => 'integer',
        'native_character_count' => 'integer',
        'native_word_count' => 'integer',
        'ocr_character_count' => 'integer',
        'ocr_word_count' => 'integer',
        'image_count' => 'integer',
        'ocr_attempted' => 'boolean',
        'ocr_succeeded' => 'boolean',
        'findings' => 'array',
    ];

    public function knowledgeEntry(): BelongsTo
    {
        return $this->belongsTo(AiHelperKnowledgeEntry::class, 'knowledge_entry_id');
    }
}
