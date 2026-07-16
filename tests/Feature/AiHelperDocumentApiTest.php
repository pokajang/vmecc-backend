<?php

namespace Tests\Feature;

use App\Jobs\ProcessAiHelperKnowledgeEntry;
use App\Models\AiHelperDocument;
use App\Models\AiHelperKnowledgeChunk;
use App\Models\AiHelperKnowledgeEntry;
use App\Models\User;
use App\Services\AiHelperKnowledgeService;
use Database\Seeders\AiHelperReferenceCorpusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AiHelperDocumentApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_pdf_upload_is_view_only_and_never_creates_ai_knowledge(): void
    {
        Storage::fake('local');
        Queue::fake();
        $user = User::factory()->create(['status' => 'active']);
        $this->actingAs($user);

        $response = $this->post('/api/ai-helper/documents', [
            'file' => UploadedFile::fake()->createWithContent('response-plan.pdf', "%PDF-1.4\n%%EOF"),
            'title' => 'Response plan',
            'visibility' => AiHelperDocument::VISIBILITY_SHARED,
            'acknowledged' => 'true',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.title', 'Response plan')
            ->assertJsonPath('data.ai_usable', false)
            ->assertJsonPath('data.kind', 'reference_pdf');
        $this->assertDatabaseCount('ai_helper_documents', 1);
        $this->assertDatabaseCount('ai_helper_knowledge_entries', 0);
        Queue::assertNotPushed(ProcessAiHelperKnowledgeEntry::class);
    }

    public function test_document_library_respects_personal_and_shared_visibility(): void
    {
        $owner = User::factory()->create(['status' => 'active']);
        $viewer = User::factory()->create(['status' => 'active']);
        AiHelperDocument::create([
            'uploaded_by' => $owner->id,
            'title' => 'Private document',
            'source_filename' => 'private.pdf',
            'source_mime' => 'application/pdf',
            'visibility' => AiHelperDocument::VISIBILITY_PERSONAL,
        ]);
        AiHelperDocument::create([
            'uploaded_by' => $owner->id,
            'title' => 'Shared document',
            'source_filename' => 'shared.pdf',
            'source_mime' => 'application/pdf',
            'visibility' => AiHelperDocument::VISIBILITY_SHARED,
        ]);

        $this->actingAs($viewer)
            ->getJson('/api/ai-helper/documents')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Shared document');
    }

    public function test_user_facing_markdown_routes_do_not_exist(): void
    {
        $this->actingAs(User::factory()->create(['status' => 'active']));

        $this->getJson('/api/ai-helper/knowledge')->assertNotFound();
        $this->getJson('/api/ai-helper/knowledge/1')->assertNotFound();
        $this->get('/api/ai-helper/knowledge/1/file')->assertNotFound();
    }

    public function test_retrieval_uses_only_markdown_and_citations_link_to_reference_documents(): void
    {
        $document = AiHelperDocument::create([
            'title' => 'Emergency Response Plan',
            'source_filename' => 'emergency-response-plan.pdf',
            'source_mime' => 'application/pdf',
            'visibility' => AiHelperDocument::VISIBILITY_SHARED,
        ]);
        $markdown = AiHelperKnowledgeEntry::create([
            'source_document_id' => $document->id,
            'title' => 'Private corpus title',
            'content' => 'Activate the emergency response team immediately.',
            'source_mime' => 'text/markdown',
            'visibility' => AiHelperKnowledgeEntry::VISIBILITY_SHARED,
            'status' => AiHelperKnowledgeEntry::STATUS_ACTIVE,
            'review_status' => AiHelperKnowledgeEntry::REVIEW_APPROVED,
            'active' => true,
        ]);
        AiHelperKnowledgeChunk::create([
            'knowledge_entry_id' => $markdown->id,
            'chunk_index' => 0,
            'content' => 'Activate the emergency response team immediately.',
            'content_hash' => hash('sha256', 'Activate the emergency response team immediately.'),
            'active' => true,
            'page_start' => 4,
            'page_end' => 4,
        ]);
        AiHelperKnowledgeEntry::create([
            'title' => 'Legacy PDF ingestion',
            'content' => 'This PDF text must never be retrieved.',
            'source_mime' => 'application/pdf',
            'visibility' => AiHelperKnowledgeEntry::VISIBILITY_SHARED,
            'status' => AiHelperKnowledgeEntry::STATUS_ACTIVE,
            'review_status' => AiHelperKnowledgeEntry::REVIEW_APPROVED,
            'active' => true,
        ]);

        $service = app(AiHelperKnowledgeService::class);
        $guidance = $service->guidanceForContext([
            'module_key' => '',
            'route_key' => '',
        ], null, 'emergency response');

        $this->assertCount(1, $guidance);
        $this->assertSame('Emergency Response Plan', $guidance[0]['title']);
        $this->assertSame([[
            'document_id' => $document->id,
            'title' => 'Emergency Response Plan',
            'source_mime' => 'application/pdf',
            'page_start' => 4,
            'page_end' => 4,
        ]], $service->citationsForGuidance($guidance));
    }

    public function test_reference_corpus_seeder_builds_34_linked_pairs_without_pdf_ingestion(): void
    {
        Storage::fake('local');

        $this->seed(AiHelperReferenceCorpusSeeder::class);
        $this->seed(AiHelperReferenceCorpusSeeder::class);

        $this->assertSame(34, AiHelperDocument::query()->count());
        $this->assertSame(34, AiHelperKnowledgeEntry::query()->where('source_mime', 'text/markdown')->count());
        $this->assertSame(34, AiHelperKnowledgeEntry::query()
            ->where('source_mime', 'text/markdown')
            ->whereNotNull('source_document_id')
            ->count());
        $this->assertSame(0, AiHelperKnowledgeEntry::query()->where('source_mime', 'application/pdf')->count());
        $this->assertSame(34, AiHelperKnowledgeEntry::query()->whereHas('chunks')->count());
    }

    public function test_citation_metadata_covers_every_source_id_the_model_can_receive(): void
    {
        config([
            'ai_helper.knowledge_citation_limit' => 2,
            'ai_helper.knowledge_retrieval_limit' => 18,
        ]);
        $guidance = collect(range(1, 13))->map(fn (int $page) => [
            'source_id' => 'S'.$page,
            'source_document_id' => 100,
            'title' => 'Multi-page source',
            'page_start' => $page,
            'page_end' => $page,
        ])->all();

        $citations = app(AiHelperKnowledgeService::class)->citationsForGuidance($guidance);

        $this->assertCount(13, $citations);
        $this->assertSame('S13', $citations[12]['source_id']);
    }
}
