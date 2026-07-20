<?php

namespace Tests\Feature;

use App\Models\AiHelperDocument;
use App\Models\AiHelperKnowledgeChunk;
use App\Models\AiHelperKnowledgeEntry;
use App\Models\User;
use App\Services\AiHelperKnowledgeLifecycleService;
use App\Services\AiHelperKnowledgeService;
use Database\Seeders\AiHelperReferenceCorpusSeeder;
use Database\Seeders\AiHelperSystemGuideSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AiHelperRuntimeReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_runtime_serves_a_previous_reference_index_while_reindexing_but_deployment_blocks(): void
    {
        config([
            'ai_helper.embedding_enabled' => false,
            'ai_helper.reference_corpus_expected_count' => 1,
            'ai_helper.system_guides_enabled' => false,
        ]);
        $entry = $this->readyReferenceEntry();
        $service = app(AiHelperKnowledgeService::class);

        $this->assertTrue($service->corpusReadiness()['ready']);
        app(AiHelperKnowledgeLifecycleService::class)->beginIngestion($entry);

        $readiness = $service->corpusReadiness();
        $this->assertTrue($readiness['ready']);
        $this->assertFalse($readiness['deployment_ready']);
        $this->assertSame(1, $readiness['building_documents']);
        $this->assertSame(1, $readiness['reference_knowledge']['active_usable']);
    }

    public function test_runtime_serves_previous_system_guide_chunks_during_a_controlled_reindex(): void
    {
        config([
            'ai_helper.embedding_enabled' => false,
            'ai_helper.system_guides_enabled' => true,
        ]);
        $this->seed(AiHelperReferenceCorpusSeeder::class);
        $this->seed(AiHelperSystemGuideSeeder::class);
        $guide = AiHelperKnowledgeEntry::query()
            ->where('knowledge_type', AiHelperKnowledgeEntry::KNOWLEDGE_SYSTEM_GUIDE)
            ->where('source_path', 'seed:system-guide:leave-self-service')
            ->firstOrFail();

        app(AiHelperKnowledgeLifecycleService::class)->beginIngestion($guide);

        $readiness = app(AiHelperKnowledgeService::class)->corpusReadiness();
        $this->assertTrue($readiness['ready']);
        $this->assertTrue($readiness['system_guides_ready']);
        $this->assertFalse($readiness['system_guides_deployment_ready']);
        $this->assertFalse($readiness['deployment_ready']);
        $this->assertSame(1, $readiness['system_guides']['building']);

        $user = User::factory()->create(['status' => 'active']);
        $user->assignRole(Role::query()->firstOrCreate([
            'name' => 'System Administrator',
            'guard_name' => 'web',
        ]));
        $context = app(AiHelperKnowledgeService::class)->buildContext(
            ['path' => '/inspection'],
            $user,
            'How do I apply for leave?',
        );
        $this->assertContains($guide->id, $context['retrieval']['document_ids']);
    }

    public function test_runtime_rejects_steady_state_legacy_vectors_but_keeps_lexical_lkg_during_reindex(): void
    {
        config([
            'ai_helper.embedding_enabled' => true,
            'ai_helper.api_key' => 'test-key',
            'ai_helper.embedding_model' => 'test-embedding',
            'ai_helper.embedding_dimensions' => 2,
            'ai_helper.reference_corpus_expected_count' => 1,
            'ai_helper.system_guides_enabled' => false,
        ]);
        $entry = $this->readyReferenceEntry();
        $entry->forceFill([
            'embedding' => [0.1, 0.2],
            'embedding_model' => 'test-embedding',
            'embedding_hash' => 'legacy-routing-hash',
            'embedding_status' => 'ready',
        ])->save();
        $entry->chunks()->firstOrFail()->forceFill([
            'embedding' => [0.1, 0.2],
            'embedding_model' => 'test-embedding',
            'embedding_hash' => 'legacy-chunk-hash',
        ])->save();

        $service = app(AiHelperKnowledgeService::class);
        $steadyState = $service->corpusReadiness();
        $this->assertFalse($steadyState['ready']);
        $this->assertSame(1, $steadyState['reference_knowledge']['incompatible_active_embeddings']);

        app(AiHelperKnowledgeLifecycleService::class)->beginIngestion($entry->fresh());
        $reindexing = $service->corpusReadiness();
        $this->assertTrue($reindexing['ready']);
        $this->assertFalse($reindexing['deployment_ready']);
        $this->assertSame(0, $reindexing['reference_knowledge']['incompatible_active_embeddings']);
    }

    private function readyReferenceEntry(): AiHelperKnowledgeEntry
    {
        $document = AiHelperDocument::create([
            'title' => 'Approved reference',
            'source_filename' => 'approved-reference.pdf',
            'source_mime' => 'application/pdf',
            'visibility' => AiHelperDocument::VISIBILITY_SHARED,
        ]);
        $entry = AiHelperKnowledgeEntry::create([
            'source_document_id' => $document->id,
            'title' => 'Approved reference',
            'content' => 'Approved operational evidence.',
            'source_mime' => 'text/markdown',
            'visibility' => AiHelperKnowledgeEntry::VISIBILITY_SHARED,
            'status' => AiHelperKnowledgeEntry::STATUS_ACTIVE,
            'review_status' => AiHelperKnowledgeEntry::REVIEW_APPROVED,
            'active' => true,
            'extraction_complete' => true,
        ]);
        AiHelperKnowledgeChunk::create([
            'knowledge_entry_id' => $entry->id,
            'chunk_index' => 0,
            'content' => $entry->content,
            'search_text' => $entry->content,
            'content_hash' => hash('sha256', $entry->content),
            'token_estimate' => 10,
            'active' => true,
        ]);

        return $entry;
    }
}
