<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class AiHelperDocument extends Model
{
    use SoftDeletes;

    public const VISIBILITY_PERSONAL = 'personal';

    public const VISIBILITY_SHARED = 'shared';

    public const VISIBILITIES = [
        self::VISIBILITY_PERSONAL,
        self::VISIBILITY_SHARED,
    ];

    protected $fillable = [
        'uploaded_by',
        'title',
        'source_filename',
        'source_mime',
        'source_size',
        'source_path',
        'source_hash',
        'visibility',
        'acknowledged_at',
    ];

    protected $casts = [
        'source_size' => 'integer',
        'acknowledged_at' => 'datetime',
    ];

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function knowledgeEntries(): HasMany
    {
        return $this->hasMany(AiHelperKnowledgeEntry::class, 'source_document_id');
    }
}
