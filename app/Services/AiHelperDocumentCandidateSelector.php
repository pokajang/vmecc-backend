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

        $exact = $ranked->where('exact_match', true)->values();
        $topic = $ranked->filter(fn (array $item) => (int) ($item['topic_score'] ?? 0) > 0)
            ->sortByDesc('topic_score')
            ->take($topicLimit)
            ->values();
        $global = $ranked->take($globalLimit)->values();
        $page = in_array($contextDependency, ['page_deictic', 'mixed'], true)
            ? $ranked->where('page_match', true)->take($pageLimit)->values()
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
                'global' => $global->count(),
                'page' => $page->count(),
            ],
        ];
    }
}
