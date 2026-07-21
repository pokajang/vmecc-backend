<?php

namespace Tests\Unit;

use App\Models\AiHelperKnowledgeEntry;
use App\Services\AiHelperDocumentCandidateSelector;
use Tests\TestCase;

class AiHelperDocumentCandidateSelectorTest extends TestCase
{
    public function test_global_scope_uses_global_score_when_building_candidates(): void
    {
        config([
            'ai_helper.retrieval_v4_global_candidate_limit' => 2,
            'ai_helper.retrieval_v4_topic_candidate_limit' => 0,
            'ai_helper.retrieval_v4_document_candidate_limit' => 8,
        ]);
        $ranked = collect([
            $this->fakeDocument(id: 1, score: 10, globalScore: 3, routeMatch: true),
            $this->fakeDocument(id: 2, score: 9, globalScore: 12, routeMatch: false),
            $this->fakeDocument(id: 3, score: 8, globalScore: 11, routeMatch: true),
        ]);

        $result = (new AiHelperDocumentCandidateSelector)->select($ranked, [
            'context_dependency' => 'explicit_topic',
            'query_scope' => 'global',
        ]);

        $this->assertSame([2, 3], $result['documents']->pluck('entry.id')->slice(0, 2)->all());
    }

    public function test_explicit_topic_lane_survives_the_global_document_cut(): void
    {
        config([
            'ai_helper.knowledge_document_candidate_limit' => 12,
            'ai_helper.retrieval_v4_document_candidate_limit' => 18,
        ]);
        $ranked = collect(range(1, 20))->map(function (int $id): array {
            $entry = new AiHelperKnowledgeEntry;
            $entry->id = $id;

            return [
                'entry' => $entry,
                'score' => 100 - $id,
                'topic_score' => $id === 20 ? 3 : 0,
                'page_match' => $id === 1,
                'exact_match' => false,
            ];
        });

        $result = (new AiHelperDocumentCandidateSelector)->select($ranked, [
            'context_dependency' => 'explicit_topic',
        ]);

        $this->assertContains(20, $result['documents']->pluck('entry.id')->all());
        $this->assertSame(0, $result['lanes']['page']);
    }

    public function test_page_lane_is_reserved_only_for_page_deictic_help(): void
    {
        $entry = new AiHelperKnowledgeEntry;
        $entry->id = 1;
        $ranked = collect([[
            'entry' => $entry,
            'score' => 1,
            'topic_score' => 0,
            'page_match' => true,
            'exact_match' => false,
        ]]);

        $result = (new AiHelperDocumentCandidateSelector)->select($ranked, [
            'context_dependency' => 'page_deictic',
        ]);

        $this->assertSame(1, $result['lanes']['page']);
    }

    private function fakeDocument(int $id, int $score, int $globalScore, bool $routeMatch): array
    {
        $entry = new AiHelperKnowledgeEntry;
        $entry->id = $id;

        return [
            'entry' => $entry,
            'score' => $score,
            'global_score' => $globalScore,
            'topic_score' => 0,
            'page_match' => $routeMatch,
            'exact_match' => false,
        ];
    }
}
