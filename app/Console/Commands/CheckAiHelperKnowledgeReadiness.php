<?php

namespace App\Console\Commands;

use App\Models\AiHelperKnowledgeChunk;
use App\Models\AiHelperKnowledgeEntry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class CheckAiHelperKnowledgeReadiness extends Command
{
    protected $signature = 'ai-helper:knowledge-readiness
        {--json : Emit machine-readable JSON}
        {--production : Require the complete fail-closed production configuration}';

    protected $description = 'Check the private Markdown knowledge corpus readiness.';

    public function handle(): int
    {
        $knowledgeQuery = AiHelperKnowledgeEntry::query()
            ->where('source_mime', 'text/markdown')
            ->whereNotIn('status', [
                AiHelperKnowledgeEntry::STATUS_DELETING,
                AiHelperKnowledgeEntry::STATUS_DELETED,
            ]);
        $retrievalSchemaReady = Schema::hasColumn('ai_helper_knowledge_entries', 'embedding_status')
            && Schema::hasColumn('ai_helper_knowledge_chunks', 'embedding');
        $counts = [
            'markdown_sources' => (clone $knowledgeQuery)->count(),
            'active' => (clone $knowledgeQuery)->where('active', true)->count(),
            'approved_active' => (clone $knowledgeQuery)
                ->where('active', true)
                ->where('status', AiHelperKnowledgeEntry::STATUS_ACTIVE)
                ->where('review_status', AiHelperKnowledgeEntry::REVIEW_APPROVED)
                ->count(),
            'processing' => (clone $knowledgeQuery)->where('status', AiHelperKnowledgeEntry::STATUS_PROCESSING)->count(),
            'failed' => (clone $knowledgeQuery)->where('status', AiHelperKnowledgeEntry::STATUS_FAILED)->count(),
            'linked_to_pdf' => (clone $knowledgeQuery)->whereNotNull('source_document_id')->count(),
            'semantic_ready' => $retrievalSchemaReady
                ? (clone $knowledgeQuery)->where('embedding_status', 'ready')->count()
                : 0,
        ];
        $usableChunkQuery = AiHelperKnowledgeChunk::query()
            ->where('active', true)
            ->whereHas('knowledgeEntry', fn ($query) => $query
                ->where('source_mime', 'text/markdown')
                ->where('active', true)
                ->where('review_status', AiHelperKnowledgeEntry::REVIEW_APPROVED)
                ->whereIn('status', [AiHelperKnowledgeEntry::STATUS_ACTIVE, AiHelperKnowledgeEntry::STATUS_PROCESSING]));
        $chunkCount = (clone $usableChunkQuery)->count();
        $embeddedChunkCount = $retrievalSchemaReady
            ? (clone $usableChunkQuery)->whereNotNull('embedding')->count()
            : 0;
        $semanticReady = $chunkCount > 0 && $chunkCount === $embeddedChunkCount;
        $semanticRequired = (bool) config('ai_helper.embedding_enabled', true)
            && trim((string) config('ai_helper.api_key')) !== '';
        $groundingVerificationMode = (string) config('ai_helper.grounding_verification_mode', 'disabled');
        $verificationAttempts = (int) config('ai_helper.verification_max_attempts', 2);
        $verificationConfigurationValid = in_array($groundingVerificationMode, ['disabled', 'shadow', 'enforce'], true)
            && $verificationAttempts >= 1
            && $verificationAttempts <= 2;
        $minimumLexicalCoverage = (float) config('ai_helper.retrieval_min_lexical_coverage', 0.6);
        $minimumSemanticSimilarity = (float) config('ai_helper.retrieval_min_semantic_similarity', 0.42);
        $rerankMinimumRelevance = (int) config('ai_helper.rerank_min_relevance', 1);
        $retrievalConfigurationValid = $minimumLexicalCoverage >= 0.0
            && $minimumLexicalCoverage <= 1.0
            && $minimumSemanticSimilarity >= 0.0
            && $minimumSemanticSimilarity <= 1.0
            && $rerankMinimumRelevance >= 0
            && $rerankMinimumRelevance <= 3
            && (int) config('ai_helper.knowledge_document_candidate_limit', 12) > 0
            && (int) config('ai_helper.retrieval_candidate_chunks', 40) > 0
            && (int) config('ai_helper.knowledge_context_token_budget', 12000) > 0;
        $corpusReady = $counts['markdown_sources'] > 0
            && $counts['active'] === $counts['markdown_sources']
            && $counts['approved_active'] === $counts['markdown_sources']
            && $counts['processing'] === 0
            && $counts['failed'] === 0
            && $counts['linked_to_pdf'] === $counts['markdown_sources']
            && $chunkCount > 0;
        $providerConfigured = (bool) config('ai_helper.enabled', false)
            && trim((string) config('ai_helper.api_key')) !== '';
        $productionConfigurationValid = $providerConfigured
            && (bool) config('ai_helper.retrieval_v2', true)
            && (bool) config('ai_helper.retrieval_v3', false)
            && (bool) config('ai_helper.embedding_enabled', true)
            && (bool) config('ai_helper.rerank_enabled', false)
            && (bool) config('ai_helper.citation_validation_enabled', true)
            && (bool) config('ai_helper.critical_fact_validation_enabled', true)
            && $groundingVerificationMode === 'enforce'
            && $verificationConfigurationValid
            && $retrievalConfigurationValid;
        $productionGate = (bool) $this->option('production');
        $ready = $retrievalSchemaReady
            && $corpusReady
            && $verificationConfigurationValid
            && $retrievalConfigurationValid
            && (! $semanticRequired || $semanticReady)
            && (! $productionGate || ($productionConfigurationValid && $semanticReady));
        $payload = [
            'ready' => $ready,
            'release_gate' => $productionGate ? 'production' : 'uat',
            'runtime' => [
                'mode' => 'markdown_only',
                'pdf_ingestion_enabled' => false,
                'external_ocr_required' => false,
            ],
            'knowledge' => $counts,
            'retrieval' => [
                'mode' => (bool) config('ai_helper.retrieval_v2', true) ? 'hybrid' : 'legacy',
                'pipeline_version' => (bool) config('ai_helper.retrieval_v3', false) ? 3 : 2,
                'rerank_enabled' => (bool) config('ai_helper.rerank_enabled', false),
                'critical_fact_validation_enabled' => (bool) config('ai_helper.critical_fact_validation_enabled', true),
                'grounding_verification_mode' => $groundingVerificationMode,
                'verification_configuration_valid' => $verificationConfigurationValid,
                'retrieval_configuration_valid' => $retrievalConfigurationValid,
                'production_configuration_valid' => $productionConfigurationValid,
                'provider_configured' => $providerConfigured,
                'minimum_lexical_coverage' => $minimumLexicalCoverage,
                'minimum_semantic_similarity' => $minimumSemanticSimilarity,
                'rerank_minimum_relevance' => $rerankMinimumRelevance,
                'schema_ready' => $retrievalSchemaReady,
                'chunks' => $chunkCount,
                'embedded_chunks' => $embeddedChunkCount,
                'missing_embeddings' => max(0, $chunkCount - $embeddedChunkCount),
                'semantic_ready' => $semanticReady,
            ],
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES));
        } else {
            $this->components->twoColumnDetail('Release ready', $ready ? '<fg=green>yes</>' : '<fg=red>no</>');
            $this->components->twoColumnDetail('Release gate', $productionGate ? 'production' : 'UAT');
            $this->components->twoColumnDetail('Knowledge mode', 'Markdown only (PDF ingestion disabled)');
            $this->components->twoColumnDetail('Retrieval schema ready', $retrievalSchemaReady ? '<fg=green>yes</>' : '<fg=red>no - run migrations</>');
            $this->components->twoColumnDetail('Corpus ready', $corpusReady ? '<fg=green>yes</>' : '<fg=red>no</>');
            $this->components->twoColumnDetail('Retrieval configuration', $retrievalConfigurationValid ? '<fg=green>valid</>' : '<fg=red>invalid</>');
            if ($productionGate) {
                $this->components->twoColumnDetail('Production configuration', $productionConfigurationValid ? '<fg=green>valid</>' : '<fg=red>invalid</>');
            }
            foreach ($counts as $label => $count) {
                $this->components->twoColumnDetail(str_replace('_', ' ', ucfirst($label)), (string) $count);
            }
        }

        return $ready ? self::SUCCESS : self::FAILURE;
    }
}
