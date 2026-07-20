<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiHelperRun extends Model
{
    public const STATUS_STARTED = 'started';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_ABORTED = 'aborted';

    protected $fillable = [
        'request_uuid',
        'user_id',
        'thread_id',
        'assistant_message_id',
        'surface',
        'pipeline_version',
        'index_version',
        'status',
        'result_code',
        'intent',
        'language',
        'topic_keys',
        'candidate_documents',
        'candidate_chunks',
        'evidence_sources',
        'retrieval_recovered',
        'semantic_fallback',
        'rerank_fallback',
        'verification_status',
        'verification_attempts',
        'provider_calls',
        'input_tokens',
        'output_tokens',
        'duration_ms',
        'stage_timings_ms',
        'provider_request_ids',
        'error_stage',
        'completed_at',
    ];

    protected $casts = [
        'topic_keys' => 'array',
        'retrieval_recovered' => 'boolean',
        'semantic_fallback' => 'boolean',
        'rerank_fallback' => 'boolean',
        'stage_timings_ms' => 'array',
        'provider_request_ids' => 'array',
        'completed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(AiHelperThread::class, 'thread_id');
    }

    public function assistantMessage(): BelongsTo
    {
        return $this->belongsTo(AiHelperMessage::class, 'assistant_message_id');
    }
}
