<?php

namespace App\Console\Commands;

use App\Models\AiHelperKnowledgeChunk;
use App\Models\AiHelperKnowledgeEntry;
use App\Services\AiHelperKnowledgeProcessingService;
use App\Services\AiHelperSystemGuideCatalog;
use App\Services\ModuleCatalog;
use App\Services\RoleCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class CheckAiHelperKnowledgeReadiness extends Command
{
    private const EXPECTED_REFERENCE_COUNT = 34;

    protected $signature = 'ai-helper:knowledge-readiness
        {--json : Emit machine-readable JSON}
        {--production : Require the complete fail-closed production configuration}';

    protected $description = 'Check reference knowledge and role-aware VMECC system-guide readiness.';

    public function handle(AiHelperSystemGuideCatalog $catalog): int
    {
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
        $references = $this->entries(AiHelperKnowledgeEntry::KNOWLEDGE_REFERENCE_DOCUMENT);
        $referenceChunks = $this->chunks(AiHelperKnowledgeEntry::KNOWLEDGE_REFERENCE_DOCUMENT);
        $referenceEmbeddedChunks = $referenceChunks->whereNotNull('embedding')->count();
        $referenceIndexed = $references->filter(fn (AiHelperKnowledgeEntry $entry) => $entry->active_chunks_count > 0)->count();
        $referencePayload = [
            'expected' => self::EXPECTED_REFERENCE_COUNT,
            'total' => $references->count(),
            'active' => $references->where('active', true)->count(),
            'approved' => $references->where('review_status', AiHelperKnowledgeEntry::REVIEW_APPROVED)->count(),
            'linked_to_pdf' => $references->whereNotNull('source_document_id')->count(),
            'indexed' => $referenceIndexed,
            'status_active' => $references->where('status', AiHelperKnowledgeEntry::STATUS_ACTIVE)->count(),
            'embedded' => $references->where('embedding_status', 'ready')->count(),
            'missing_embeddings' => max(0, $referenceChunks->count() - $referenceEmbeddedChunks),
            'processing' => $references->where('status', AiHelperKnowledgeEntry::STATUS_PROCESSING)->count(),
            'failed' => $references->where('status', AiHelperKnowledgeEntry::STATUS_FAILED)->count(),
        ];
        $referenceReady = $references->isNotEmpty()
            && (! $productionGate || $references->count() === self::EXPECTED_REFERENCE_COUNT)
            && $referencePayload['active'] === $references->count()
            && $referencePayload['approved'] === $references->count()
            && $referencePayload['linked_to_pdf'] === $references->count()
            && $referencePayload['indexed'] === $references->count()
            && $referencePayload['status_active'] === $references->count()
            && $referencePayload['processing'] === 0
            && $referencePayload['failed'] === 0
            && $referenceChunks->isNotEmpty()
            && (! $semanticRequired || $referencePayload['missing_embeddings'] === 0);

        $guides = $this->entries(AiHelperKnowledgeEntry::KNOWLEDGE_SYSTEM_GUIDE)
            ->filter(fn (AiHelperKnowledgeEntry $entry) => str_starts_with((string) $entry->source_path, 'seed:system-guide:'))
            ->values();
        $guideChunks = $this->chunks(AiHelperKnowledgeEntry::KNOWLEDGE_SYSTEM_GUIDE, true);
        $guideEmbeddedChunks = $guideChunks->whereNotNull('embedding')->count();
        $guideIndexed = $guides->filter(fn (AiHelperKnowledgeEntry $entry) => $entry->active_chunks_count > 0)->count();
        $approvalHashMatches = $guides->filter(fn (AiHelperKnowledgeEntry $entry) => $catalog->approvalMatchesEntry($entry))->count();
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
            'approval_hash_matches' => $approvalHashMatches,
            'expected_versions' => $expectedVersions,
            'maintainer_chunks_excluded' => $maintainerChunkViolations === 0,
            'maintainer_chunk_violations' => $maintainerChunkViolations,
            'embedded' => $guides->where('embedding_status', 'ready')->count(),
            'missing_embeddings' => max(0, $guideChunks->count() - $guideEmbeddedChunks),
            'processing' => $guides->where('status', AiHelperKnowledgeEntry::STATUS_PROCESSING)->count(),
            'failed' => $guides->where('status', AiHelperKnowledgeEntry::STATUS_FAILED)->count(),
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
            && $approvalHashMatches === $guides->count()
            && $expectedVersions === $guides->count()
            && $maintainerChunkViolations === 0
            && $systemPayload['processing'] === 0
            && $systemPayload['failed'] === 0
            && $legacyActive === 0
            && $catalogErrors === []
            && $guideChunks->isNotEmpty()
            && (! $semanticRequired || $systemPayload['missing_embeddings'] === 0);

        $groundingMode = (string) config('ai_helper.grounding_verification_mode', 'disabled');
        $verificationAttempts = (int) config('ai_helper.verification_max_attempts', 2);
        $verificationValid = in_array($groundingMode, ['disabled', 'shadow', 'enforce'], true)
            && $verificationAttempts >= 1 && $verificationAttempts <= 2;
        $retrievalConfigurationValid = $this->retrievalConfigurationValid();
        $providerConfigured = (bool) config('ai_helper.enabled', false)
            && trim((string) config('ai_helper.api_key')) !== '';
        $productionConfigurationValid = $providerConfigured
            && (bool) config('ai_helper.retrieval_v2', true)
            && (bool) config('ai_helper.retrieval_v3', false)
            && (bool) config('ai_helper.embedding_enabled', true)
            && (bool) config('ai_helper.rerank_enabled', false)
            && (bool) config('ai_helper.citation_validation_enabled', true)
            && (bool) config('ai_helper.critical_fact_validation_enabled', true)
            && $groundingMode === 'enforce'
            && $verificationValid
            && $retrievalConfigurationValid;
        $roleAwareReady = $catalogErrors === [] && $legacyActive === 0
            && (! (bool) config('ai_helper.system_guides_enabled', false) || $systemReady);
        $ready = $referenceReady
            && $verificationValid
            && $retrievalConfigurationValid
            && $roleAwareReady
            && (! $productionGate || ($systemReady && $productionConfigurationValid));

        return $this->render([
            'ready' => $ready,
            'release_gate' => $productionGate ? 'production' : 'uat',
            'reference_knowledge_ready' => $referenceReady,
            'system_guides_ready' => $systemReady,
            'role_aware_retrieval_ready' => $roleAwareReady,
            'reference_knowledge' => $referencePayload,
            'system_guides' => $systemPayload,
            'retrieval' => [
                'pipeline_version' => (bool) config('ai_helper.retrieval_v3', false) ? 3 : 2,
                'schema_ready' => true,
                'verification_configuration_valid' => $verificationValid,
                'retrieval_configuration_valid' => $retrievalConfigurationValid,
                'production_configuration_valid' => $productionConfigurationValid,
                'provider_configured' => $providerConfigured,
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

        return $lexical >= 0.0 && $lexical <= 1.0
            && $semantic >= 0.0 && $semantic <= 1.0
            && $rerank >= 0 && $rerank <= 3
            && (int) config('ai_helper.knowledge_document_candidate_limit', 12) > 0
            && (int) config('ai_helper.retrieval_candidate_chunks', 40) > 0
            && (int) config('ai_helper.knowledge_context_token_budget', 12000) > 0;
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
