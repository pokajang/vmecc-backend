<?php

namespace App\Services;

use Illuminate\Support\Collection;

class AiHelperDocumentCandidateSelector
{
    /**
     * Select a bounded union of independent candidate lanes. This prevents a
     * large current-page score from pruning an explicit cross-module topic
     * before its passages have been considered.
     *
     * @param  Collection<int, array<string, mixed>>  $ranked
     * @return array{documents: Collection<int, array<string, mixed>>, lanes: array<string, int>}
     */
    public function select(Collection $ranked, array $analysis): array
    {
        $baseLimit = max(1, (int) config('ai_helper.knowledge_document_candidate_limit', 12));
        $limit = max($baseLimit, (int) config('ai_helper.retrieval_v4_document_candidate_limit', 18));
        $topicLimit = max(1, (int) config('ai_helper.retrieval_v4_topic_candidate_limit', 6));
        $pageLimit = max(1, (int) config('ai_helper.retrieval_v4_page_candidate_limit', 4));
        $globalLimit = max($baseLimit, (int) config('ai_helper.retrieval_v4_global_candidate_limit', 12));
        $contextDependency = (string) ($analysis['context_dependency'] ?? 'neutral');
        $queryScope = (string) ($analysis['query_scope'] ?? 'local');
        $isGlobalScope = $queryScope === 'global';

        $eligible = $ranked->reject(fn (array $item) => (bool) ($item['task_conflict'] ?? false))->values();
        $exact = $ranked->where('exact_match', true)->values();
        $topic = $eligible->filter(fn (array $item) => (int) ($item['topic_score'] ?? 0) > 0)
            ->sort(function (array $left, array $right): int {
                foreach (['task_score', 'topic_coverage', 'topic_score', 'operation_score', 'score'] as $field) {
                    $comparison = ($right[$field] ?? 0) <=> ($left[$field] ?? 0);
                    if ($comparison !== 0) {
                        return $comparison;
                    }
                }

                return 0;
            })
            ->take($topicLimit)
            ->values();
        $global = $eligible->take($globalLimit)->values();
        if ($isGlobalScope) {
            $global = $eligible->sortByDesc('global_score')
                ->take($globalLimit)
                ->values();
        }
        $page = in_array($contextDependency, ['page_deictic', 'mixed'], true)
            ? $eligible->where('page_match', true)->take($pageLimit)->values()
            : collect();

        $documents = $exact
            ->concat($topic)
            ->concat($global)
            ->concat($page)
            ->unique(fn (array $item) => (int) $item['entry']->id)
            ->take($limit)
            ->values();

        return [
            'documents' => $documents,
            'lanes' => [
                'exact' => $exact->count(),
                'topic' => $topic->count(),
                'topic_intersection' => $topic->where('topic_coverage', '>=', 1.0)->count(),
                'global' => $global->count(),
                'page' => $page->count(),
            ],
        ];
    }
}
