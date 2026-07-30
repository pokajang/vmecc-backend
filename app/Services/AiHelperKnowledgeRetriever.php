<?php

namespace App\Services;

use App\Models\AiHelperKnowledgeChunk;
use App\Models\AiHelperKnowledgeEntry;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class AiHelperKnowledgeRetriever
{
    public function __construct(
        private readonly AiHelperKnowledgeQueryAnalyzer $analyzer,
        private readonly AiHelperEmbeddingService $embeddings,
        private readonly AiHelperRetrievalRankFusion $rankFusion,
        private readonly AiHelperPassageReranker $reranker,
        private readonly AiHelperKnowledgeAudienceResolver $audienceResolver,
        private readonly AiHelperDocumentCandidateSelector $candidateSelector,
        private readonly AiHelperTopicAliasRegistry $topicAliases,
        private readonly AiHelperSystemGuideCatalog $systemGuides,
        private readonly AiHelperEvidenceAdequacyValidator $evidenceAdequacy,
        private readonly AiHelperKnowledgeEntityResolver $entityResolver,
    ) {}

    /** @return array{analysis: array<string, mixed>, guidance: array<int, array<string, mixed>>, trace: array<string, mixed>} */
    public function retrieve(
        array $context,
        ?User $user,
        string $message,
        array $previousUserMessages = [],
        bool $forceRecovery = false,
        ?AiHelperRequestDeadline $deadline = null,
        ?string $safetyIdentifier = null,
    ): array {
        $startedAt = microtime(true);
        $analysis = $this->analyzer->analyze($message, $previousUserMessages);
        if ($analysis['sensitive_request'] ?? false) {
            return ['analysis' => $analysis, 'guidance' => [], 'trace' => [
                'mode' => 'blocked_sensitive',
                'documents_considered' => 0,
                'documents_selected' => 0,
                'chunks_selected' => 0,
                'token_estimate' => 0,
                'semantic_fallback' => false,
                'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
            ]];
        }
        if ($analysis['intent'] === 'catalogue') {
            return ['analysis' => $analysis, 'guidance' => [], 'trace' => [
                'mode' => 'catalogue',
                'documents_considered' => 0,
                'documents_selected' => 0,
                'chunks_selected' => 0,
                'token_estimate' => 0,
                'semantic_fallback' => false,
                'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
            ]];
        }
        $retrievalPolicy = (string) ($analysis['retrieval_policy']
            ?? (($analysis['evidence_required'] ?? true) ? 'required' : 'none'));
        if ($retrievalPolicy === 'none') {
            return ['analysis' => $analysis, 'guidance' => [], 'trace' => [
                'mode' => (string) ($analysis['answer_mode'] ?? 'general_conversation'),
                'retrieval_policy' => $retrievalPolicy,
                'probe_attempted' => false,
                'documents_considered' => 0,
                'documents_selected' => 0,
                'chunks_selected' => 0,
                'token_estimate' => 0,
                'semantic_fallback' => false,
                'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
            ]];
        }

        $audience = $this->audienceResolver->resolve($user, $context);
        $rankingContext = $context;
        // Page metadata supplied by the browser is never trusted directly. An
        // unknown path receives no route/module ranking boost.
        $rankingContext['route_key'] = $audience->routeKey;
        $rankingContext['module_key'] = $audience->moduleKey;
        $authorizedIds = $this->authorizedEntryIds($user, $audience, $analysis);
        $entityResolution = $this->entityResolver->resolve((string) $analysis['query'], $authorizedIds);
        $analysis['entity_entry_ids'] = $this->preferredEntityEntryIds(
            (string) $analysis['query'],
            $entityResolution['matches'],
        );
        if ($entityResolution['matches'] !== [] && ($analysis['resolved_entities'] ?? []) === []) {
            $analysis = $this->mergeCorpusEntities($analysis, $entityResolution);
            $retrievalPolicy = 'required';
        }
        $entries = AiHelperKnowledgeEntry::query()
            ->whereKey($authorizedIds)
            ->with('sourceDocument:id,title,source_filename')
            ->get();
        $semanticCompatibleIds = $entries
            ->filter(fn (AiHelperKnowledgeEntry $entry) => $this->embeddings->isEntryVectorCurrent($entry))
            ->pluck('id')
            ->mapWithKeys(fn ($id) => [(int) $id => true]);
        $queryEmbedding = null;
        $semanticFallback = false;
        if ($semanticCompatibleIds->isNotEmpty()) {
            try {
                $queryEmbedding = $this->embeddings->embedQuery((string) $analysis['query'], $deadline);
                $semanticFallback = $queryEmbedding === null;
            } catch (\Throwable) {
                $semanticFallback = true;
            }
        } elseif ((bool) config('ai_helper.embedding_enabled', true) && $entries->isNotEmpty()) {
            $semanticFallback = true;
        }

        $pipelineVersion = $this->pipelineVersion();
        $retrievalV4 = $pipelineVersion >= 4;
        $rankedDocuments = $entries->map(function (AiHelperKnowledgeEntry $entry) use ($analysis, $rankingContext, $queryEmbedding, $retrievalV4, $semanticCompatibleIds) {
            $topicScore = $retrievalV4 ? $this->documentTopicScore($entry, $analysis) : 0;
            $matchedTopicKeys = $retrievalV4
                ? $this->topicAliases->matchedTopicKeys(
                    $this->documentIdentity($entry),
                    is_array($analysis['topic_keys'] ?? null) ? $analysis['topic_keys'] : [],
                )
                : [];
            $operationScore = $retrievalV4 ? $this->documentOperationScore($entry, $analysis) : 0;
            $taskScore = $retrievalV4 ? $this->documentTaskScore($entry, $analysis) : 0;
            $taskConflict = $retrievalV4 && $this->documentTaskConflict($entry, $analysis);
            $entityConflict = $retrievalV4 && $this->documentEntityConflict($entry, $analysis);
            $entityMatch = in_array(
                (int) $entry->id,
                array_map('intval', (array) ($analysis['entity_entry_ids'] ?? [])),
                true,
            );
            $exactMatch = $this->isExactDocumentMatch($entry, $analysis);
            $pageMatch = $this->isPageMatch($entry, $rankingContext);
            $semanticCompatible = $semanticCompatibleIds->has((int) $entry->id);
            $documentQueryEmbedding = $semanticCompatible ? $queryEmbedding : null;

            return [
                'entry' => $entry,
                'score' => $this->documentScore($entry, $analysis, $rankingContext, $documentQueryEmbedding),
                'global_score' => $this->documentScore($entry, $analysis, [], $documentQueryEmbedding),
                'exact_match' => $exactMatch,
                'topic_score' => $topicScore,
                'matched_topic_keys' => $matchedTopicKeys,
                'topic_coverage' => $this->topicCoverage($matchedTopicKeys, $analysis),
                'operation_score' => $operationScore,
                'task_score' => $taskScore,
                'task_conflict' => $taskConflict,
                'entity_conflict' => $entityConflict,
                'entity_match' => $entityMatch,
                'page_match' => $pageMatch,
                'semantic_compatible' => $semanticCompatible,
                'protected_match' => $exactMatch || $entityMatch || $taskScore > 0 || ($topicScore > 0
                    && ($analysis['task_keys'] ?? []) === []
                    && in_array($analysis['context_dependency'] ?? null, ['explicit_topic', 'mixed'], true))
                    || ($pageMatch && ($analysis['context_dependency'] ?? null) === 'page_deictic'),
            ];
        })->filter(fn (array $document) => ! ($document['entity_conflict'] ?? false)
            && (! ($document['task_conflict'] ?? false) || ($document['exact_match'] ?? false)))
            ->sortByDesc('score')->values();
        $documentLimit = max(1, (int) config('ai_helper.knowledge_document_candidate_limit', 12));
        $exactDocuments = $rankedDocuments->where('exact_match', true)->values();
        $candidateLanes = ['exact' => $exactDocuments->count(), 'topic' => 0, 'global' => 0, 'page' => 0];
        if ($retrievalV4) {
            $selection = $this->candidateSelector->select($rankedDocuments, $analysis);
            $selectedDocuments = $selection['documents'];
            $candidateLanes = $selection['lanes'];
        } else {
            $selectedDocuments = $exactDocuments->isNotEmpty()
                ? $exactDocuments->take(max($documentLimit, 12))->values()
                : $rankedDocuments->take($documentLimit);
        }
        $selectedDocuments = $this->hydrateSelectedDocuments($selectedDocuments);

        $rankedChunks = $this->chunkCandidates($selectedDocuments, $analysis, $queryEmbedding);
        if ($exactDocuments->isEmpty()) {
            $rankedChunks = $rankedChunks
                ->filter(fn (array $candidate) => ($retrievalV4 && ($candidate['protected_match'] ?? false))
                    || $this->isRelevantCandidate($candidate, $analysis))
                ->values();
        }
        $initialAdequacy = $this->evidenceAdequacy->assessCandidates($rankedChunks, $analysis);
        $rankedChunks = $initialAdequacy['candidates'];
        $recoveryAttempted = false;
        $recoverySucceeded = false;
        $scopeRecoveryReason = null;
        $shouldAttemptScopeRecovery = $retrievalV4
            && ! $forceRecovery
            && ($analysis['query_scope'] ?? null) === 'local'
            && ($analysis['context_dependency'] ?? null) !== 'page_deictic'
            && $rankedDocuments->isNotEmpty()
            && $rankedChunks->isNotEmpty()
            && $this->isScopeRecoveryHelpful($analysis, $rankedChunks, $exactDocuments, $selectedDocuments);
        if ($retrievalV4 && ($forceRecovery || $rankedChunks->isEmpty() || $shouldAttemptScopeRecovery) && $rankedDocuments->isNotEmpty()) {
            $recoveryAttempted = true;
            $recoveryAnalysis = $analysis;
            if ($shouldAttemptScopeRecovery) {
                $scopeRecoveryReason = $this->scopeRecoveryReason($analysis, $rankedChunks);
                $recoveryAnalysis['query_scope'] = 'global';
                $recoveryAnalysis['scope_adjustment_hint'] = 'global_recovery';
            }
            $recoveryAnalysis['terms'] = collect([
                ...(array) ($analysis['terms'] ?? []),
                ...(array) ($analysis['expanded_terms'] ?? []),
            ])->filter()->unique()->take(40)->values()->all();
            $recoveryLimit = max(
                (int) config('ai_helper.retrieval_v4_document_candidate_limit', 18),
                (int) config('ai_helper.retrieval_v4_recovery_document_limit', 32),
            );
            $recoveryDocuments = $rankedDocuments
                ->sortByDesc('global_score')
                ->take($recoveryLimit)
                ->values();
            $recoveryDocuments = $this->hydrateSelectedDocuments($recoveryDocuments);
            if ($recoveryAttempted && $forceRecovery) {
                $scopeRecoveryReason = 'forced_recovery';
            } elseif (! $scopeRecoveryReason) {
                $scopeRecoveryReason = $rankedChunks->isEmpty() ? 'empty_local_evidence' : $scopeRecoveryReason;
            }
            $recoveryChunks = $this->chunkCandidates($recoveryDocuments, $recoveryAnalysis, $queryEmbedding)
                ->filter(fn (array $candidate) => ($candidate['protected_match'] ?? false)
                    || $this->isRelevantCandidate($candidate, $recoveryAnalysis))
                ->values();
            $recoveryAdequacy = $this->evidenceAdequacy->assessCandidates($recoveryChunks, $recoveryAnalysis);
            $recoveryChunks = $recoveryAdequacy['candidates'];
            if ($recoveryChunks->isNotEmpty()) {
                $rankedChunks = $recoveryChunks;
                $selectedDocuments = $recoveryDocuments;
                $recoverySucceeded = true;
            }
        }
        $retrievalV3 = $pipelineVersion >= 3;
        $rankedChunks = $retrievalV3
            ? $this->rankChunksWithFusion($rankedChunks)
            : $rankedChunks->sortByDesc('score')->values();
        if ($exactDocuments->count() > 1) {
            $rankedChunks = $this->prioritizeExactDocumentRepresentatives($rankedChunks, $exactDocuments);
        }
        $rankedChunks = $this->prioritizeProtectedEvidenceRepresentatives($rankedChunks, $analysis);
        $rankedChunks = $this->prioritizeEvidenceRepresentative(
            $rankedChunks,
            $rankedChunks,
            $analysis,
        );
        $candidateChunkLimit = max(
            (int) config('ai_helper.knowledge_retrieval_limit', 18),
            (int) config('ai_helper.retrieval_candidate_chunks', 40),
        );
        $rankedChunks = $rankedChunks->take($candidateChunkLimit)->values();
        $preRerankCandidates = $rankedChunks;
        $rerankMetadata = ['enabled' => false, 'status' => 'not_run', 'fallback' => false];
        if ($retrievalV3) {
            $rerankAnalysis = $analysis;
            $protectedEntryCount = $rankedChunks
                ->filter(fn (array $candidate) => (bool) ($candidate['protected_match'] ?? false))
                ->pluck('entry.id')
                ->unique()
                ->count();
            $rerankAnalysis['skip_rerank'] = $retrievalV4
                && (bool) config('ai_helper.rerank_adaptive', true)
                && (
                    $exactDocuments->count() === 1
                    || ($protectedEntryCount === 1
                        && ($analysis['context_dependency'] ?? null) === 'explicit_topic')
                );
            $reranked = $this->reranker->rerank(
                (string) $analysis['query'],
                $rerankAnalysis,
                $rankedChunks,
                $deadline,
                $safetyIdentifier,
            );
            $rankedChunks = $reranked['candidates'];
            $rerankMetadata = $reranked['metadata'];
            if ($exactDocuments->count() > 1) {
                $rankedChunks = $this->ensureExactDocumentCoverage(
                    $rankedChunks,
                    $preRerankCandidates,
                    $exactDocuments,
                );
            }
        }
        $subqueryCoverage = $retrievalV3
            ? $this->prioritizeSubqueryCoverage($rankedChunks, $analysis)
            : ['candidates' => $rankedChunks, 'requested' => 1, 'covered' => 1];
        $rankedChunks = $this->prioritizeProtectedEvidenceRepresentatives(
            $subqueryCoverage['candidates'],
            $analysis,
        );
        $rankedChunks = $this->prioritizeEvidenceRepresentative(
            $rankedChunks,
            $preRerankCandidates,
            $analysis,
        );

        $limit = max(1, (int) config('ai_helper.knowledge_retrieval_limit', 18));
        $perDocument = max(1, (int) config('ai_helper.knowledge_max_chunks_per_document', 4));
        if ($exactDocuments->isNotEmpty()) {
            $perDocument = max($perDocument, (int) config('ai_helper.knowledge_exact_document_chunk_limit', 12));
        }
        $budget = max(1000, (int) config('ai_helper.knowledge_context_token_budget', 12000));
        $adjacentWindow = max(0, (int) config('ai_helper.knowledge_adjacent_chunk_window', 1));
        $selected = collect();
        $selectedIds = [];
        $perDocumentCounts = [];
        $tokens = 0;

        foreach ($rankedChunks as $candidate) {
            $chunk = $candidate['chunk'];
            $entryId = (int) $candidate['entry']->id;
            if (($perDocumentCounts[$entryId] ?? 0) >= $perDocument || isset($selectedIds[$chunk->id])) {
                continue;
            }
            foreach ($this->candidateWithNeighbours($candidate, $adjacentWindow) as $expanded) {
                $expandedChunk = $expanded['chunk'];
                $estimate = max(1, (int) ($expandedChunk->token_estimate ?: ceil(Str::length($expandedChunk->content) / 4)));
                if (isset($selectedIds[$expandedChunk->id]) || ($selected->isNotEmpty() && $tokens + $estimate > $budget)) {
                    continue;
                }
                $selected->push($expanded);
                $selectedIds[$expandedChunk->id] = true;
                $perDocumentCounts[$entryId] = ($perDocumentCounts[$entryId] ?? 0) + 1;
                $tokens += $estimate;
                if ($selected->count() >= $limit || ($perDocumentCounts[$entryId] ?? 0) >= $perDocument) {
                    break;
                }
            }
            if ($selected->count() >= $limit || $tokens >= $budget) {
                break;
            }
        }

        $sourceIds = [];
        $nextSourceNumber = 1;
        $guidance = $selected->values()
            ->map(function (array $item) use ($rankingContext, &$sourceIds, &$nextSourceNumber) {
                $key = $item['entry']->knowledge_type === AiHelperKnowledgeEntry::KNOWLEDGE_REFERENCE_DOCUMENT
                    ? implode(':', [
                        'reference',
                        $item['entry']->source_document_id
                            ? 'document-'.(int) $item['entry']->source_document_id
                            : 'entry-'.(int) $item['entry']->id,
                        (int) ($item['chunk']->page_start ?? 0),
                        (int) ($item['chunk']->page_end ?? 0),
                    ])
                    : implode(':', ['entry', (int) $item['entry']->id]);
                if (! isset($sourceIds[$key])) {
                    $sourceIds[$key] = 'S'.$nextSourceNumber++;
                }

                return $this->formatGuidance($item, $rankingContext, $sourceIds[$key]);
            })
            ->all();

        $probePromoted = false;
        if ($retrievalPolicy === 'probe') {
            $probeLexicalThreshold = max(
                0.0,
                min(1.0, (float) config('ai_helper.retrieval_probe_min_lexical_coverage', 0.5)),
            );
            $probeSemanticThreshold = max(
                0.0,
                min(1.0, (float) config('ai_helper.retrieval_probe_min_semantic_similarity', 0.52)),
            );
            $probePromoted = $guidance !== [] && (
                (float) ($rankedChunks->max('lexical_coverage') ?? 0) >= $probeLexicalThreshold
                || (float) ($rankedChunks->max('semantic_score') ?? 0) >= $probeSemanticThreshold
            );
            if ($probePromoted) {
                $analysis['answer_mode'] = 'operational_knowledge';
                $analysis['evidence_required'] = true;
                $analysis['retrieval_policy'] = 'required';
            } else {
                $guidance = [];
                $selected = collect();
                $tokens = 0;
                if (($analysis['unknown_acronyms'] ?? []) !== []) {
                    $analysis['answer_mode'] = 'operational_knowledge';
                    $analysis['evidence_required'] = true;
                    $analysis['retrieval_policy'] = 'required';
                    $analysis['clarification_required'] = true;
                    $analysis['clarification_reason'] = 'unknown_acronym_not_found';
                }
            }
        }

        return ['analysis' => $analysis, 'guidance' => $guidance, 'trace' => [
            'pipeline_version' => $pipelineVersion,
            'mode' => $queryEmbedding ? 'hybrid' : 'lexical',
            'retrieval_policy' => $retrievalPolicy,
            'probe_attempted' => $retrievalPolicy === 'probe',
            'probe_promoted' => $probePromoted,
            'documents_considered' => $entries->count(),
            'documents_selected' => $selected->pluck('entry.id')->unique()->count(),
            'document_ids' => $selected->pluck('entry.id')->unique()->values()->all(),
            'chunk_ids' => $selected->pluck('chunk.id')->values()->all(),
            'chunks_selected' => $selected->count(),
            'candidate_chunks' => $rankedChunks->count(),
            'max_lexical_coverage' => (float) ($rankedChunks->max('lexical_coverage') ?? 0),
            'max_semantic_similarity' => (float) ($rankedChunks->max('semantic_score') ?? 0),
            'relevance_gate' => $exactDocuments->isNotEmpty()
                ? 'exact_document_bypass'
                : ($rankedChunks->isEmpty() ? 'no_relevant_evidence' : 'passed'),
            'subqueries_requested' => $subqueryCoverage['requested'],
            'subqueries_covered' => $subqueryCoverage['covered'],
            'token_estimate' => $tokens,
            'semantic_fallback' => $semanticFallback,
            'rerank' => $rerankMetadata,
            'entity_resolution' => [
                'matches' => collect($entityResolution['matches'])->map(fn (array $match) => [
                    'canonical_name' => $match['canonical_name'],
                    'entity_type' => $match['entity_type'],
                    'matched_aliases' => $match['matched_aliases'],
                ])->values()->all(),
                'ambiguous_aliases' => $entityResolution['ambiguous_aliases'],
            ],
            'evidence_adequacy' => [
                'status' => $initialAdequacy['status'],
                'reason' => $initialAdequacy['reason'],
                'matched_entities' => $initialAdequacy['matched_entities'],
                'requested_facets' => $initialAdequacy['requested_facets'],
            ],
            'query_plan' => [
                'intent' => $analysis['intent'] ?? null,
                'source_mode' => $analysis['source_mode'] ?? null,
                'context_dependency' => $analysis['context_dependency'] ?? null,
                'language' => $analysis['language'] ?? null,
                'intent_scope' => $analysis['intent_scope'] ?? 'local',
                'cross_module_required' => (bool) ($analysis['cross_module_required'] ?? false),
                'entities_explicit' => (bool) ($analysis['entities_explicit'] ?? false),
                'requires_global_context' => (bool) ($analysis['requires_global_context'] ?? false),
                'topic_keys' => $analysis['topic_keys'] ?? [],
                'operation_keys' => $analysis['operation_keys'] ?? [],
                'task_keys' => $analysis['task_keys'] ?? [],
                'clarification_required' => (bool) ($analysis['clarification_required'] ?? false),
                'clarification_reason' => $analysis['clarification_reason'] ?? null,
                'clarification_option_keys' => $analysis['clarification_option_keys'] ?? [],
                'query_scope' => $analysis['query_scope'] ?? null,
                'scope_adjustment_hint' => $analysis['scope_adjustment_hint'] ?? 'none',
                'requires_multiple_documents' => (bool) ($analysis['requires_multiple_documents'] ?? false),
                'follow_up' => (bool) ($analysis['follow_up'] ?? false),
                'follow_up_confidence' => $analysis['follow_up_confidence'] ?? 'none',
            ],
            'candidate_lanes' => $candidateLanes,
            'recovery_attempted' => $recoveryAttempted,
            'recovery_succeeded' => $recoverySucceeded,
            'scope_recovery' => $scopeRecoveryReason !== null,
            'recovery_forced' => $forceRecovery,
            'scope_recovery_reason' => $scopeRecoveryReason,
            'recovery_expansion_terms' => $recoveryAttempted
                ? array_values((array) ($analysis['expanded_terms'] ?? []))
                : [],
            'index_fingerprint' => $this->embeddings->indexFingerprint(),
            'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
        ]];
    }

    public function usableEntries(?User $user, array $context = [], string $message = '')
    {
        $audience = $this->audienceResolver->resolve($user, $context);

        return AiHelperKnowledgeEntry::query()
            ->whereKey($this->authorizedEntryIds($user, $audience, $this->analyzer->analyze($message)));
    }

    /** @return array<int, int> */
    private function authorizedEntryIds(?User $user, AiHelperKnowledgeAudience $audience, array $analysis): array
    {
        $candidates = AiHelperKnowledgeEntry::query()
            ->select([
                'id', 'uploaded_by', 'source_document_id', 'knowledge_type', 'required_permissions',
                'permission_match', 'allowed_roles', 'module_gate', 'source_path', 'visibility',
                'module_key', 'route_key', 'guide_owner', 'status', 'review_status', 'active',
                'review_due_at', 'source_mime', 'extraction_complete',
            ])
            ->where('source_mime', 'text/markdown')
            ->where('active', true)
            ->whereIn('status', [AiHelperKnowledgeEntry::STATUS_ACTIVE, AiHelperKnowledgeEntry::STATUS_PROCESSING])
            ->where('review_status', AiHelperKnowledgeEntry::REVIEW_APPROVED)
            ->where(function ($query) use ($user) {
                $query->where('visibility', AiHelperKnowledgeEntry::VISIBILITY_SHARED);
                if ($user) {
                    $query->orWhere(fn ($personal) => $personal
                        ->where('visibility', AiHelperKnowledgeEntry::VISIBILITY_PERSONAL)
                        ->where('uploaded_by', $user->id));
                }
            })
            ->where(function ($types) {
                $types->where(function ($reference) {
                    $reference->where('knowledge_type', AiHelperKnowledgeEntry::KNOWLEDGE_REFERENCE_DOCUMENT);
                })->orWhere('knowledge_type', AiHelperKnowledgeEntry::KNOWLEDGE_SYSTEM_GUIDE)
                    ->orWhere('knowledge_type', AiHelperKnowledgeEntry::KNOWLEDGE_UPLOADED_MARKDOWN);
            })
            ->get();

        $sourceMode = (string) ($analysis['source_mode'] ?? 'any');

        return $candidates
            ->filter(function (AiHelperKnowledgeEntry $entry) use ($audience) {
                return match ($entry->knowledge_type) {
                    AiHelperKnowledgeEntry::KNOWLEDGE_REFERENCE_DOCUMENT => true,
                    AiHelperKnowledgeEntry::KNOWLEDGE_SYSTEM_GUIDE => $this->audienceResolver->allowsSystemGuide($entry, $audience),
                    AiHelperKnowledgeEntry::KNOWLEDGE_UPLOADED_MARKDOWN => ! str_starts_with((string) $entry->source_path, 'seed:'),
                    default => false,
                };
            })
            ->filter(function (AiHelperKnowledgeEntry $entry) use ($sourceMode) {
                if ($sourceMode === 'reference') {
                    return $entry->knowledge_type !== AiHelperKnowledgeEntry::KNOWLEDGE_SYSTEM_GUIDE;
                }
                if ($sourceMode === 'system') {
                    return $entry->knowledge_type === AiHelperKnowledgeEntry::KNOWLEDGE_SYSTEM_GUIDE;
                }

                return true;
            })
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    private function documentScore(
        AiHelperKnowledgeEntry $entry,
        array $analysis,
        array $context,
        ?array $queryEmbedding,
    ): float {
        $document = $entry->sourceDocument;
        $title = Str::lower($this->documentIdentity($entry));
        $score = $this->termScore($title, $analysis['terms'] ?? []) * 35;
        $retrievalV4 = $this->pipelineVersion() >= 4;
        $topicScore = $retrievalV4 ? $this->documentTopicScore($entry, $analysis) : 0;
        if ($topicScore > 0) {
            $score += $topicScore * 240;
            $matchedTopics = $this->topicAliases->matchedTopicKeys(
                $this->documentIdentity($entry),
                is_array($analysis['topic_keys'] ?? null) ? $analysis['topic_keys'] : [],
            );
            $coverage = $this->topicCoverage($matchedTopics, $analysis);
            $score += $coverage * 500;
            if ($coverage >= 1.0 && count($matchedTopics) > 1) {
                $score += 500;
            }
        }
        if ($retrievalV4) {
            $score += $this->documentOperationScore($entry, $analysis) * 180;
            $score += $this->documentTaskScore($entry, $analysis) * 1500;
            if (in_array((int) $entry->id, array_map('intval', (array) ($analysis['entity_entry_ids'] ?? [])), true)) {
                $score += 2200;
            }
        }
        foreach ($analysis['annex_numbers'] ?? [] as $number) {
            if (preg_match('/\bannex(?:e)?\s*0*'.preg_quote((string) $number, '/').'\b/i', $title)) {
                $score += 1600;
            }
        }
        foreach ($analysis['document_codes'] ?? [] as $code) {
            if (str_contains(Str::upper($title), $code)) {
                $score += 1800;
            }
        }
        foreach ($analysis['revisions'] ?? [] as $revision) {
            if (preg_match('/\brev(?:ision)?[.\s:-]*0*'.preg_quote((string) $revision, '/').'\b/i', $title)) {
                $score += 600;
            }
        }
        $contextDependency = (string) ($analysis['context_dependency'] ?? 'neutral');
        $queryScope = (string) ($analysis['query_scope'] ?? 'local');
        $routeBoost = ! $retrievalV4 ? 900 : match ($contextDependency) {
            'page_deictic' => 900,
            'mixed' => 260,
            'explicit_topic' => 0,
            default => 90,
        };
        $moduleBoost = ! $retrievalV4 ? 500 : match ($contextDependency) {
            'page_deictic' => 500,
            'mixed' => 140,
            'explicit_topic' => 0,
            default => 50,
        };
        if ($queryScope === 'global') {
            $routeBoost = min(260, $routeBoost);
            $moduleBoost = min(120, $moduleBoost);
        }
        if ($entry->route_key && $entry->route_key === ($context['route_key'] ?? null)) {
            $score += $entry->knowledge_type === AiHelperKnowledgeEntry::KNOWLEDGE_SYSTEM_GUIDE ? $routeBoost : min(80, $routeBoost);
        } elseif ($entry->module_key && $entry->module_key === ($context['module_key'] ?? null)) {
            $score += $entry->knowledge_type === AiHelperKnowledgeEntry::KNOWLEDGE_SYSTEM_GUIDE ? $moduleBoost : min(50, $moduleBoost);
        }
        if ($queryScope !== 'global' && $contextDependency === 'page_deictic') {
            $score += $this->systemGuides->pageHelpPriorityForGuideKey($this->guideKey($entry)) * 1200;
        }
        if ($entry->knowledge_type === AiHelperKnowledgeEntry::KNOWLEDGE_SYSTEM_GUIDE
            && in_array($analysis['source_mode'] ?? null, ['system', 'mixed'], true)) {
            $score += 300;
        }
        if ($queryEmbedding && is_array($entry->embedding)) {
            $score += max(0, $this->cosineSimilarity($queryEmbedding, $entry->embedding)) * 500;
        }

        return $score;
    }

    private function documentIdentity(AiHelperKnowledgeEntry $entry): string
    {
        $document = $entry->sourceDocument;
        $metadata = is_array($entry->retrieval_metadata) ? $entry->retrieval_metadata : [];

        return collect([
            $document?->title ?: $entry->title,
            $document?->source_filename ?: $entry->source_filename,
            $entry->summary,
            $entry->module_key,
            $entry->route_key,
            str_replace(['seed:system-guide:', '-', '_'], [' ', ' ', ' '], (string) $entry->source_path),
            collect($entry->tags ?? [])->filter()->join(' '),
            collect($metadata['headings'] ?? [])->filter()->join(' '),
        ])->filter(fn ($value) => is_string($value) && trim($value) !== '')
            ->join(' ');
    }

    private function documentTopicScore(AiHelperKnowledgeEntry $entry, array $analysis): int
    {
        return $this->topicAliases->matchScore(
            $this->documentIdentity($entry),
            is_array($analysis['topic_keys'] ?? null) ? $analysis['topic_keys'] : [],
        );
    }

    /** @param array<int, string> $matchedTopicKeys */
    private function topicCoverage(array $matchedTopicKeys, array $analysis): float
    {
        $requested = collect($analysis['topic_keys'] ?? [])->filter()->unique()->count();

        return $requested === 0 ? 0.0 : count(array_unique($matchedTopicKeys)) / $requested;
    }

    private function documentOperationScore(AiHelperKnowledgeEntry $entry, array $analysis): int
    {
        $identity = Str::lower(str_replace(['_', '-'], ' ', $this->documentIdentity($entry)));
        $aliases = [
            'view' => ['view', 'viewing', 'find', 'read', 'records'],
            'create' => ['create', 'creating', 'add', 'new', 'register', 'record'],
            'inspect' => ['inspect', 'inspection', 'inspections', 'conduct', 'checklist'],
            'maintain' => ['maintain', 'maintenance', 'management', 'managing', 'lifecycle', 'assets'],
            'submit' => ['submit', 'submission', 'workflow'],
            'approve' => ['approve', 'approval', 'review', 'verification', 'workflow'],
            'configure' => ['configure', 'configuration', 'settings', 'activation'],
            'troubleshoot' => ['troubleshoot', 'error', 'recovery', 'restore'],
            'list' => ['list', 'catalogue', 'navigation', 'reports'],
        ];

        return collect($analysis['operation_keys'] ?? [])
            ->sum(fn (string $operation) => collect($aliases[$operation] ?? [])
                ->filter(fn (string $alias) => preg_match(
                    '/(?<![\pL\pN])'.preg_quote($alias, '/').'(?![\pL\pN])/u',
                    $identity,
                ) === 1)
                ->take(2)
                ->count());
    }

    private function documentTaskScore(AiHelperKnowledgeEntry $entry, array $analysis): int
    {
        $requested = collect($analysis['task_keys'] ?? [])->filter()->unique();
        if ($requested->isEmpty()) {
            return 0;
        }

        return $requested->intersect($this->entryTaskKeys($entry))->count();
    }

    private function documentTaskConflict(AiHelperKnowledgeEntry $entry, array $analysis): bool
    {
        if ($entry->knowledge_type !== AiHelperKnowledgeEntry::KNOWLEDGE_SYSTEM_GUIDE) {
            return false;
        }

        $requested = collect($analysis['task_keys'] ?? [])->filter()->unique();
        $documentTasks = collect($this->entryTaskKeys($entry));

        if ($requested->contains(fn (string $task) => str_starts_with($task, 'inspection.'))
            && $entry->module_key !== 'reports.inspection') {
            return true;
        }

        return $requested->isNotEmpty()
            && $documentTasks->isNotEmpty()
            && $requested->intersect($documentTasks)->isEmpty();
    }

    private function documentEntityConflict(AiHelperKnowledgeEntry $entry, array $analysis): bool
    {
        if ($entry->knowledge_type !== AiHelperKnowledgeEntry::KNOWLEDGE_SYSTEM_GUIDE) {
            return false;
        }

        $entities = collect($analysis['entity_keys'] ?? []);
        if ($entities->isEmpty()) {
            return false;
        }

        $documentEntities = collect($this->systemGuides->entitiesForGuideKey($this->guideKey($entry)));

        return $documentEntities->isNotEmpty() && $entities->intersect($documentEntities)->isEmpty();
    }

    /** @return array<int, string> */
    private function entryTaskKeys(AiHelperKnowledgeEntry $entry): array
    {
        return $this->systemGuides->tasksForGuideKey($this->guideKey($entry));
    }

    private function guideKey(AiHelperKnowledgeEntry $entry): string
    {
        $prefix = 'seed:system-guide:';

        return str_starts_with((string) $entry->source_path, $prefix)
            ? Str::after((string) $entry->source_path, $prefix)
            : '';
    }

    private function isPageMatch(AiHelperKnowledgeEntry $entry, array $context): bool
    {
        return ($entry->route_key && $entry->route_key === ($context['route_key'] ?? null))
            || ($entry->module_key && $entry->module_key === ($context['module_key'] ?? null));
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $documents
     * @return Collection<int, array<string, mixed>>
     */
    private function chunkCandidates(Collection $documents, array $analysis, ?array $queryEmbedding): Collection
    {
        $candidates = collect();
        foreach ($documents as $document) {
            /** @var AiHelperKnowledgeEntry $entry */
            $entry = $document['entry'];
            foreach ($entry->chunks as $chunk) {
                $lexical = $this->chunkLexicalMetrics($chunk, $analysis);
                $chunkSemanticCompatible = ($document['semantic_compatible'] ?? false)
                    && $queryEmbedding
                    && $this->embeddings->isChunkCurrent($chunk);
                $semanticQueryEmbedding = $chunkSemanticCompatible ? $queryEmbedding : null;
                $candidates->push([
                    'chunk' => $chunk,
                    'entry' => $entry,
                    'document_score' => (float) $document['score'],
                    'lexical_score' => $lexical['score'],
                    'lexical_coverage' => $lexical['coverage'],
                    'matched_terms' => $lexical['matched_terms'],
                    'semantic_score' => $semanticQueryEmbedding
                        ? max(0, $this->cosineSimilarity($semanticQueryEmbedding, $chunk->embedding))
                        : 0.0,
                    'protected_match' => (bool) ($document['protected_match'] ?? false),
                    'score' => $this->chunkScore($chunk, (float) $document['score'], $analysis, $semanticQueryEmbedding),
                ]);
            }
        }

        return $candidates;
    }

    private function isExactDocumentMatch(AiHelperKnowledgeEntry $entry, array $analysis): bool
    {
        if (($analysis['annex_numbers'] ?? []) === [] && ($analysis['document_codes'] ?? []) === []) {
            return false;
        }
        $document = $entry->sourceDocument;
        $identity = trim(($document?->title ?? $entry->title).' '.($document?->source_filename ?? ''));
        $matchesIdentity = collect($analysis['annex_numbers'] ?? [])->contains(
            fn ($number) => preg_match('/\bannex(?:e)?\s*0*'.preg_quote((string) $number, '/').'\b/i', $identity) === 1
        ) || collect($analysis['document_codes'] ?? [])->contains(
            fn ($code) => str_contains(Str::upper($identity), (string) $code)
        );
        if (! $matchesIdentity) {
            return false;
        }
        if (($analysis['revisions'] ?? []) === []) {
            return true;
        }

        return collect($analysis['revisions'])->contains(
            fn ($revision) => preg_match('/\brev(?:ision)?[.\s:-]*0*'.preg_quote((string) $revision, '/').'\b/i', $identity) === 1
        );
    }

    private function chunkScore(AiHelperKnowledgeChunk $chunk, float $documentScore, array $analysis, ?array $queryEmbedding): float
    {
        $content = Str::lower((string) $chunk->content);
        $headings = collect($chunk->heading_path ?? [])->values();
        $documentHeading = Str::lower((string) $headings->first());
        $sectionHeadings = Str::lower($headings->slice(1)->join(' '));
        $terms = $this->contentTerms($analysis);
        // Content matches must dominate repeated document headings; otherwise a
        // code in the H1 gives every chunk the same lexical score and can hide
        // the one passage containing a requested telephone number or step.
        $score = $documentScore
            + ($this->termScore($content, $terms) * 120)
            + ($this->termScore($sectionHeadings, $terms) * 120)
            + ($this->termScore($documentHeading, $terms) * 5);
        foreach ($terms as $term) {
            if (preg_match('/\d/u', $term) && str_contains($content, Str::lower($term))) {
                $score += 250;
            }
        }
        $normalizedQuery = trim((string) ($analysis['normalized_query'] ?? ''));
        if ($normalizedQuery !== '' && str_contains($content, $normalizedQuery)) {
            $score += 350;
        }
        if ($queryEmbedding && is_array($chunk->embedding)) {
            $score += max(0, $this->cosineSimilarity($queryEmbedding, $chunk->embedding)) * 700;
        }

        return $score;
    }

    /** @return array{score: float, coverage: float, matched_terms: int} */
    private function chunkLexicalMetrics(AiHelperKnowledgeChunk $chunk, array $analysis): array
    {
        $terms = $this->contentTerms($analysis);
        $content = Str::lower((string) ($chunk->search_text ?: $chunk->content));
        $headings = Str::lower(collect($chunk->heading_path ?? [])->slice(1)->join(' '));
        $matchedTerms = collect($terms)->filter(
            fn (string $term) => $this->termOccurrences($content, $term) > 0
                || $this->termOccurrences($headings, $term) > 0
        )->count();
        $coverage = $terms === [] ? 0.0 : $matchedTerms / count($terms);
        $subqueryCoverage = collect($analysis['subqueries'] ?? [])
            ->map(function (string $subquery) use ($analysis, $content, $headings) {
                $subqueryAnalysis = $analysis;
                $subqueryAnalysis['terms'] = $this->analyzer->terms($subquery);
                $subqueryTerms = $this->contentTerms($subqueryAnalysis);
                if ($subqueryTerms === []) {
                    return 0.0;
                }
                $matched = collect($subqueryTerms)->filter(
                    fn (string $term) => $this->termOccurrences($content, $term) > 0
                        || $this->termOccurrences($headings, $term) > 0
                )->count();

                return $matched / count($subqueryTerms);
            })
            ->max() ?? 0.0;

        return [
            'score' => ($this->termScore($content, $terms) * 2) + ($this->termScore($headings, $terms) * 3),
            'coverage' => max($coverage, (float) $subqueryCoverage),
            'matched_terms' => $matchedTerms,
        ];
    }

    private function isRelevantCandidate(array $candidate, array $analysis): bool
    {
        $minimumLexicalCoverage = max(
            0.0,
            min(1.0, (float) config('ai_helper.retrieval_min_lexical_coverage', 0.6)),
        );
        $minimumSemanticSimilarity = max(
            0.0,
            min(1.0, (float) config('ai_helper.retrieval_min_semantic_similarity', 0.42)),
        );
        if (($analysis['query_scope'] ?? 'local') === 'global') {
            $minimumLexicalCoverage = 0.0;
            $minimumSemanticSimilarity = max(0.32, min(1.0, $minimumSemanticSimilarity));
        }

        return (float) ($candidate['lexical_coverage'] ?? 0)
                >= $minimumLexicalCoverage
            || (float) ($candidate['semantic_score'] ?? 0)
                >= $minimumSemanticSimilarity;
    }

    private function isScopeRecoveryHelpful(
        array $analysis,
        Collection $rankedChunks,
        Collection $exactDocuments,
        Collection $selectedDocuments,
    ): bool {
        if ($exactDocuments->isNotEmpty()) {
            return false;
        }
        if ($selectedDocuments->isEmpty()) {
            return false;
        }
        $topLexical = (float) ($rankedChunks->max('lexical_coverage') ?? 0);
        $topSemantic = (float) ($rankedChunks->max('semantic_score') ?? 0);
        $scopeHint = (string) ($analysis['scope_adjustment_hint'] ?? 'none');

        return $topLexical <= 0.09
            || $topSemantic <= 0.24
            || in_array($scopeHint, ['global', 'cross_module_candidate'], true);
    }

    private function scopeRecoveryReason(
        array $analysis,
        Collection $rankedChunks,
    ): string {
        $scopeHint = (string) ($analysis['scope_adjustment_hint'] ?? 'none');
        if ($scopeHint === 'cross_module_candidate') {
            return 'cross_module_query';
        }
        if ($scopeHint === 'global') {
            return 'global_query_hint';
        }

        $topLexical = (float) ($rankedChunks->max('lexical_coverage') ?? 0);
        $topSemantic = (float) ($rankedChunks->max('semantic_score') ?? 0);
        if ($topLexical < 0.06) {
            return 'low_local_coverage_lexical';
        }
        if ($topSemantic < 0.16) {
            return 'low_local_coverage_semantic';
        }

        return 'scope_broadening';
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $candidates
     * @param  Collection<int, array<string, mixed>>  $fallbackCandidates
     * @param  Collection<int, array<string, mixed>>  $exactDocuments
     * @return Collection<int, array<string, mixed>>
     */
    private function ensureExactDocumentCoverage(
        Collection $candidates,
        Collection $fallbackCandidates,
        Collection $exactDocuments,
    ): Collection {
        $requiredEntryIds = $exactDocuments
            ->pluck('entry.id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
        $presentEntryIds = $candidates
            ->pluck('entry.id')
            ->map(fn ($id) => (int) $id)
            ->unique();
        $representatives = $requiredEntryIds
            ->diff($presentEntryIds)
            ->map(fn (int $entryId) => $fallbackCandidates->first(
                fn (array $candidate) => (int) $candidate['entry']->id === $entryId,
            ))
            ->filter();

        return $candidates
            ->concat($representatives)
            ->unique(fn (array $candidate) => (int) $candidate['chunk']->id)
            ->values();
    }

    /**
     * Keep the strongest deterministic entity/facet match ahead of a model
     * rerank. This prevents a corpus-exact role definition from being crowded
     * out by broader documents that repeat the same acronym.
     *
     * @param  Collection<int, array<string, mixed>>  $candidates
     * @param  Collection<int, array<string, mixed>>  $fallbackCandidates
     */
    private function prioritizeEvidenceRepresentative(
        Collection $candidates,
        Collection $fallbackCandidates,
        array $analysis,
    ): Collection {
        $hasRequestedEvidence = ($analysis['resolved_entities'] ?? []) !== []
            || ($analysis['requested_facets'] ?? []) !== [];
        if (! $hasRequestedEvidence || $fallbackCandidates->isEmpty()) {
            return $candidates->values();
        }

        $representative = $fallbackCandidates
            ->sort(function (array $left, array $right): int {
                $evidenceComparison = $this->evidenceRepresentativeScore($right)
                    <=> $this->evidenceRepresentativeScore($left);
                if ($evidenceComparison !== 0) {
                    return $evidenceComparison;
                }
                foreach (['entity_adequacy_score', 'facet_adequacy_score', 'score'] as $field) {
                    $comparison = ($right[$field] ?? 0) <=> ($left[$field] ?? 0);
                    if ($comparison !== 0) {
                        return $comparison;
                    }
                }

                return 0;
            })
            ->first(fn (array $candidate) => (int) ($candidate['entity_adequacy_score'] ?? 0) > 0);

        if (! $representative) {
            return $candidates->values();
        }

        return collect([$representative])
            ->concat($candidates)
            ->unique(fn (array $candidate) => (int) $candidate['chunk']->id)
            ->values();
    }

    /**
     * Reserve the strongest entity/facet passage from each protected document
     * before the global candidate cut. Topic lanes often contain several
     * documents, and each document must keep its most directly useful passage
     * available to the model reranker.
     *
     * @param  Collection<int, array<string, mixed>>  $candidates
     */
    private function prioritizeProtectedEvidenceRepresentatives(
        Collection $candidates,
        array $analysis,
    ): Collection {
        $hasRequestedEvidence = ($analysis['resolved_entities'] ?? []) !== []
            || ($analysis['requested_facets'] ?? []) !== [];
        if (! $hasRequestedEvidence || $candidates->isEmpty()) {
            return $candidates->values();
        }

        $representatives = $candidates
            ->filter(fn (array $candidate) => (bool) ($candidate['protected_match'] ?? false))
            ->groupBy(fn (array $candidate) => (int) $candidate['entry']->id)
            ->map(fn (Collection $documentCandidates) => $documentCandidates
                ->sort(function (array $left, array $right): int {
                    $evidenceComparison = $this->evidenceRepresentativeScore($right)
                        <=> $this->evidenceRepresentativeScore($left);
                    if ($evidenceComparison !== 0) {
                        return $evidenceComparison;
                    }
                    foreach (['entity_adequacy_score', 'facet_adequacy_score', 'score'] as $field) {
                        $comparison = ($right[$field] ?? 0) <=> ($left[$field] ?? 0);
                        if ($comparison !== 0) {
                            return $comparison;
                        }
                    }

                    return 0;
                })
                ->first())
            ->filter()
            ->values();

        return $representatives
            ->concat($candidates)
            ->unique(fn (array $candidate) => (int) $candidate['chunk']->id)
            ->values();
    }

    /** @param array<string, mixed> $candidate */
    private function evidenceRepresentativeScore(array $candidate): int
    {
        return (int) ($candidate['entity_adequacy_score'] ?? 0)
            + ((int) ($candidate['facet_adequacy_score'] ?? 0) * 2);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $candidates
     * @param  Collection<int, array<string, mixed>>  $exactDocuments
     * @return Collection<int, array<string, mixed>>
     */
    private function prioritizeExactDocumentRepresentatives(
        Collection $candidates,
        Collection $exactDocuments,
    ): Collection {
        $representatives = $exactDocuments
            ->pluck('entry.id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->map(fn (int $entryId) => $candidates->first(
                fn (array $candidate) => (int) $candidate['entry']->id === $entryId,
            ))
            ->filter();

        return $representatives
            ->concat($candidates)
            ->unique(fn (array $candidate) => (int) $candidate['chunk']->id)
            ->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $candidates
     * @return Collection<int, array<string, mixed>>
     */
    private function rankChunksWithFusion(Collection $candidates): Collection
    {
        if ($candidates->count() < 2) {
            return $candidates->values();
        }

        $rankingFor = fn (string $score) => $candidates
            ->sortByDesc($score)
            ->pluck('chunk.id')
            ->map(fn ($id) => (int) $id)
            ->all();
        $hasDiscriminatingSignal = fn (string $score) => $candidates
            ->pluck($score)
            ->map(fn ($value) => round((float) $value, 8))
            ->unique()
            ->count() > 1;
        $rankings = [$rankingFor('score')];
        if ($hasDiscriminatingSignal('lexical_score')) {
            $rankings[] = $rankingFor('lexical_score');
        }
        if ($hasDiscriminatingSignal('document_score')) {
            $rankings[] = $rankingFor('document_score');
        }
        if ($hasDiscriminatingSignal('semantic_score')) {
            $rankings[] = $rankingFor('semantic_score');
        }
        $scores = $this->rankFusion->fuse($rankings);

        return $candidates
            ->map(function (array $item) use ($scores) {
                $item['fused_score'] = (float) ($scores[(int) $item['chunk']->id] ?? 0);

                return $item;
            })
            ->sort(function (array $left, array $right) {
                $fused = ($right['fused_score'] ?? 0) <=> ($left['fused_score'] ?? 0);

                return $fused !== 0 ? $fused : (($right['score'] ?? 0) <=> ($left['score'] ?? 0));
            })
            ->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $candidates
     * @return array{candidates: Collection<int, array<string, mixed>>, requested: int, covered: int}
     */
    private function prioritizeSubqueryCoverage(Collection $candidates, array $analysis): array
    {
        $subqueries = collect($analysis['subqueries'] ?? [])
            ->filter(fn ($query) => is_string($query) && trim($query) !== '')
            ->values();
        if ($subqueries->count() < 2 || $candidates->isEmpty()) {
            return [
                'candidates' => $candidates,
                'requested' => max(1, $subqueries->count()),
                'covered' => $candidates->isEmpty() ? 0 : 1,
            ];
        }

        $reserved = collect();
        foreach ($subqueries as $subquery) {
            $subqueryAnalysis = $analysis;
            $subqueryAnalysis['terms'] = $this->analyzer->terms($subquery);
            $terms = $this->contentTerms($subqueryAnalysis);
            $best = $candidates
                ->map(function (array $item) use ($terms) {
                    $content = Str::lower((string) ($item['chunk']->search_text ?: $item['chunk']->content));
                    $heading = Str::lower(collect($item['chunk']->heading_path ?? [])->join(' '));
                    $item['subquery_score'] = ($this->termScore($content, $terms) * 2)
                        + ($this->termScore($heading, $terms) * 3);

                    return $item;
                })
                ->sortByDesc('subquery_score')
                ->first(fn (array $item) => ($item['subquery_score'] ?? 0) > 0);
            if ($best && ! $reserved->contains(fn (array $item) => $item['chunk']->id === $best['chunk']->id)) {
                $reserved->push($best);
            }
        }

        $reservedIds = $reserved->pluck('chunk.id');

        return [
            'candidates' => $reserved
                ->concat($candidates->reject(fn (array $item) => $reservedIds->contains($item['chunk']->id)))
                ->values(),
            'requested' => $subqueries->count(),
            'covered' => $reserved->count(),
        ];
    }

    /** @return array<int, string> */
    private function contentTerms(array $analysis): array
    {
        $identityTerms = collect($analysis['document_codes'] ?? [])
            ->flatMap(fn (string $code) => preg_split('/[^\pL\pN]+/u', Str::lower($code)) ?: []);
        if (($analysis['annex_numbers'] ?? []) !== []) {
            $identityTerms->push('annex');
            $identityTerms = $identityTerms->merge(array_map('strval', $analysis['annex_numbers']));
        }
        if (($analysis['revisions'] ?? []) !== []) {
            $identityTerms = $identityTerms->merge(['rev', 'revision'])->merge($analysis['revisions']);
        }

        return collect($analysis['terms'] ?? [])
            ->reject(fn (string $term) => $identityTerms->contains(Str::lower($term)))
            ->values()
            ->all();
    }

    private function termScore(string $haystack, array $terms): int
    {
        return collect($terms)->sum(function (string $term) use ($haystack) {
            $count = $this->termOccurrences($haystack, $term);

            return $count > 0 ? min(3, $count) : 0;
        });
    }

    private function termOccurrences(string $haystack, string $term): int
    {
        $pattern = '/(?<![\pL\pN])'.preg_quote(Str::lower($term), '/').'(?![\pL\pN])/u';

        return preg_match_all($pattern, Str::lower($haystack)) ?: 0;
    }

    /**
     * @param  Collection<int, array{entry: AiHelperKnowledgeEntry, score: float}>  $documents
     * @return Collection<int, array{entry: AiHelperKnowledgeEntry, score: float}>
     */
    private function hydrateSelectedDocuments(Collection $documents): Collection
    {
        if ($documents->isEmpty()) {
            return $documents;
        }

        $entries = AiHelperKnowledgeEntry::query()
            ->whereKey($documents->pluck('entry.id')->all())
            ->with([
                'sourceDocument:id,title,source_filename',
                'chunks' => fn ($query) => $query->where('active', true)->orderBy('chunk_index'),
            ])
            ->get()
            ->keyBy('id');

        return $documents
            ->map(function (array $document) use ($entries) {
                $document['entry'] = $entries->get($document['entry']->id, $document['entry']);

                return $document;
            })
            ->filter(fn (array $document) => $document['entry']->relationLoaded('chunks'))
            ->values();
    }

    private function candidateWithNeighbours(array $candidate, int $window): Collection
    {
        if ($window === 0) {
            return collect([$candidate]);
        }
        $chunk = $candidate['chunk'];
        $entry = $candidate['entry'];
        $minimum = max(0, $chunk->chunk_index - $window);
        $maximum = $chunk->chunk_index + $window;

        return $entry->chunks
            ->whereBetween('chunk_index', [$minimum, $maximum])
            ->sortBy('chunk_index')
            ->map(fn ($neighbour) => [
                'chunk' => $neighbour,
                'entry' => $entry,
                'score' => $neighbour->id === $chunk->id ? $candidate['score'] : $candidate['score'] - 1,
            ])->values();
    }

    private function formatGuidance(array $item, array $context, string $sourceId): array
    {
        $chunk = $item['chunk'];
        $entry = $item['entry'];

        return [
            'source_id' => $sourceId,
            'source_type' => $entry->knowledge_type,
            'knowledge_type' => $entry->knowledge_type,
            'id' => $entry->id,
            'source_document_id' => $entry->source_document_id,
            'chunk_id' => $chunk->id,
            'chunk_index' => $chunk->chunk_index,
            'module_key' => $chunk->module_key,
            'route_key' => $chunk->route_key,
            'title' => $entry->sourceDocument?->title ?: $entry->title,
            'content' => $chunk->content,
            'content_type' => $chunk->content_type ?: 'text',
            'heading_path' => $chunk->heading_path ?: [],
            'source_scope' => $this->sourceScope($chunk->module_key, $chunk->route_key, $context),
            'page_start' => $chunk->page_start,
            'page_end' => $chunk->page_end,
            'guide_version' => $entry->knowledge_type === AiHelperKnowledgeEntry::KNOWLEDGE_SYSTEM_GUIDE
                ? (int) $entry->version
                : null,
            'display_label' => $entry->knowledge_type === AiHelperKnowledgeEntry::KNOWLEDGE_SYSTEM_GUIDE
                ? AiHelperSystemGuideCatalog::DISPLAY_LABEL
                : null,
            'guide_key' => $entry->knowledge_type === AiHelperKnowledgeEntry::KNOWLEDGE_SYSTEM_GUIDE
                ? $this->guideKey($entry)
                : null,
            'task_keys' => $this->entryTaskKeys($entry),
        ];
    }

    private function sourceScope(?string $module, ?string $route, array $context): string
    {
        if ($route && $route === ($context['route_key'] ?? null)) {
            return 'Page guidance';
        }
        if ($module && $module === ($context['module_key'] ?? null)) {
            return 'Module guidance';
        }

        return 'General guidance';
    }

    private function pipelineVersion(): int
    {
        return max(2, min(4, (int) config('ai_helper.pipeline_version', 4)));
    }

    /** @param array{matches: array<int, array<string, mixed>>, ambiguous_aliases: array<int, string>} $resolution */
    private function mergeCorpusEntities(array $analysis, array $resolution): array
    {
        $matches = collect($resolution['matches']);
        $ambiguous = $resolution['ambiguous_aliases'] !== [];
        $entityTerms = $matches
            ->flatMap(fn (array $match) => $ambiguous
                ? $match['matched_aliases']
                : [
                    $match['canonical_name'],
                    ...$match['matched_aliases'],
                    ...$match['all_aliases'],
                ])
            ->flatMap(fn (string $value) => preg_split('/[^\pL\pN]+/u', Str::lower($value)) ?: [])
            ->filter(fn (string $term) => Str::length($term) >= 2);
        $analysis['terms'] = collect([
            ...(array) ($analysis['terms'] ?? []),
            ...$entityTerms->all(),
        ])->filter()->unique()->take(48)->values()->all();
        $analysis['expanded_terms'] = collect([
            ...(array) ($analysis['expanded_terms'] ?? []),
            ...$entityTerms->all(),
        ])->filter()->unique()->take(48)->values()->all();
        if (! $ambiguous) {
            $analysis['resolved_entities'] = collect([
                ...(array) ($analysis['resolved_entities'] ?? []),
                ...$matches->pluck('normalized_name'),
            ])->filter()->unique()->values()->all();
        }
        $analysis['corpus_entities'] = $matches->values()->all();
        $analysis['entity_ambiguity'] = $ambiguous;
        if (! $ambiguous && $matches->contains(fn (array $match) => in_array($match['entity_type'], ['role', 'team'], true))) {
            $analysis['topic_keys'] = collect([
                ...(array) ($analysis['topic_keys'] ?? []),
                'emergency_response_role',
            ])->unique()->values()->all();
        }
        $analysis['answer_mode'] = 'operational_knowledge';
        $analysis['evidence_required'] = true;
        $analysis['retrieval_policy'] = 'required';

        return $analysis;
    }

    /**
     * @param  array<int, array<string, mixed>>  $matches
     * @return array<int, int>
     */
    private function preferredEntityEntryIds(string $query, array $matches): array
    {
        $normalizedQuery = Str::lower((string) preg_replace('/[^\pL\pN]+/u', ' ', $query));
        $scored = collect($matches)->map(function (array $match) use ($normalizedQuery): array {
            $length = collect($match['matched_aliases'] ?? [])
                ->filter(fn (string $alias) => str_contains(
                    $normalizedQuery,
                    Str::lower((string) preg_replace('/[^\pL\pN]+/u', ' ', $alias)),
                ))
                ->map(fn (string $alias) => Str::length($alias))
                ->max() ?? 0;

            return ['entry_id' => (int) $match['knowledge_entry_id'], 'length' => (int) $length];
        });
        $maximum = (int) ($scored->max('length') ?? 0);
        if ($maximum <= 0) {
            return [];
        }

        return $scored
            ->where('length', $maximum)
            ->pluck('entry_id')
            ->unique()
            ->values()
            ->all();
    }

    private function cosineSimilarity(array $left, array $right): float
    {
        if ($left === [] || count($left) !== count($right)) {
            return 0.0;
        }
        $dot = 0.0;
        $leftMagnitude = 0.0;
        $rightMagnitude = 0.0;
        foreach ($left as $index => $value) {
            $leftValue = (float) $value;
            $rightValue = (float) $right[$index];
            $dot += $leftValue * $rightValue;
            $leftMagnitude += $leftValue * $leftValue;
            $rightMagnitude += $rightValue * $rightValue;
        }
        if ($leftMagnitude <= 0 || $rightMagnitude <= 0) {
            return 0.0;
        }

        return $dot / (sqrt($leftMagnitude) * sqrt($rightMagnitude));
    }
}
