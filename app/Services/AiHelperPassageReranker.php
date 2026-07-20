<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class AiHelperPassageReranker
{
    public function __construct(private readonly AiHelperOpenAiService $openAi) {}

    /**
     * @param  Collection<int, array<string, mixed>>  $candidates
     * @return array{candidates: Collection<int, array<string, mixed>>, metadata: array<string, mixed>}
     */
    public function rerank(
        string $question,
        array $analysis,
        Collection $candidates,
        ?AiHelperRequestDeadline $deadline = null,
        ?string $safetyIdentifier = null,
    ): array {
        if (! (bool) config('ai_helper.rerank_enabled', false)
            || $candidates->count() < 2
            || (bool) ($analysis['skip_rerank'] ?? false)) {
            return ['candidates' => $candidates, 'metadata' => [
                'enabled' => false,
                'status' => (bool) ($analysis['skip_rerank'] ?? false) ? 'skipped_high_confidence' : 'not_run',
                'fallback' => false,
            ]];
        }

        $limit = max(2, (int) config('ai_helper.rerank_candidate_limit', 32));
        $protected = $candidates->filter(fn (array $candidate) => (bool) ($candidate['protected_match'] ?? false));
        $inputCandidates = $protected
            ->concat($candidates)
            ->unique(fn (array $candidate) => (int) $candidate['chunk']->id)
            ->take($limit)
            ->values();
        $payload = $inputCandidates->map(fn (array $item) => [
            'chunk_id' => (int) $item['chunk']->id,
            'document' => (string) ($item['entry']->sourceDocument?->title ?: $item['entry']->title),
            'heading' => collect($item['chunk']->heading_path ?? [])->filter()->join(' > '),
            'content' => Str::limit((string) $item['chunk']->content, 1600, ''),
        ])->all();

        try {
            $result = $this->openAi->structuredResponse(
                (string) config('ai_helper.rerank_model', config('ai_helper.model')),
                'Rank only the supplied evidence passages for the question. Do not answer the question. Preserve chunk IDs exactly. Rank direct, complete evidence above merely related text. Respect explicit document and revision constraints.',
                [[
                    'role' => 'user',
                    'content' => json_encode([
                        'question' => $question,
                        'subqueries' => $analysis['subqueries'] ?? [$question],
                        'required_annexes' => $analysis['annex_numbers'] ?? [],
                        'required_revisions' => $analysis['revisions'] ?? [],
                        'required_document_codes' => $analysis['document_codes'] ?? [],
                        'candidates' => $payload,
                    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ]],
                'ai_helper_passage_ranking',
                $this->schema(),
                (int) config('ai_helper.rerank_timeout', 20),
                $deadline,
                $safetyIdentifier,
            );
            $allowed = $inputCandidates->keyBy(fn (array $item) => (int) $item['chunk']->id);
            $minimumRelevance = max(0, min(3, (int) config('ai_helper.rerank_min_relevance', 1)));
            $rankedIds = collect($result['data']['results'] ?? [])
                ->filter(fn (array $item) => (int) ($item['relevance'] ?? 0) >= $minimumRelevance)
                ->sortByDesc(fn (array $item) => (int) ($item['relevance'] ?? 0))
                ->pluck('chunk_id')
                ->map(fn ($id) => (int) $id)
                ->filter(fn (int $id) => $allowed->has($id))
                ->unique()
                ->values();
            $ranked = $rankedIds->map(fn (int $id) => $allowed->get($id))->values();
            if ($ranked->isEmpty()) {
                return ['candidates' => $candidates, 'metadata' => [
                    'enabled' => true,
                    'status' => 'fallback',
                    'fallback' => true,
                    'reason' => 'no_relevant_candidates',
                    'provider_response_id' => $result['response_id'] ?? null,
                    'candidate_count' => $inputCandidates->count(),
                ]];
            }

            $protectedOmitted = $protected->reject(
                fn (array $candidate) => $rankedIds->contains((int) $candidate['chunk']->id),
            );
            $ranked = $protectedOmitted
                ->concat($ranked)
                ->unique(fn (array $candidate) => (int) $candidate['chunk']->id)
                ->values();

            return ['candidates' => $ranked, 'metadata' => [
                'enabled' => true,
                'status' => $protectedOmitted->isEmpty() ? 'completed' : 'completed_with_protected_matches',
                'fallback' => false,
                'provider_response_id' => $result['response_id'] ?? null,
                'candidate_count' => $inputCandidates->count(),
            ]];
        } catch (Throwable $e) {
            Log::warning('Ask AI passage reranking fell back to deterministic order.', [
                'exception_class' => $e::class,
            ]);

            return ['candidates' => $candidates, 'metadata' => [
                'enabled' => true,
                'status' => 'fallback',
                'fallback' => true,
                'reason' => 'provider_or_schema_failure',
                'candidate_count' => $inputCandidates->count(),
            ]];
        }
    }

    private function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['results'],
            'properties' => [
                'results' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['chunk_id', 'relevance', 'direct_answer', 'covers'],
                        'properties' => [
                            'chunk_id' => ['type' => 'integer'],
                            'relevance' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 3],
                            'direct_answer' => ['type' => 'boolean'],
                            'covers' => ['type' => 'array', 'items' => ['type' => 'string']],
                        ],
                    ],
                ],
            ],
        ];
    }
}
