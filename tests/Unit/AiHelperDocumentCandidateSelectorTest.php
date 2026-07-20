<?php

namespace Tests\Unit;

use App\Models\AiHelperKnowledgeEntry;
use App\Services\AiHelperDocumentCandidateSelector;
use Tests\TestCase;

class AiHelperDocumentCandidateSelectorTest extends TestCase
{
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
}
