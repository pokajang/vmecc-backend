<?php

namespace Tests\Feature;

use App\Models\AiHelperDocument;
use App\Models\AiHelperKnowledgeChunk;
use App\Models\AiHelperKnowledgeEntry;
use Database\Seeders\AiHelperReferenceCorpusSeeder;
use Database\Seeders\AiHelperSystemGuideSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class AiHelperKnowledgeReadinessCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_empty_corpus_is_not_ready(): void
    {
        config(['ai_helper.embedding_enabled' => false]);

        $this->assertSame(1, Artisan::call('ai-helper:knowledge-readiness', ['--json' => true]));
        $this->assertStringContainsString('"ready":false', Artisan::output());
    }

    public function test_invalid_retrieval_thresholds_fail_the_readiness_gate(): void
    {
        $this->createReadyKnowledge();
        config([
            'ai_helper.embedding_enabled' => false,
            'ai_helper.retrieval_min_lexical_coverage' => -0.1,
        ]);

        $this->assertSame(1, Artisan::call('ai-helper:knowledge-readiness', ['--json' => true]));
        $this->assertStringContainsString('"retrieval_configuration_valid":false', Artisan::output());
    }

    public function test_production_gate_also_requires_the_complete_reference_and_system_guide_corpora(): void
    {
        $this->createReadyKnowledge(withEmbedding: true);
        config([
            'ai_helper.enabled' => true,
            'ai_helper.api_key' => 'test-key',
            'ai_helper.retrieval_v2' => true,
            'ai_helper.retrieval_v3' => true,
            'ai_helper.embedding_enabled' => true,
            'ai_helper.rerank_enabled' => true,
            'ai_helper.citation_validation_enabled' => true,
            'ai_helper.critical_fact_validation_enabled' => true,
            'ai_helper.grounding_verification_mode' => 'shadow',
        ]);

        $this->assertSame(1, Artisan::call('ai-helper:knowledge-readiness', [
            '--production' => true,
            '--json' => true,
        ]));

        config(['ai_helper.grounding_verification_mode' => 'enforce']);

        $this->assertSame(1, Artisan::call('ai-helper:knowledge-readiness', [
            '--production' => true,
            '--json' => true,
        ]));
        $output = Artisan::output();
        $this->assertStringContainsString('"production_configuration_valid":true', $output);
        $this->assertStringContainsString('"reference_knowledge_ready":false', $output);
        $this->assertStringContainsString('"system_guides_ready":false', $output);
    }

    public function test_readiness_keeps_unaudited_system_guide_drafts_out_of_release(): void
    {
        config([
            'ai_helper.embedding_enabled' => false,
            'ai_helper.system_guides_enabled' => true,
        ]);
        $this->seed(AiHelperReferenceCorpusSeeder::class);
        $this->seed(AiHelperSystemGuideSeeder::class);

        $this->assertSame(1, Artisan::call('ai-helper:knowledge-readiness', ['--json' => true]));
        $output = Artisan::output();
        $this->assertStringContainsString('"reference_knowledge_ready":true', $output);
        $this->assertStringContainsString('"system_guides_ready":false', $output);
        $this->assertStringContainsString('"role_aware_retrieval_ready":false', $output);
        $this->assertStringContainsString('"linked_to_pdf":34', $output);
        $this->assertStringContainsString('"valid_catalog_metadata":51', $output);
    }

    public function test_readiness_requires_active_status_and_an_index_for_each_entry(): void
    {
        config(['ai_helper.embedding_enabled' => false]);
        $this->createReadyKnowledge();
        $entry = AiHelperKnowledgeEntry::query()->firstOrFail();
        $entry->forceFill(['status' => AiHelperKnowledgeEntry::STATUS_DISABLED])->save();

        $this->assertSame(1, Artisan::call('ai-helper:knowledge-readiness', ['--json' => true]));
        $this->assertStringContainsString('"status_active":0', Artisan::output());

        $entry->forceFill(['status' => AiHelperKnowledgeEntry::STATUS_ACTIVE])->save();
        $entry->chunks()->delete();

        $this->assertSame(1, Artisan::call('ai-helper:knowledge-readiness', ['--json' => true]));
        $this->assertStringContainsString('"indexed":0', Artisan::output());
    }

    private function createReadyKnowledge(bool $withEmbedding = false): void
    {
        $document = AiHelperDocument::create([
            'title' => 'ANNEX 1 Terminologies and Definitions',
            'source_filename' => 'ANNEX 1 Terminologies and Definitions.pdf',
            'source_mime' => 'application/pdf',
            'visibility' => AiHelperDocument::VISIBILITY_SHARED,
        ]);
        $entry = AiHelperKnowledgeEntry::create([
            'source_document_id' => $document->id,
            'title' => $document->title,
            'content' => '999 is the official Malaysian Emergency Service Centre telephone number.',
            'source_mime' => 'text/markdown',
            'visibility' => AiHelperKnowledgeEntry::VISIBILITY_SHARED,
            'status' => AiHelperKnowledgeEntry::STATUS_ACTIVE,
            'review_status' => AiHelperKnowledgeEntry::REVIEW_APPROVED,
            'active' => true,
            'embedding_status' => $withEmbedding ? 'ready' : 'pending',
        ]);
        AiHelperKnowledgeChunk::create([
            'knowledge_entry_id' => $entry->id,
            'chunk_index' => 0,
            'content' => $entry->content,
            'search_text' => $document->title.' '.$entry->content,
            'content_hash' => hash('sha256', $entry->content),
            'token_estimate' => 20,
            'embedding' => $withEmbedding ? [0.1, 0.2, 0.3] : null,
            'embedding_dimensions' => $withEmbedding ? 3 : null,
            'embedding_model' => $withEmbedding ? 'test-embedding-model' : null,
            'active' => true,
        ]);
    }
}
