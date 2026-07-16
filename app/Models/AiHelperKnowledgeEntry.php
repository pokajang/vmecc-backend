<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class AiHelperKnowledgeEntry extends Model
{
    use SoftDeletes;

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_DISABLED = 'disabled';

    public const STATUS_FAILED = 'failed';

    public const STATUS_DELETING = 'deleting';

    public const STATUS_DELETED = 'deleted';

    public const VISIBILITY_PERSONAL = 'personal';

    public const VISIBILITY_SHARED = 'shared';

    public const REVIEW_PENDING = 'pending';

    public const REVIEW_APPROVED = 'approved';

    public const REVIEW_REJECTED = 'rejected';

    public const SCOPE_GLOBAL = 'global';

    public const SCOPE_ROUTE = 'route';

    public const SCOPE_MODULE = 'module';

    public const USER_UPLOAD_MODULE_KEYS = [
        'dashboard',
        'messages',
        'payroll',
        'leave',
        'overtime',
        'staff',
        'teams',
        'roster',
        'inspection',
        'reports',
        'settings',
    ];

    public const STATUSES = [
        self::STATUS_PROCESSING,
        self::STATUS_ACTIVE,
        self::STATUS_DISABLED,
        self::STATUS_FAILED,
        self::STATUS_DELETING,
        self::STATUS_DELETED,
    ];

    public const VISIBILITIES = [
        self::VISIBILITY_PERSONAL,
        self::VISIBILITY_SHARED,
    ];

    public const REVIEW_STATUSES = [
        self::REVIEW_PENDING,
        self::REVIEW_APPROVED,
        self::REVIEW_REJECTED,
    ];

    protected $fillable = [
        'uploaded_by',
        'source_document_id',
        'module_key',
        'route_key',
        'title',
        'content',
        'summary',
        'source_filename',
        'source_mime',
        'source_size',
        'source_path',
        'content_hash',
        'pdf_page_count',
        'pdf_image_count',
        'pdf_pages_with_images',
        'pdf_readable_text_characters',
        'pdf_readable_word_count',
        'pdf_image_coverage_estimate',
        'processing_warnings',
        'processing_findings',
        'scope_type',
        'visibility',
        'status',
        'review_status',
        'reviewed_by',
        'reviewed_at',
        'review_note',
        'acknowledged_at',
        'processed_at',
        'error',
        'tags',
        'active',
        'version',
        'ingestion_run_id',
        'ingestion_version',
        'ingestion_started_at',
        'ingestion_completed_at',
        'extraction_mode',
        'extraction_complete',
        'quality_status',
        'pages_indexed',
        'pages_native',
        'pages_ocr',
        'pages_blank',
        'pages_visual_only',
        'pages_unreadable',
        'extracted_characters',
    ];

    protected $casts = [
        'tags' => 'array',
        'active' => 'boolean',
        'version' => 'integer',
        'source_size' => 'integer',
        'pdf_page_count' => 'integer',
        'pdf_image_count' => 'integer',
        'pdf_pages_with_images' => 'integer',
        'pdf_readable_text_characters' => 'integer',
        'pdf_readable_word_count' => 'integer',
        'pdf_image_coverage_estimate' => 'integer',
        'processing_warnings' => 'array',
        'processing_findings' => 'array',
        'extraction_complete' => 'boolean',
        'pages_indexed' => 'integer',
        'pages_native' => 'integer',
        'pages_ocr' => 'integer',
        'pages_blank' => 'integer',
        'pages_visual_only' => 'integer',
        'pages_unreadable' => 'integer',
        'ingestion_version' => 'integer',
        'extracted_characters' => 'integer',
        'acknowledged_at' => 'datetime',
        'processed_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'ingestion_started_at' => 'datetime',
        'ingestion_completed_at' => 'datetime',
    ];

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function sourceDocument(): BelongsTo
    {
        return $this->belongsTo(AiHelperDocument::class, 'source_document_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(AiHelperKnowledgeChunk::class, 'knowledge_entry_id');
    }

    public function pages(): HasMany
    {
        return $this->hasMany(AiHelperKnowledgePage::class, 'knowledge_entry_id');
    }
}
