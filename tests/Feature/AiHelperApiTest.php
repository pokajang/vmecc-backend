<?php

namespace Tests\Feature;

use App\Models\AiHelperDocument;
use App\Models\AiHelperKnowledgeChunk;
use App\Models\AiHelperKnowledgeEntry;
use App\Models\AiHelperMessage;
use App\Models\User;
use App\Services\AiHelperOpenAiService;
use Database\Seeders\AiHelperReferenceCorpusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AiHelperApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_context_requires_authentication(): void
    {
        $this->getJson('/api/ai-helper/context?path=/inspection')->assertUnauthorized();
    }

    public function test_context_reports_guidance_availability_without_exposing_private_markdown(): void
    {
        $this->seed(AiHelperReferenceCorpusSeeder::class);
        $this->actingAs(User::factory()->create(['status' => 'active']));

        $this->getJson('/api/ai-helper/context?path=/inspection&route_name=Inspection')
            ->assertOk()
            ->assertJsonPath('data.page.route_key', 'inspection')
            ->assertJsonPath('data.page.module_key', 'inspection')
            ->assertJsonPath('data.available', true)
            ->assertJsonMissingPath('data.guidance')
            ->assertJsonMissingPath('data.catalogue');
    }

    public function test_stream_requires_csrf_for_authenticated_session(): void
    {
        config(['ai_helper.enabled' => true, 'ai_helper.api_key' => 'test-key']);
        $this->actingAs(User::factory()->create(['status' => 'active']));

        $this->withHeader('X-CSRF-Token', '')
            ->postJson('/api/ai-helper/messages/stream', ['message' => 'Help me'])
            ->assertStatus(419);
    }

    public function test_stream_returns_clear_unavailable_response_when_disabled(): void
    {
        config(['ai_helper.enabled' => false, 'ai_helper.api_key' => null]);
        $this->actingAs(User::factory()->create(['status' => 'active']));

        $this->postJson('/api/ai-helper/messages/stream', [
            'message' => 'How do inspections work?',
            'page_context' => ['path' => '/inspection'],
        ])
            ->assertStatus(503)
            ->assertJsonPath('code', 'AI_HELPER_UNAVAILABLE')
            ->assertJsonStructure(['request_id']);
    }

    public function test_stream_sse_contract_remains_available(): void
    {
        config([
            'ai_helper.enabled' => true,
            'ai_helper.api_key' => 'test-key',
            'ai_helper.knowledge_strict_readiness' => false,
        ]);
        $this->linkedKnowledge(
            'ANNEX 1 Terminologies and Definitions',
            '999 is the official Malaysian Emergency Service Centre telephone number.',
        );
        $this->actingAs(User::factory()->create(['status' => 'active']));
        $this->mock(AiHelperOpenAiService::class, function ($mock) {
            $mock->shouldReceive('isAvailable')->andReturnTrue();
            $mock->shouldReceive('streamResponse')->once()->andReturnUsing(function ($instructions, $input, $onDelta) {
                $onDelta('999 is the official Malaysian Emergency Service Centre telephone number. [S1]');

                return ['response_id' => 'resp_test_123'];
            });
        });

        $content = $this->postJson('/api/ai-helper/messages/stream', [
            'message' => 'What is 999 according to Annex 1?',
            'page_context' => ['path' => '/dashboard'],
            'new_thread' => true,
        ])->assertOk()->streamedContent();

        $this->assertStringContainsString('event: meta', $content);
        $this->assertStringContainsString('event: heartbeat', $content);
        $this->assertStringContainsString('event: status', $content);
        $this->assertStringContainsString('Checking the answer against its sources', $content);
        $this->assertStringContainsString('event: delta', $content);
        $this->assertStringContainsString('event: done', $content);
    }

    public function test_stream_rejects_an_uncited_operational_answer_before_emitting_it(): void
    {
        config([
            'ai_helper.enabled' => true,
            'ai_helper.api_key' => 'test-key',
            'ai_helper.embedding_enabled' => false,
            'ai_helper.knowledge_strict_readiness' => false,
        ]);
        $this->linkedKnowledge(
            'ANNEX 1 Terminologies and Definitions',
            '999 is the official Malaysian Emergency Service Centre telephone number.',
        );
        $this->actingAs(User::factory()->create(['status' => 'active']));
        $this->mock(AiHelperOpenAiService::class, function ($mock) {
            $mock->shouldReceive('isAvailable')->andReturnTrue();
            $mock->shouldReceive('streamResponse')->twice()->andReturnUsing(function ($instructions, $input, $onDelta) {
                $onDelta('Call 999 immediately for every incident.');

                return ['response_id' => 'resp_uncited'];
            });
        });

        $content = $this->postJson('/api/ai-helper/messages/stream', [
            'message' => 'What is 999 according to Annex 1?',
            'page_context' => ['path' => '/dashboard'],
            'response_language' => 'en',
            'new_thread' => true,
        ])->assertOk()->streamedContent();

        $this->assertStringContainsString('sufficiently sourced answer', $content);
        $this->assertStringNotContainsString('Call 999 immediately', $content);
        $message = AiHelperMessage::query()->where('role', AiHelperMessage::ROLE_ASSISTANT)->latest('id')->firstOrFail();
        $this->assertSame('rejected', $message->retrieval_metadata['citation_validation']['status']);
        $this->assertSame([], $message->sources);
    }

    public function test_unsupported_knowledge_question_returns_deterministic_not_found_without_calling_the_model(): void
    {
        config([
            'ai_helper.enabled' => true,
            'ai_helper.api_key' => 'test-key',
            'ai_helper.embedding_enabled' => false,
            'ai_helper.rerank_enabled' => false,
            'ai_helper.retrieval_v3' => true,
            'ai_helper.knowledge_strict_readiness' => false,
        ]);
        $this->actingAs(User::factory()->create(['status' => 'active']));
        $this->mock(AiHelperOpenAiService::class, function ($mock) {
            $mock->shouldReceive('isAvailable')->andReturnTrue();
            $mock->shouldNotReceive('streamResponse');
            $mock->shouldNotReceive('structuredResponse');
        });

        $content = $this->postJson('/api/ai-helper/messages/stream', [
            'message' => 'What colour is the CEO car?',
            'page_context' => ['path' => '/dashboard'],
            'response_language' => 'en',
            'new_thread' => true,
        ])->assertOk()->streamedContent();

        $this->assertStringContainsString('not found in the available VMECC knowledge', $content);
        $this->assertStringNotContainsString('event: status', $content);
        $message = AiHelperMessage::query()->where('role', AiHelperMessage::ROLE_ASSISTANT)->latest('id')->firstOrFail();
        $this->assertSame([], $message->sources);
        $this->assertSame('deterministic', $message->retrieval_metadata['verification']['status']);
    }

    public function test_legacy_unstructured_embedded_helper_is_rejected(): void
    {
        config([
            'ai_helper.enabled' => true,
            'ai_helper.api_key' => 'test-key',
            'ai_helper.embedding_enabled' => false,
        ]);
        $this->actingAs(User::factory()->create(['status' => 'active']));
        $this->mock(AiHelperOpenAiService::class, function ($mock) {
            $mock->shouldNotReceive('isAvailable');
            $mock->shouldNotReceive('streamResponse');
            $mock->shouldNotReceive('structuredResponse');
        });

        $this->postJson('/api/ai-helper/messages/stream', [
            'message' => 'What is 999 according to Annex 1?',
            'page_context' => ['path' => '/dashboard'],
            'response_language' => 'en',
            'conversation_purpose' => 'embedded_helper',
        ])->assertUnprocessable()->assertJsonValidationErrors('embedded_task');
        $this->assertSame(0, AiHelperMessage::query()->where('role', AiHelperMessage::ROLE_ASSISTANT)->count());
    }

    public function test_markdown_upload_requires_system_administrator(): void
    {
        $this->actingAs(User::factory()->create(['status' => 'active']));

        $this->post('/api/ai-helper/knowledge/markdown', [
            'file' => $this->markdownUpload('guidance.md', '# Guidance'),
            'acknowledged' => 'true',
        ])->assertForbidden();
    }

    public function test_admin_can_upload_markdown_but_review_api_never_returns_content(): void
    {
        $admin = $this->systemAdministrator();
        $this->actingAs($admin);
        $content = <<<'MD'
---
title: Fire Safety Markdown
scope_type: global
---

Keep evacuation routes clear and check emergency access.
MD;

        $response = $this->post('/api/ai-helper/knowledge/markdown', [
            'file' => $this->markdownUpload('fire-safety.md', $content),
            'acknowledged' => 'true',
        ])
            ->assertCreated()
            ->assertJsonPath('data.title', 'Fire Safety Markdown')
            ->assertJsonMissingPath('data.content')
            ->assertJsonMissingPath('data.summary');

        $entryId = (int) $response->json('data.id');
        $this->getJson("/api/ai-helper/knowledge-review/{$entryId}")
            ->assertOk()
            ->assertJsonMissingPath('data.content')
            ->assertJsonMissingPath('data.content_preview')
            ->assertJsonMissingPath('data.chunks')
            ->assertJsonMissingPath('data.source_path');

        $entry = AiHelperKnowledgeEntry::query()->findOrFail($entryId);
        $this->assertSame('text/markdown', $entry->source_mime);
        Storage::disk('local')->assertExists($entry->source_path);
        $this->assertGreaterThan(0, $entry->chunks()->count());
    }

    public function test_admin_diagnostics_declares_markdown_only_mode_without_secrets(): void
    {
        config([
            'ai_helper.enabled' => true,
            'ai_helper.api_key' => 'sk-secret-value',
            'ai_helper.embedding_model' => 'test-embedding',
            'ai_helper.embedding_dimensions' => 2,
        ]);
        $staleEntry = $this->linkedKnowledge('Stale semantic source', 'Approved guidance with a legacy vector.');
        $staleEntry->forceFill([
            'embedding' => [0.1, 0.2],
            'embedding_model' => 'test-embedding',
            'embedding_hash' => 'legacy-routing-hash',
            'embedding_status' => 'ready',
        ])->save();
        $staleEntry->chunks()->firstOrFail()->forceFill([
            'embedding' => [0.1, 0.2],
            'embedding_model' => 'test-embedding',
            'embedding_hash' => 'legacy-chunk-hash',
        ])->save();
        $this->actingAs($this->systemAdministrator());

        $this->getJson('/api/ai-helper/diagnostics')
            ->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.configured', true)
            ->assertJsonPath('data.knowledge_runtime.mode', 'markdown_only')
            ->assertJsonPath('data.knowledge_runtime.pdf_ingestion_enabled', false)
            ->assertJsonPath('data.knowledge_runtime.external_ocr_required', false)
            ->assertJsonPath('data.knowledge_runtime.semantic_ready', false)
            ->assertJsonPath('data.knowledge_runtime.usable_sources', 1)
            ->assertJsonPath('data.knowledge_runtime.semantic_sources', 0)
            ->assertJsonPath('data.knowledge_runtime.incompatible_semantic_sources', 1)
            ->assertJsonStructure(['data' => [
                'knowledge_runtime' => [
                    'retrieval_pipeline_version',
                    'index_fingerprint',
                    'rerank_enabled',
                    'critical_fact_validation_enabled',
                    'grounding_verification_mode',
                ],
                'reliability' => [
                    'sample_size',
                    'verified',
                    'repaired',
                    'rejected',
                    'rerank_fallbacks',
                    'p95_response_ms',
                ],
            ]])
            ->assertJsonMissing(['api_key' => 'sk-secret-value']);
    }

    private function systemAdministrator(): User
    {
        $admin = User::factory()->create(['status' => 'active']);
        $role = Role::query()->firstOrCreate([
            'name' => 'System Administrator',
            'guard_name' => 'web',
        ]);
        $admin->assignRole($role);

        return $admin;
    }

    private function linkedKnowledge(string $title, string $content): AiHelperKnowledgeEntry
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
            'search_text' => $content,
            'content_hash' => hash('sha256', $content),
            'token_estimate' => 20,
            'active' => true,
        ]);

        return $entry;
    }

    private function markdownUpload(string $name, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'ai-helper-md-');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, 'text/markdown', null, true);
    }
}
