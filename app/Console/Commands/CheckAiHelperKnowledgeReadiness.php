<?php

namespace App\Console\Commands;

use App\Models\AiHelperKnowledgeChunk;
use App\Models\AiHelperKnowledgeEntity;
use App\Models\AiHelperKnowledgeEntityAlias;
use App\Models\AiHelperKnowledgeEntry;
use App\Services\AiHelperEmbeddingService;
use App\Services\AiHelperKnowledgeProcessingService;
use App\Services\AiHelperMarkdownKnowledgeParser;
use App\Services\AiHelperReferenceCorpusCatalog;
use App\Services\AiHelperSystemGuideCatalog;
use App\Services\AiHelperWorkflowRegistry;
use App\Services\ModuleCatalog;
use App\Services\RoleCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class CheckAiHelperKnowledgeReadiness extends Command
{
    protected $signature = 'ai-helper:knowledge-readiness
        {--json : Emit machine-readable JSON}
        {--production : Require the complete fail-closed production configuration}';

    protected $description = 'Check reference knowledge and role-aware VMECC system-guide readiness.';

    public function handle(
        AiHelperSystemGuideCatalog $catalog,
        AiHelperMarkdownKnowledgeParser $parser,
        AiHelperEmbeddingService $embeddings,
        AiHelperWorkflowRegistry $workflows,
        AiHelperReferenceCorpusCatalog $referenceCatalog,
    ): int {
        $productionGate = (bool) $this->option('production');
        $schemaReady = collect([
            'knowledge_type', 'required_permissions', 'permission_match', 'allowed_roles',
            'module_gate', 'guide_owner', 'review_due_at', 'embedding_status',
        ])->every(fn (string $column) => Schema::hasColumn('ai_helper_knowledge_entries', $column))
            && Schema::hasColumn('ai_helper_knowledge_chunks', 'embedding');

        if (! $schemaReady) {
            return $this->render([
                'ready' => false,
                'release_gate' => $productionGate ? 'production' : 'uat',
                'reference_knowledge_ready' => false,
                'system_guides_ready' => false,
                'role_aware_retrieval_ready' => false,
                'retrieval' => ['schema_ready' => false],
            ]);
        }

        $semanticRequired = (bool) config('ai_helper.embedding_enabled', true)
            && trim((string) config('ai_helper.api_key')) !== '';
        $referenceCatalogError = null;
        try {
            $referenceSources = collect($referenceCatalog->sources());
            $orphanPdfFiles = $referenceCatalog->orphanPdfFiles();
            $expectedReferenceCount = $referenceSources->count();
        } catch (Throwable $exception) {
            $referenceSources = collect();
            $orphanPdfFiles = [];
            $expectedReferenceCount = max(1, (int) config('ai_helper.reference_corpus_expected_count', 35));
            $referenceCatalogError = $exception->getMessage();
        }
        $references = $this->entries(AiHelperKnowledgeEntry::KNOWLEDGE_REFERENCE_DOCUMENT);
        $referenceChunks = $this->chunks(AiHelperKnowledgeEntry::KNOWLEDGE_REFERENCE_DOCUMENT);
        $referenceEmbeddedChunks = $referenceChunks->whereNotNull('embedding')->count();
        $referenceCompatible = $semanticRequired
            ? $references->filter(fn (AiHelperKnowledgeEntry $entry) => $embeddings->isEntryCurrent($entry))->count()
            : $references->count();
        $referenceIndexed = $references->filter(fn (AiHelperKnowledgeEntry $entry) => $entry->active_chunks_count > 0)->count();
        $canonicalSourcePaths = $referenceSources->pluck('source_path')->filter()->all();
        $canonicalSources = $references->whereIn('source_path', $canonicalSourcePaths)->count();
        $pdfAttached = $references->whereNotNull('source_document_id')->count();
        $referencePayload = [
            'expected' => $expectedReferenceCount,
            'markdown_source_files' => $referenceSources->count(),
            'total' => $references->count(),
            'active' => $references->where('active', true)->count(),
            'approved' => $references->where('review_status', AiHelperKnowledgeEntry::REVIEW_APPROVED)->count(),
            'canonical_sources' => $canonicalSources,
            'pdf_attached' => $pdfAttached,
            'markdown_only' => max(0, $references->count() - $pdfAttached),
            // Kept for diagnostics compatibility; this is no longer a gate.
            'linked_to_pdf' => $pdfAttached,
            'orphan_pdf_files' => $orphanPdfFiles,
            'source_catalog_error' => $referenceCatalogError,
            'indexed' => $referenceIndexed,
            'status_active' => $references->where('status', AiHelperKnowledgeEntry::STATUS_ACTIVE)->count(),
            'embedded' => $references->where('embedding_status', 'ready')->count(),
            'missing_embeddings' => max(0, $referenceChunks->count() - $referenceEmbeddedChunks),
            'compatible_embeddings' => $referenceCompatible,
            'incompatible_embeddings' => max(0, $references->count() - $referenceCompatible),
            'processing' => $references->where('status', AiHelperKnowledgeEntry::STATUS_PROCESSING)->count(),
            'failed' => $references->where('status', AiHelperKnowledgeEntry::STATUS_FAILED)->count(),
            'reindex_errors' => $references->filter(fn (AiHelperKnowledgeEntry $entry) => trim((string) $entry->error) !== '')->count(),
        ];
        $referenceReady = $references->isNotEmpty()
            && $referenceCatalogError === null
            && $orphanPdfFiles === []
            && (! $productionGate || $references->count() === $expectedReferenceCount)
            && $referencePayload['active'] === $references->count()
            && $referencePayload['approved'] === $references->count()
            && $referencePayload['canonical_sources'] === $references->count()
            && $referencePayload['indexed'] === $references->count()
            && $referencePayload['status_active'] === $references->count()
            && $referencePayload['processing'] === 0
            && $referencePayload['failed'] === 0
            && $referencePayload['reindex_errors'] === 0
            && $referenceChunks->isNotEmpty()
            && (! $semanticRequired || (
                $referencePayload['missing_embeddings'] === 0
                && $referencePayload['incompatible_embeddings'] === 0
            ));

        $guides = $this->entries(AiHelperKnowledgeEntry::KNOWLEDGE_SYSTEM_GUIDE)
            ->filter(fn (AiHelperKnowledgeEntry $entry) => str_starts_with((string) $entry->source_path, 'seed:system-guide:'))
            ->values();
        $guideChunks = $this->chunks(AiHelperKnowledgeEntry::KNOWLEDGE_SYSTEM_GUIDE, true);
        $guideEmbeddedChunks = $guideChunks->whereNotNull('embedding')->count();
        $guideCompatible = $semanticRequired
            ? $guides->filter(fn (AiHelperKnowledgeEntry $entry) => $embeddings->isEntryCurrent($entry))->count()
            : $guides->count();
        $guideIndexed = $guides->filter(fn (AiHelperKnowledgeEntry $entry) => $entry->active_chunks_count > 0)->count();
        $sourceHashMatches = $guides->filter(function (AiHelperKnowledgeEntry $entry) use ($parser): bool {
            $key = Str::after((string) $entry->source_path, 'seed:system-guide:');
            $path = database_path("ai-helper-system-guides/{$key}.md");
            if (! is_file($path) || ! is_string($entry->content_hash)) {
                return false;
            }

            $parsed = $parser->parseFile($path, true);

            return hash_equals($entry->content_hash, hash('sha256', $parsed['content']));
        })->count();
        $expectedVersions = $guides->where('version', AiHelperSystemGuideCatalog::FINAL_VERSION)->count();
        $maintainerChunkViolations = $guideChunks->filter(fn (AiHelperKnowledgeChunk $chunk) => collect($chunk->heading_path ?? [])
            ->intersect(AiHelperKnowledgeProcessingService::SYSTEM_GUIDE_MAINTAINER_HEADINGS)
            ->isNotEmpty())->count();
        $validPermissions = $guides->filter(fn (AiHelperKnowledgeEntry $entry) => collect($entry->required_permissions ?? [])
            ->every(fn (string $permission) => $permission === '*' || in_array($permission, RoleCatalog::allPermissions(), true)))->count();
        $validRoles = $guides->filter(fn (AiHelperKnowledgeEntry $entry) => collect($entry->allowed_roles ?? [])
            ->every(fn (string $role) => in_array($role, RoleCatalog::ROLES, true)))->count();
        $validModules = $guides->filter(fn (AiHelperKnowledgeEntry $entry) => ModuleCatalog::has((string) $entry->module_gate))->count();
        $accessControlled = $guides->filter(fn (AiHelperKnowledgeEntry $entry) => ModuleCatalog::has((string) $entry->module_gate)
            && is_array($entry->required_permissions)
            && in_array($entry->permission_match, AiHelperKnowledgeEntry::PERMISSION_MATCHES, true))->count();
        $withinReview = $guides->filter(fn (AiHelperKnowledgeEntry $entry) => $entry->review_due_at?->isFuture() === true)->count();
        $validCatalogMetadata = $guides->filter(fn (AiHelperKnowledgeEntry $entry) => $catalog->matchesStoredMetadata($entry))->count();
        $legacyActive = AiHelperKnowledgeEntry::query()
            ->where('source_path', 'like', 'seed:%')
            ->where('source_path', 'not like', 'seed:ai_knowledge:%')
            ->where('source_path', 'not like', 'seed:system-guide:%')
            ->where(fn ($query) => $query->where('active', true)
                ->orWhere('status', '!=', AiHelperKnowledgeEntry::STATUS_DISABLED))
            ->count();
        $catalogErrors = $catalog->validateRegistry();
        if ((bool) config('ai_helper.product_workflows_enabled', false)) {
            $catalogErrors = array_merge($catalogErrors, $workflows->validationErrors());
        }
        $sourceFinal = 0;
        $sourceActive = 0;
        $verificationDossiers = 0;
        foreach ($catalog->keys() as $key) {
            try {
                $path = database_path("ai-helper-system-guides/{$key}.md");
                $parsed = $parser->parseFile($path, true);
                $metadata = $catalog->validate($parsed['frontmatter'], $parsed['content'], $path);
                $sourceFinal += $metadata['release_status'] === AiHelperSystemGuideCatalog::RELEASE_FINAL ? 1 : 0;
                $sourceActive += $metadata['active'] ? 1 : 0;
                $verificationDossiers += is_file(base_path("docs/ai-helper-system-guide-reviews/{$key}.md")) ? 1 : 0;
            } catch (Throwable $exception) {
                $catalogErrors[] = $key.': '.$exception->getMessage();
            }
        }
        $catalogErrors = array_values(array_unique($catalogErrors));
        $systemPayload = [
            'expected' => $catalog->expectedCount(),
            'total' => $guides->count(),
            'active' => $guides->where('active', true)->count(),
            'approved' => $guides->where('review_status', AiHelperKnowledgeEntry::REVIEW_APPROVED)->count(),
            'indexed' => $guideIndexed,
            'status_active' => $guides->where('status', AiHelperKnowledgeEntry::STATUS_ACTIVE)->count(),
            'access_controlled' => $accessControlled,
            'valid_permissions' => $validPermissions,
            'valid_roles' => $validRoles,
            'valid_module_gates' => $validModules,
            'within_review_period' => $withinReview,
            'valid_catalog_metadata' => $validCatalogMetadata,
            'source_final' => $sourceFinal,
            'source_active' => $sourceActive,
            'source_hash_matches' => $sourceHashMatches,
            'verification_dossiers' => $verificationDossiers,
            'expected_versions' => $expectedVersions,
            'maintainer_chunks_excluded' => $maintainerChunkViolations === 0,
            'maintainer_chunk_violations' => $maintainerChunkViolations,
            'embedded' => $guides->where('embedding_status', 'ready')->count(),
            'missing_embeddings' => max(0, $guideChunks->count() - $guideEmbeddedChunks),
            'compatible_embeddings' => $guideCompatible,
            'incompatible_embeddings' => max(0, $guides->count() - $guideCompatible),
            'processing' => $guides->where('status', AiHelperKnowledgeEntry::STATUS_PROCESSING)->count(),
            'failed' => $guides->where('status', AiHelperKnowledgeEntry::STATUS_FAILED)->count(),
            'reindex_errors' => $guides->filter(fn (AiHelperKnowledgeEntry $entry) => trim((string) $entry->error) !== '')->count(),
            'legacy_active' => $legacyActive,
            'catalog_errors' => $catalogErrors,
        ];
        $systemReady = $guides->count() === $catalog->expectedCount()
            && $systemPayload['active'] === $guides->count()
            && $systemPayload['approved'] === $guides->count()
            && $systemPayload['indexed'] === $guides->count()
            && $systemPayload['status_active'] === $guides->count()
            && $accessControlled === $guides->count()
            && $validPermissions === $guides->count()
            && $validRoles === $guides->count()
            && $validModules === $guides->count()
            && $withinReview === $guides->count()
            && $validCatalogMetadata === $guides->count()
            && $sourceFinal === $catalog->expectedCount()
            && $sourceActive === $catalog->expectedCount()
            && $sourceHashMatches === $guides->count()
            && $verificationDossiers === $catalog->expectedCount()
            && $expectedVersions === $guides->count()
            && $maintainerChunkViolations === 0
            && $systemPayload['processing'] === 0
            && $systemPayload['failed'] === 0
            && $systemPayload['reindex_errors'] === 0
            && $legacyActive === 0
            && $catalogErrors === []
            && $guideChunks->isNotEmpty()
            && (! $semanticRequired || (
                $systemPayload['missing_embeddings'] === 0
                && $systemPayload['incompatible_embeddings'] === 0
            ));

        $groundingMode = (string) config('ai_helper.grounding_verification_mode', 'disabled');
        $verificationAttempts = (int) config('ai_helper.verification_max_attempts', 2);
        $verificationValid = in_array($groundingMode, ['disabled', 'shadow', 'enforce'], true)
            && $verificationAttempts >= 1 && $verificationAttempts <= 2;
        $retrievalConfigurationValid = $this->retrievalConfigurationValid();
        $primaryModel = trim((string) config('ai_helper.model'));
        $embeddingModel = trim((string) config('ai_helper.embedding_model'));
        $providerConfigured = (bool) config('ai_helper.enabled', false)
            && trim((string) config('ai_helper.api_key')) !== ''
            && $primaryModel !== '';
        $systemGuidesEnabled = (bool) config('ai_helper.system_guides_enabled', false);
        $productWorkflowsEnabled = (bool) config('ai_helper.product_workflows_enabled', false);
        $finalCorpusEnforced = (bool) config('ai_helper.system_guide_final_corpus_enforced', true);
        $approvalEnforced = (bool) config('ai_helper.system_guide_approval_enforced', true);
        $productionConfigurationValid = $providerConfigured
            && $embeddingModel !== ''
            && (int) config('ai_helper.pipeline_version', 4) === 4
            && $systemGuidesEnabled
            && $productWorkflowsEnabled
            && $finalCorpusEnforced
            && $approvalEnforced
            && (bool) config('ai_helper.embedding_enabled', true)
            && (bool) config('ai_helper.rerank_enabled', false)
            && (bool) config('ai_helper.citation_validation_enabled', true)
            && (bool) config('ai_helper.critical_fact_validation_enabled', true)
            && $groundingMode === 'enforce'
            && $verificationValid
            && $retrievalConfigurationValid;
        $roleAwareReady = $catalogErrors === []
            && $legacyActive === 0
            && $systemReady;
        $entitySchemaReady = Schema::hasTable('ai_helper_knowledge_entities')
            && Schema::hasTable('ai_helper_knowledge_entity_aliases');
        $activeEntityCount = $entitySchemaReady
            ? AiHelperKnowledgeEntity::query()->where('active', true)->count()
            : 0;
        $activeAliasCount = $entitySchemaReady
            ? AiHelperKnowledgeEntityAlias::query()
                ->whereHas('entity', fn ($query) => $query->where('active', true))
                ->count()
            : 0;
        $entityIndexReady = $entitySchemaReady && $activeEntityCount > 0 && $activeAliasCount > 0;
        $ready = $referenceReady
            && $verificationValid
            && $retrievalConfigurationValid
            && $roleAwareReady
            && (! $productionGate || ($systemReady && $productionConfigurationValid));
        $deploymentState = match (true) {
            $ready && $systemGuidesEnabled && $finalCorpusEnforced && $approvalEnforced => 'production_ready',
            $systemReady && ! $systemGuidesEnabled => 'staged_disabled',
            default => 'incomplete',
        };

        return $this->render([
            'ready' => $ready,
            'release_gate' => $productionGate ? 'production' : 'uat',
            'reference_knowledge_ready' => $referenceReady,
            'system_guides_ready' => $systemReady,
            'role_aware_retrieval_ready' => $roleAwareReady,
            'entity_index_ready' => $entityIndexReady,
            'system_guides_runtime_enabled' => $systemGuidesEnabled,
            'product_workflows_runtime_enabled' => $productWorkflowsEnabled,
            'final_corpus_enforced' => $finalCorpusEnforced,
            'system_guide_approval_enforced' => $approvalEnforced,
            'deployment_state' => $deploymentState,
            'reference_knowledge' => $referencePayload,
            'system_guides' => $systemPayload,
            'entity_index' => [
                'schema_ready' => $entitySchemaReady,
                'active_entities' => $activeEntityCount,
                'active_aliases' => $activeAliasCount,
            ],
            'retrieval' => [
                'pipeline_version' => (int) config('ai_helper.pipeline_version', 4),
                'index_fingerprint' => $embeddings->indexFingerprint(),
                'schema_ready' => true,
                'verification_configuration_valid' => $verificationValid,
                'retrieval_configuration_valid' => $retrievalConfigurationValid,
                'production_configuration_valid' => $productionConfigurationValid,
                'provider_configured' => $providerConfigured,
                'primary_model' => $primaryModel,
                'embedding_model' => $embeddingModel,
            ],
        ]);
    }

    private function entries(string $type): Collection
    {
        return AiHelperKnowledgeEntry::query()
            ->where('knowledge_type', $type)
            ->whereNotIn('status', [AiHelperKnowledgeEntry::STATUS_DELETING, AiHelperKnowledgeEntry::STATUS_DELETED])
            ->withCount([
                'chunks as active_chunks_count' => fn ($query) => $query->where('active', true),
            ])
            ->get();
    }

    private function chunks(string $type, bool $codeControlledSystemGuidesOnly = false): Collection
    {
        return AiHelperKnowledgeChunk::query()
            ->where('active', true)
            ->whereHas('knowledgeEntry', function ($query) use ($type, $codeControlledSystemGuidesOnly) {
                $query->where('knowledge_type', $type);
                if ($codeControlledSystemGuidesOnly) {
                    $query->where('source_path', 'like', 'seed:system-guide:%');
                }
            })
            ->get(['id', 'embedding', 'heading_path']);
    }

    private function retrievalConfigurationValid(): bool
    {
        $lexical = (float) config('ai_helper.retrieval_min_lexical_coverage', 0.6);
        $semantic = (float) config('ai_helper.retrieval_min_semantic_similarity', 0.42);
        $rerank = (int) config('ai_helper.rerank_min_relevance', 1);
        $pipelineVersion = (int) config('ai_helper.pipeline_version', 4);

        return $lexical >= 0.0 && $lexical <= 1.0
            && $semantic >= 0.0 && $semantic <= 1.0
            && $rerank >= 0 && $rerank <= 3
            && (int) config('ai_helper.knowledge_document_candidate_limit', 12) > 0
            && (int) config('ai_helper.retrieval_candidate_chunks', 40) > 0
            && (int) config('ai_helper.knowledge_context_token_budget', 12000) > 0
            && $pipelineVersion === 4
            && (int) config('ai_helper.index_profile_version', 4) > 0
            && (int) config('ai_helper.retrieval_v4_document_candidate_limit', 18) > 0
            && (int) config('ai_helper.retrieval_v4_topic_candidate_limit', 6) > 0
            && (int) config('ai_helper.retrieval_v4_page_candidate_limit', 4) > 0
            && (int) config('ai_helper.retrieval_v4_global_candidate_limit', 12) > 0
            && (int) config('ai_helper.retrieval_v4_recovery_document_limit', 32) > 0;
    }

    private function render(array $payload): int
    {
        if ($this->option('json')) {
            $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES));
        } else {
            $this->components->twoColumnDetail('Release ready', $payload['ready'] ? '<fg=green>yes</>' : '<fg=red>no</>');
            $this->components->twoColumnDetail('Reference knowledge', ($payload['reference_knowledge_ready'] ?? false) ? '<fg=green>ready</>' : '<fg=red>not ready</>');
            $this->components->twoColumnDetail('System guides', ($payload['system_guides_ready'] ?? false) ? '<fg=green>ready</>' : '<fg=red>not ready</>');
            $this->components->twoColumnDetail('Role-aware retrieval', ($payload['role_aware_retrieval_ready'] ?? false) ? '<fg=green>ready</>' : '<fg=red>not ready</>');
        }

        return $payload['ready'] ? self::SUCCESS : self::FAILURE;
    }
}
