<?php

namespace Tests\Unit;

use App\Models\AiHelperDocument;
use App\Models\AiHelperKnowledgeChunk;
use App\Models\AiHelperKnowledgeEntry;
use App\Services\AiHelperOpenAiService;
use App\Services\AiHelperPassageReranker;
use RuntimeException;
use Tests\TestCase;

class AiHelperPassageRerankerTest extends TestCase
{
    public function test_it_applies_only_valid_chunk_ids_from_the_structured_ranking(): void
    {
        config(['ai_helper.rerank_enabled' => true]);
        $this->mock(AiHelperOpenAiService::class, function ($mock) {
            $mock->shouldReceive('structuredResponse')->once()->andReturn([
                'response_id' => 'rerank-1',
                'data' => ['results' => [
                    ['chunk_id' => 2, 'relevance' => 3, 'direct_answer' => true, 'covers' => ['answer']],
                    ['chunk_id' => 999, 'relevance' => 3, 'direct_answer' => true, 'covers' => []],
                    ['chunk_id' => 1, 'relevance' => 1, 'direct_answer' => false, 'covers' => []],
                ]],
            ]);
        });

        $result = app(AiHelperPassageReranker::class)->rerank(
            'question',
            ['subqueries' => ['question']],
            collect([$this->candidate(1), $this->candidate(2), $this->candidate(3)]),
        );

        $this->assertSame([2, 1], $result['candidates']->pluck('chunk.id')->all());
        $this->assertFalse($result['metadata']['fallback']);
        $this->assertSame('rerank-1', $result['metadata']['provider_response_id']);
    }

    public function test_it_falls_back_to_fused_order_when_the_provider_fails(): void
    {
        config(['ai_helper.rerank_enabled' => true]);
        $this->mock(AiHelperOpenAiService::class, function ($mock) {
            $mock->shouldReceive('structuredResponse')->once()->andThrow(new RuntimeException('unavailable'));
        });
        $candidates = collect([$this->candidate(3), $this->candidate(1)]);

        $result = app(AiHelperPassageReranker::class)->rerank('question', [], $candidates);

        $this->assertSame([3, 1], $result['candidates']->pluck('chunk.id')->all());
        $this->assertTrue($result['metadata']['fallback']);
    }

    public function test_an_empty_model_ranking_falls_back_to_deterministic_candidates(): void
    {
        config(['ai_helper.rerank_enabled' => true, 'ai_helper.rerank_min_relevance' => 1]);
        $this->mock(AiHelperOpenAiService::class, function ($mock) {
            $mock->shouldReceive('structuredResponse')->once()->andReturn([
                'response_id' => 'rerank-none',
                'data' => ['results' => [[
                    'chunk_id' => 1,
                    'relevance' => 0,
                    'direct_answer' => false,
                    'covers' => [],
                ]]],
            ]);
        });

        $result = app(AiHelperPassageReranker::class)->rerank(
            'unsupported question',
            [],
            collect([$this->candidate(1), $this->candidate(2)]),
        );

        $this->assertSame([1, 2], $result['candidates']->pluck('chunk.id')->all());
        $this->assertSame('fallback', $result['metadata']['status']);
        $this->assertTrue($result['metadata']['fallback']);
        $this->assertSame('no_relevant_candidates', $result['metadata']['reason']);
    }

    public function test_it_preserves_a_protected_deterministic_match_omitted_by_the_model(): void
    {
        config(['ai_helper.rerank_enabled' => true]);
        $this->mock(AiHelperOpenAiService::class, function ($mock) {
            $mock->shouldReceive('structuredResponse')->once()->andReturn([
                'response_id' => 'rerank-protected',
                'data' => ['results' => [[
                    'chunk_id' => 2,
                    'relevance' => 3,
                    'direct_answer' => true,
                    'covers' => ['answer'],
                ]]],
            ]);
        });
        $protected = $this->candidate(1);
        $protected['protected_match'] = true;

        $result = app(AiHelperPassageReranker::class)->rerank(
            'question',
            [],
            collect([$protected, $this->candidate(2)]),
        );

        $this->assertSame([1, 2], $result['candidates']->pluck('chunk.id')->all());
        $this->assertSame('completed_with_protected_matches', $result['metadata']['status']);
    }

    private function candidate(int $id): array
    {
        $document = new AiHelperDocument(['title' => 'Document '.$id]);
        $entry = new AiHelperKnowledgeEntry(['title' => 'Entry '.$id]);
        $entry->setRelation('sourceDocument', $document);
        $chunk = new AiHelperKnowledgeChunk([
            'content' => 'Evidence '.$id,
            'heading_path' => ['Heading '.$id],
        ]);
        $chunk->id = $id;

        return ['chunk' => $chunk, 'entry' => $entry, 'score' => 1];
    }
}
