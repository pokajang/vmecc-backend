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
    ) {}

    /** @return array{analysis: array<string, mixed>, guidance: array<int, array<string, mixed>>, trace: array<string, mixed>} */
    public function retrieve(array $context, ?User $user, string $message, array $previousUserMessages = []): array
    {
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
        if ($analysis['intent'] === 'casual') {
            return ['analysis' => $analysis, 'guidance' => [], 'trace' => [
                'mode' => 'casual',
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
        $rankingContext['route_key'] = $audience->routeKey ?? ($context['route_key'] ?? null);
        $rankingContext['module_key'] = $audience->moduleKey ?? ($context['module_key'] ?? null);
        $authorizedIds = $this->authorizedEntryIds($user, $audience, $message);
        $entries = AiHelperKnowledgeEntry::query()
            ->whereKey($authorizedIds)
            ->with('sourceDocument:id,title,source_filename')
            ->get();
        $queryEmbedding = null;
        $semanticFallback = false;
        if ($entries->contains(fn (AiHelperKnowledgeEntry $entry) => is_array($entry->embedding) && $entry->embedding !== [])) {
            try {
                $queryEmbedding = $this->embeddings->embedQuery((string) $analysis['query']);
                $semanticFallback = $queryEmbedding === null;
            } catch (\Throwable) {
                $semanticFallback = true;
            }
        }

        $rankedDocuments = $entries->map(function (AiHelperKnowledgeEntry $entry) use ($analysis, $rankingContext, $queryEmbedding, $message) {
            return ['entry' => $entry, 'score' => $this->documentScore($entry, $analysis, $rankingContext, $queryEmbedding, $message)];
        })->sortByDesc('score')->values();
        $documentLimit = max(1, (int) config('ai_helper.knowledge_document_candidate_limit', 12));
        $exactDocuments = $rankedDocuments->filter(fn (array $item) => $this->isExactDocumentMatch($item['entry'], $analysis));
        $selectedDocuments = $exactDocuments->isNotEmpty()
            ? $exactDocuments->take(max($documentLimit, 12))->values()
            : $rankedDocuments->take($documentLimit);
        $selectedDocuments = $this->hydrateSelectedDocuments($selectedDocuments);

        $rankedChunks = collect();
        foreach ($selectedDocuments as $document) {
            /** @var AiHelperKnowledgeEntry $entry */
            $entry = $document['entry'];
            foreach ($entry->chunks as $chunk) {
                $lexical = $this->chunkLexicalMetrics($chunk, $analysis);
                $rankedChunks->push([
                    'chunk' => $chunk,
                    'entry' => $entry,
                    'document_score' => (float) $document['score'],
                    'lexical_score' => $lexical['score'],
                    'lexical_coverage' => $lexical['coverage'],
                    'matched_terms' => $lexical['matched_terms'],
                    'semantic_score' => $queryEmbedding && is_array($chunk->embedding)
                        ? max(0, $this->cosineSimilarity($queryEmbedding, $chunk->embedding))
                        : 0.0,
                    'score' => $this->chunkScore($chunk, (float) $document['score'], $analysis, $queryEmbedding),
                ]);
            }
        }
        if ($exactDocuments->isEmpty()) {
            $rankedChunks = $rankedChunks
                ->filter(fn (array $candidate) => $this->isRelevantCandidate($candidate))
                ->values();
        }
        $retrievalV3 = (bool) config('ai_helper.retrieval_v3', false);
        $rankedChunks = $retrievalV3
            ? $this->rankChunksWithFusion($rankedChunks)
            : $rankedChunks->sortByDesc('score')->values();
        if ($exactDocuments->count() > 1) {
            $rankedChunks = $this->prioritizeExactDocumentRepresentatives($rankedChunks, $exactDocuments);
        }
        $candidateChunkLimit = max(
            (int) config('ai_helper.knowledge_retrieval_limit', 18),
            (int) config('ai_helper.retrieval_candidate_chunks', 40),
        );
        $rankedChunks = $rankedChunks->take($candidateChunkLimit)->values();
        $preRerankCandidates = $rankedChunks;
        $rerankMetadata = ['enabled' => false, 'status' => 'not_run', 'fallback' => false];
        if ($retrievalV3) {
            $reranked = $this->reranker->rerank((string) $analysis['query'], $analysis, $rankedChunks);
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
        $rankedChunks = $subqueryCoverage['candidates'];

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
                        (int) $item['entry']->source_document_id,
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

        return ['analysis' => $analysis, 'guidance' => $guidance, 'trace' => [
            'pipeline_version' => $retrievalV3 ? 3 : 2,
            'mode' => $queryEmbedding ? 'hybrid' : 'lexical',
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
            'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
        ]];
    }

    public function usableEntries(?User $user, array $context = [], string $message = '')
    {
        $audience = $this->audienceResolver->resolve($user, $context);

        return AiHelperKnowledgeEntry::query()
            ->whereKey($this->authorizedEntryIds($user, $audience, $message));
    }

    /** @return array<int, int> */
    private function authorizedEntryIds(?User $user, AiHelperKnowledgeAudience $audience, string $message): array
    {
        $candidates = AiHelperKnowledgeEntry::query()
            ->select([
                'id', 'uploaded_by', 'source_document_id', 'knowledge_type', 'required_permissions',
                'permission_match', 'allowed_roles', 'module_gate', 'source_path', 'visibility',
                'module_key', 'route_key', 'guide_owner', 'status', 'review_status', 'active',
                'review_due_at', 'source_mime',
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
                    $reference->where('knowledge_type', AiHelperKnowledgeEntry::KNOWLEDGE_REFERENCE_DOCUMENT)
                        ->whereNotNull('source_document_id');
                })->orWhere('knowledge_type', AiHelperKnowledgeEntry::KNOWLEDGE_SYSTEM_GUIDE)
                    ->orWhere('knowledge_type', AiHelperKnowledgeEntry::KNOWLEDGE_UPLOADED_MARKDOWN);
            })
            ->get();

        $sourceMode = $this->questionSourceMode($message);

        return $candidates
            ->filter(function (AiHelperKnowledgeEntry $entry) use ($audience) {
                return match ($entry->knowledge_type) {
                    AiHelperKnowledgeEntry::KNOWLEDGE_REFERENCE_DOCUMENT => $entry->source_document_id !== null,
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
        string $message,
    ): float {
        $document = $entry->sourceDocument;
        $title = Str::lower(trim(($document?->title ?? $entry->title).' '.($document?->source_filename ?? '').' '.($entry->summary ?? '')));
        $score = $this->termScore($title, $analysis['terms'] ?? []) * 35;
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
        if ($entry->route_key && $entry->route_key === ($context['route_key'] ?? null)) {
            $score += $entry->knowledge_type === AiHelperKnowledgeEntry::KNOWLEDGE_SYSTEM_GUIDE ? 900 : 80;
        } elseif ($entry->module_key && $entry->module_key === ($context['module_key'] ?? null)) {
            $score += $entry->knowledge_type === AiHelperKnowledgeEntry::KNOWLEDGE_SYSTEM_GUIDE ? 500 : 50;
        }
        if ($entry->knowledge_type === AiHelperKnowledgeEntry::KNOWLEDGE_SYSTEM_GUIDE
            && in_array($this->questionSourceMode($message), ['system', 'mixed'], true)) {
            $score += 300;
        }
        if ($queryEmbedding && is_array($entry->embedding)) {
            $score += max(0, $this->cosineSimilarity($queryEmbedding, $entry->embedding)) * 500;
        }

        return $score;
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

    private function isRelevantCandidate(array $candidate): bool
    {
        $minimumLexicalCoverage = max(
            0.0,
            min(1.0, (float) config('ai_helper.retrieval_min_lexical_coverage', 0.6)),
        );
        $minimumSemanticSimilarity = max(
            0.0,
            min(1.0, (float) config('ai_helper.retrieval_min_semantic_similarity', 0.42)),
        );

        return (float) ($candidate['lexical_coverage'] ?? 0)
                >= $minimumLexicalCoverage
            || (float) ($candidate['semantic_score'] ?? 0)
                >= $minimumSemanticSimilarity;
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
        ];
    }

    private function questionSourceMode(string $message): string
    {
        $system = preg_match('/\b(?:how (?:do|can|should) i|where (?:do|can) i|which button|what status|navigate|screen|page|form|field)\b/i', $message) === 1;
        $reference = preg_match('/\b(?:emergency|policy|telephone|phone number|procedure|annex|erp)\b/i', $message) === 1;

        if ($system && $reference) {
            return 'mixed';
        }
        if ($system) {
            return 'system';
        }
        if ($reference) {
            return 'reference';
        }

        return 'any';
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
