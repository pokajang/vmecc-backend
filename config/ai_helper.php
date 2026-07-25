<?php

$primaryModel = trim((string) env('OPENAI_HELPER_MODEL', 'gpt-5.4-mini'));
$embeddingModel = trim((string) env('AI_HELPER_EMBEDDING_MODEL', 'text-embedding-3-small'));

return [
    // Deployment boundary: only the feature switch, credential, and model IDs
    // are environment-controlled. Runtime policy is versioned with the code.
    'enabled' => env('AI_HELPER_ENABLED', false),
    'api_key' => env('OPENAI_API_KEY'),
    'model' => $primaryModel,
    'embedding_model' => $embeddingModel,

    // OpenAI Responses API and request-wide reliability controls.
    'base_url' => 'https://api.openai.com/v1',
    'timeout' => 60,
    'connect_timeout' => 5,
    'request_deadline_seconds' => 50,
    'max_provider_calls_per_request' => 8,
    'provider_max_retries' => 1,
    'provider_retry_base_milliseconds' => 250,
    'provider_retry_max_delay_ms' => 1500,
    'provider_circuit_failure_threshold' => 3,
    'provider_circuit_cooldown_seconds' => 60,
    'max_output_tokens' => 1200,
    'max_output_characters' => 20000,

    // User input, conversation, admission, and concurrency limits.
    'max_message_length' => 2000,
    'embedded_task_max_message_length' => 12000,
    'history_turns' => 12,
    'history_max_characters' => 12000,
    // Version-controlled MVP policy: allow conversational bursts while the
    // concurrency guard still permits only one active generation per user.
    'rate_limit_per_minute' => 8,
    'rate_limit_per_hour' => 60,
    'ip_rate_limit_per_minute' => 12,
    'max_concurrent_per_user' => 1,
    'max_concurrent_global' => 3,
    'concurrency_lock_seconds' => 90,
    'request_deduplication_seconds' => 600,

    // Uploaded-document and curated-knowledge limits.
    'document_upload_max_kb' => 10240,
    'document_max_active_uploads_per_user' => 20,
    'document_max_upload_bytes_per_user' => 209715200,
    'document_max_total_upload_bytes' => 2147483648,
    'reference_corpus_path' => storage_path('app/private/ai_knowledge'),
    'reference_corpus_expected_count' => 34,
    'markdown_upload_max_kb' => 1024,
    'knowledge_upload_rate_limit_per_minute' => 6,
    'knowledge_upload_ip_rate_limit_per_minute' => 30,
    'knowledge_chunk_characters' => 1500,
    // Zero disables the chunk-count limit.
    'knowledge_max_chunks_per_entry' => 2500,
    'knowledge_max_active_uploads_per_user' => 20,
    'knowledge_max_upload_bytes_per_user' => 209715200,
    'knowledge_max_total_upload_bytes' => 2147483648,

    // Current release profile. Older retrieval generations are no longer
    // controlled independently by deployment-time switches.
    'pipeline_version' => 4,
    'index_profile_version' => 4,
    'system_guides_enabled' => true,
    'product_workflows_enabled' => true,
    'system_guide_final_corpus_enforced' => true,
    'system_guide_approval_enforced' => true,
    'knowledge_strict_readiness' => true,
    // Temporarily overridden only by the console evaluation command.
    'evaluation_disabled_module_gate' => null,

    // Retrieval and evidence-context policy.
    'knowledge_retrieval_limit' => 18,
    'knowledge_document_candidate_limit' => 12,
    'retrieval_v4_document_candidate_limit' => 18,
    'retrieval_v4_topic_candidate_limit' => 6,
    'retrieval_v4_page_candidate_limit' => 4,
    'retrieval_v4_global_candidate_limit' => 12,
    'retrieval_v4_recovery_document_limit' => 32,
    'retrieval_candidate_chunks' => 40,
    'retrieval_min_lexical_coverage' => 0.6,
    'retrieval_min_semantic_similarity' => 0.42,
    'knowledge_max_chunks_per_document' => 4,
    'knowledge_exact_document_chunk_limit' => 12,
    'knowledge_adjacent_chunk_window' => 1,
    'knowledge_context_token_budget' => 12000,
    'knowledge_citation_limit' => 12,
    'knowledge_catalogue_limit' => 250,

    // Answer validation and low-confidence policy.
    'citation_validation_enabled' => true,
    'critical_fact_validation_enabled' => true,
    'rerank_enabled' => true,
    'rerank_candidate_limit' => 32,
    'rerank_min_relevance' => 1,
    'rerank_timeout' => 20,
    'rerank_adaptive' => true,
    'grounding_verification_mode' => 'enforce',
    'verifier_timeout' => 25,
    'verification_max_attempts' => 2,
    'response_min_coverage' => 0.55,
    'fallback_confidence_threshold' => 0.35,
    'strict_ungrounded_action' => 'refuse',
    'policy_mode' => 'progressive',

    // Semantic-index profile. Changing the embedding model or any fingerprint
    // field makes existing vectors incompatible and requires a full reindex.
    'embedding_enabled' => true,
    'embedding_dimensions' => 512,
    'embedding_batch_size' => 32,
    'embedding_batch_token_budget' => 100000,
    'embedding_max_input_characters' => 6000,
    'embedding_timeout' => 30,
    'embedding_routing_profile_version' => 'routing-v1',
    'embedding_chunk_profile_version' => 'contextual-v2',
    'query_embedding_cache_seconds' => 3600,

    // Queue, cleanup, telemetry, and storage-health policy.
    'knowledge_job_timeout_seconds' => 900,
    'embedding_job_timeout_seconds' => 300,
    'knowledge_deleted_retention_days' => 30,
    'knowledge_failed_retention_days' => 14,
    'telemetry_enabled' => true,
    'conversation_retention_days' => 90,
    'failed_message_retention_days' => 7,
    'run_retention_days' => 90,
    'resolved_report_retention_days' => 90,
    'report_network_data_retention_days' => 30,
    'stale_stream_minutes' => 10,
    'stale_embedding_minutes' => 30,
    'storage_minimum_free_percent' => 10,
    'storage_minimum_free_mb' => 1024,
    'storage_maximum_upload_percent' => 85,
];
