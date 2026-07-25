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
        'source_mode',
        'answer_mode',
        'workflow_key',
        'topic_keys',
        'operation_keys',
        'task_keys',
        'guide_keys',
        'clarification_required',
        'clarification_reason',
        'record_state_used',
        'input_decision',
        'input_reason_codes',
        'input_confidence',
        'input_recoverable',
        'input_semantic_fallback',
        'candidate_documents',
        'candidate_chunks',
        'evidence_sources',
        'coverage_supported_count',
        'coverage_missing_count',
        'retrieval_failure_reason',
        'retrieval_recovered',
        'semantic_fallback',
        'rerank_fallback',
        'verification_status',
        'validation_failure_reason',
        'fallback_type',
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
        'operation_keys' => 'array',
        'task_keys' => 'array',
        'guide_keys' => 'array',
        'clarification_required' => 'boolean',
        'input_reason_codes' => 'array',
        'input_confidence' => 'float',
        'input_recoverable' => 'boolean',
        'input_semantic_fallback' => 'boolean',
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
