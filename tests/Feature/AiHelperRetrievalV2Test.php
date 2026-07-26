<?php

namespace Tests\Feature;

use App\Models\AiHelperDocument;
use App\Models\AiHelperKnowledgeChunk;
use App\Models\AiHelperKnowledgeEntry;
use App\Models\User;
use App\Services\AiHelperKnowledgeRetriever;
use App\Services\AiHelperKnowledgeService;
use App\Services\AiHelperOpenAiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiHelperRetrievalV2Test extends TestCase
{
    use RefreshDatabase;

    public function test_exact_annex_matching_searches_the_corpus_before_selecting_chunks(): void
    {
        config(['ai_helper.embedding_enabled' => false]);
        foreach (range(1, 9) as $annex) {
            $this->knowledge("ANNEX {$annex} Procedure", "Procedure content for annex {$annex}.");
        }

        $result = app(AiHelperKnowledgeRetriever::class)->retrieve(
            ['route_key' => '', 'module_key' => ''],
            null,
            'What does Annex 8 require?',
        );

        $this->assertSame(9, $result['trace']['documents_considered']);
        $this->assertSame('ANNEX 8 Procedure', $result['guidance'][0]['title']);
        $this->assertSame('lexical', $result['trace']['mode']);
    }

    public function test_ai_catalogue_excludes_pdf_only_documents(): void
    {
        $this->knowledge('ANNEX 1 Active knowledge', 'Approved Markdown content.');
        AiHelperDocument::create([
            'title' => 'PDF only upload',
            'source_filename' => 'pdf-only.pdf',
            'source_mime' => 'application/pdf',
            'visibility' => AiHelperDocument::VISIBILITY_SHARED,
        ]);

        $context = app(AiHelperKnowledgeService::class)->buildContext([], null, 'List all annexes');

        $this->assertTrue(app(AiHelperKnowledgeService::class)->isCatalogueContext($context));
        $this->assertSame(1, $context['catalogue']['total']);
        $this->assertStringContainsString('ANNEX 1 Active knowledge', app(AiHelperKnowledgeService::class)->catalogueResponse($context));
        $this->assertStringNotContainsString('PDF only upload', app(AiHelperKnowledgeService::class)->catalogueResponse($context));
    }

    public function test_ai_catalogue_includes_markdown_only_knowledge(): void
    {
        AiHelperKnowledgeEntry::create([
            'title' => 'Markdown only response perimeter',
            'content' => 'Approved Markdown content without a PDF attachment.',
            'source_mime' => 'text/markdown',
            'visibility' => AiHelperKnowledgeEntry::VISIBILITY_SHARED,
            'status' => AiHelperKnowledgeEntry::STATUS_ACTIVE,
            'review_status' => AiHelperKnowledgeEntry::REVIEW_APPROVED,
            'active' => true,
        ]);

        $context = app(AiHelperKnowledgeService::class)->buildContext([], null, 'List all annexes');

        $this->assertSame(1, $context['catalogue']['total']);
        $this->assertNull($context['catalogue']['entries'][0]['document_id']);
        $this->assertStringContainsString(
            'Markdown only response perimeter',
            app(AiHelperKnowledgeService::class)->catalogueResponse($context),
        );
    }

    public function test_annex_with_multiple_revisions_keeps_each_revision_available(): void
    {
        config(['ai_helper.embedding_enabled' => false]);
        $this->knowledge('ANNEX 18 ERP for Man Overboard (MOB)', 'Original man overboard response.');
        $this->knowledge('ANNEX 18 ERP for Man Overboard (MOB). Rev 001', 'Revised man overboard response.');

        $result = app(AiHelperKnowledgeRetriever::class)->retrieve(
            ['route_key' => '', 'module_key' => ''],
            null,
            'Explain Annex 18',
        );
        $titles = collect($result['guidance'])->pluck('title')->unique()->values()->all();

        $this->assertCount(2, $titles);
        $this->assertContains('ANNEX 18 ERP for Man Overboard (MOB)', $titles);
        $this->assertContains('ANNEX 18 ERP for Man Overboard (MOB). Rev 001', $titles);
    }

    public function test_reranking_cannot_remove_an_exactly_matched_revision(): void
    {
        config([
            'ai_helper.pipeline_version' => 3,
            'ai_helper.rerank_enabled' => true,
            'ai_helper.embedding_enabled' => false,
        ]);
        $original = $this->knowledge('ANNEX 18 ERP for Man Overboard (MOB)', 'Original man overboard response.');
        $this->knowledge('ANNEX 18 ERP for Man Overboard (MOB). Rev 001', 'Revised man overboard response.');
        $originalChunkId = AiHelperKnowledgeChunk::query()
            ->where('knowledge_entry_id', $original->id)
            ->value('id');
        $this->mock(AiHelperOpenAiService::class, function ($mock) use ($originalChunkId) {
            $mock->shouldReceive('structuredResponse')->once()->andReturn([
                'response_id' => 'rerank-drops-revision',
                'data' => ['results' => [[
                    'chunk_id' => $originalChunkId,
                    'relevance' => 3,
                    'direct_answer' => true,
                    'covers' => ['Annex 18'],
                ]]],
            ]);
        });

        $result = app(AiHelperKnowledgeRetriever::class)->retrieve(
            ['route_key' => '', 'module_key' => ''],
            null,
            'Which Annex 18 revisions are available?',
        );
        $titles = collect($result['guidance'])->pluck('title')->unique();

        $this->assertCount(2, $titles);
        $this->assertContains('ANNEX 18 ERP for Man Overboard (MOB)', $titles);
        $this->assertContains('ANNEX 18 ERP for Man Overboard (MOB). Rev 001', $titles);
    }

    public function test_retrieval_v4_skips_variable_reranking_for_one_exact_document(): void
    {
        config([
            'ai_helper.pipeline_version' => 4,
            'ai_helper.rerank_enabled' => true,
            'ai_helper.rerank_adaptive' => true,
            'ai_helper.embedding_enabled' => false,
        ]);
        $entry = $this->knowledge(
            'ANNEX 1 Terminologies and Definitions',
            '999 is the official Malaysian Emergency Service Centre telephone number.',
        );
        AiHelperKnowledgeChunk::create([
            'knowledge_entry_id' => $entry->id,
            'chunk_index' => 1,
            'content' => 'A second definition in the same exact source.',
            'search_text' => 'ANNEX 1 second definition',
            'content_hash' => hash('sha256', 'A second definition in the same exact source.'),
            'token_estimate' => 20,
            'active' => true,
        ]);
        $this->mock(AiHelperOpenAiService::class, function ($mock) {
            $mock->shouldNotReceive('structuredResponse');
        });

        $result = app(AiHelperKnowledgeRetriever::class)->retrieve(
            ['route_key' => '', 'module_key' => ''],
            null,
            'What is 999 according to Annex 1?',
        );

        $this->assertSame(
            'skipped_high_confidence',
            $result['trace']['rerank']['status'],
        );
        $this->assertSame(
            ['ANNEX 1 Terminologies and Definitions'],
            collect($result['guidance'])->pluck('title')->unique()->values()->all(),
        );
    }

    public function test_catalogue_stream_is_deterministic_and_does_not_call_the_model(): void
    {
        config([
            'ai_helper.enabled' => true,
            'ai_helper.api_key' => 'test-key',
            'ai_helper.knowledge_strict_readiness' => false,
        ]);
        $this->knowledge('ANNEX 1 Active knowledge', 'Approved Markdown content.');
        $this->mock(AiHelperOpenAiService::class, function ($mock) {
            $mock->shouldReceive('isAvailable')->andReturnTrue();
            $mock->shouldNotReceive('streamResponse');
        });
        $this->actingAs(User::factory()->create(['status' => 'active']));

        $content = $this->postJson('/api/ai-helper/messages/stream', [
            'message' => 'List all annexes',
            'page_context' => ['path' => '/dashboard'],
            'new_thread' => true,
        ])->assertOk()->streamedContent();

        $this->assertStringContainsString('1 active AI knowledge documents are available', $content);
        $this->assertStringContainsString('ANNEX 1 Active knowledge', $content);
    }

    public function test_credential_requests_do_not_receive_knowledge_passages(): void
    {
        $this->knowledge('Control room communications', 'The control room coordinates emergency communications.');

        $result = app(AiHelperKnowledgeRetriever::class)->retrieve(
            ['route_key' => '', 'module_key' => ''],
            null,
            'What is the Wi-Fi password for the control room?',
        );

        $this->assertSame('blocked_sensitive', $result['trace']['mode']);
        $this->assertSame([], $result['guidance']);
        $this->assertTrue($result['analysis']['sensitive_request']);
    }

    public function test_retrieval_v3_uses_rank_fusion_and_records_a_fallback_safe_trace(): void
    {
        config([
            'ai_helper.pipeline_version' => 3,
            'ai_helper.rerank_enabled' => false,
            'ai_helper.embedding_enabled' => false,
        ]);
        $this->knowledge('ANNEX 10 ERP Body Injury', 'Call 999 for ambulance assistance.');
        $this->knowledge('ANNEX 11 Epidemic ERP', 'For multiple casualties, call 999 for ambulance assistance.');

        $result = app(AiHelperKnowledgeRetriever::class)->retrieve(
            ['route_key' => '', 'module_key' => ''],
            null,
            'According to Annex 11, what number is used for multiple casualties?',
        );

        $this->assertSame(3, $result['trace']['pipeline_version']);
        $this->assertSame('ANNEX 11 Epidemic ERP', $result['guidance'][0]['title']);
        $this->assertSame('not_run', $result['trace']['rerank']['status']);
        $this->assertGreaterThanOrEqual(1, $result['trace']['candidate_chunks']);
    }

    public function test_retrieval_v3_abstains_when_no_chunk_meets_the_relevance_floor(): void
    {
        config([
            'ai_helper.pipeline_version' => 3,
            'ai_helper.rerank_enabled' => false,
            'ai_helper.embedding_enabled' => false,
        ]);
        $this->knowledge('Emergency vehicle procedure', 'Personnel carry emergency equipment to the incident area.');

        $result = app(AiHelperKnowledgeRetriever::class)->retrieve(
            ['route_key' => '', 'module_key' => ''],
            null,
            'According to Annex 99, what is the payroll escalation deadline?',
        );

        $this->assertSame([], $result['guidance']);
        $this->assertSame('no_relevant_evidence', $result['trace']['relevance_gate']);
    }

    private function knowledge(string $title, string $content): AiHelperKnowledgeEntry
    {
        $document = AiHelperDocument::create([
            'title' => $title,
            'source_filename' => $title.'.pdf',
            'source_mime' => 'application/pdf',
            'visibility' => AiHelperDocument::VISIBILITY_SHARED,
        ]);
        $entry = AiHelperKnowledgeEntry::create([
            'source_document_id' => $document->id,
            'title' => $title,
            'content' => $content,
            'source_mime' => 'text/markdown',
            'visibility' => AiHelperKnowledgeEntry::VISIBILITY_SHARED,
            'status' => AiHelperKnowledgeEntry::STATUS_ACTIVE,
            'review_status' => AiHelperKnowledgeEntry::REVIEW_APPROVED,
            'active' => true,
        ]);
        AiHelperKnowledgeChunk::create([
            'knowledge_entry_id' => $entry->id,
            'chunk_index' => 0,
            'content' => $content,
            'search_text' => $title.' '.$content,
            'content_hash' => hash('sha256', $content),
            'token_estimate' => 20,
            'active' => true,
        ]);

        return $entry;
    }
}
