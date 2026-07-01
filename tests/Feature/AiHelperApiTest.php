<?php

namespace Tests\Feature;

use App\Models\AiHelperMessage;
use App\Models\AiHelperKnowledgeChunk;
use App\Models\AiHelperKnowledgeEntry;
use App\Models\AiHelperThread;
use App\Models\User;
use App\Jobs\ProcessAiHelperKnowledgeEntry;
use App\Services\AiHelperKnowledgeProcessingService;
use App\Services\AiHelperKnowledgeService;
use App\Services\AiHelperConversationService;
use App\Services\AiHelperOpenAiService;
use App\Services\AiHelperPdfKnowledgeExtractor;
use Database\Seeders\AiHelperKnowledgeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AiHelperApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_context_requires_authentication(): void
    {
        $this->getJson('/api/ai-helper/context?path=/inspection')
            ->assertStatus(401);
    }

    public function test_context_returns_matching_guidance(): void
    {
        $this->seed(AiHelperKnowledgeSeeder::class);
        $this->actingAs(User::factory()->create(['status' => 'active']));

        $this->getJson('/api/ai-helper/context?path=/inspection&route_name=Inspection')
            ->assertOk()
            ->assertJsonPath('data.page.route_key', 'inspection')
            ->assertJsonPath('data.page.module_key', 'inspection')
            ->assertJsonPath('data.available', true)
            ->assertJsonPath('data.guidance.0.route_key', 'inspection');
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
            ->assertJsonStructure(['request_id'])
            ->assertJsonMissing(['api_key' => 'test-key']);
    }

    public function test_stream_sse_contract_includes_request_id_and_heartbeat(): void
    {
        config(['ai_helper.enabled' => true, 'ai_helper.api_key' => 'test-key']);
        $this->actingAs(User::factory()->create(['status' => 'active']));

        $this->mock(AiHelperOpenAiService::class, function ($mock) {
            $mock->shouldReceive('isAvailable')->andReturnTrue();
            $mock->shouldReceive('streamResponse')->once()->andReturnUsing(function ($instructions, $input, $onDelta) {
                $onDelta('Hello from Ask AI.');

                return ['response_id' => 'resp_test_123'];
            });
        });

        $response = $this->postJson('/api/ai-helper/messages/stream', [
            'message' => 'What can I do here?',
            'page_context' => ['path' => '/dashboard'],
            'new_thread' => true,
        ])->assertOk();

        $content = $response->streamedContent();
        $this->assertStringContainsString('event: meta', $content);
        $this->assertStringContainsString('event: heartbeat', $content);
        $this->assertStringContainsString('event: delta', $content);
        $this->assertStringContainsString('event: done', $content);
        $this->assertMatchesRegularExpression('/"request_id":"[^"]+"/', $content);
    }

    public function test_stream_rejects_invalid_response_language(): void
    {
        config(['ai_helper.enabled' => false, 'ai_helper.api_key' => null]);
        $this->actingAs(User::factory()->create(['status' => 'active']));

        $this->postJson('/api/ai-helper/messages/stream', [
            'message' => 'Help me',
            'response_language' => 'fr',
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'AI_HELPER_VALIDATION_FAILED')
            ->assertJsonStructure(['request_id', 'errors']);
    }

    public function test_instructions_include_selected_response_language_rule(): void
    {
        $service = app(AiHelperKnowledgeService::class);

        $bmInstructions = $service->instructionsFor([
            'page' => ['path' => '/dashboard', 'route_key' => 'dashboard'],
            'guidance' => [],
        ], 'bm');
        $autoInstructions = $service->instructionsFor([
            'page' => ['path' => '/dashboard', 'route_key' => 'dashboard'],
            'guidance' => [],
        ], 'auto');

        $this->assertStringContainsString('reply in Bahasa Melayu', $bmInstructions);
        $this->assertStringContainsString('same language as the latest user message', $autoInstructions);
    }

    public function test_latest_thread_is_isolated_per_user(): void
    {
        $first = User::factory()->create(['status' => 'active']);
        $second = User::factory()->create(['status' => 'active']);

        $firstThread = AiHelperThread::create([
            'user_id' => $first->id,
            'title' => 'First user chat',
        ]);
        $firstThread->messages()->create([
            'role' => AiHelperMessage::ROLE_USER,
            'content' => 'first user question',
            'status' => AiHelperMessage::STATUS_COMPLETED,
        ]);

        $secondThread = AiHelperThread::create([
            'user_id' => $second->id,
            'title' => 'Second user chat',
        ]);
        $secondThread->messages()->create([
            'role' => AiHelperMessage::ROLE_USER,
            'content' => 'second user question',
            'status' => AiHelperMessage::STATUS_COMPLETED,
        ]);

        $this->actingAs($first);

        $this->getJson('/api/ai-helper/thread')
            ->assertOk()
            ->assertJsonPath('data.thread.id', $firstThread->id)
            ->assertJsonPath('data.messages.0.content', 'first user question')
            ->assertJsonMissing(['content' => 'second user question']);
    }

    public function test_helper_rate_limit_is_applied(): void
    {
        config(['ai_helper.rate_limit_per_minute' => 1]);
        $this->actingAs(User::factory()->create(['status' => 'active']));

        $this->getJson('/api/ai-helper/context?path=/dashboard')->assertOk();
        $this->getJson('/api/ai-helper/context?path=/dashboard')->assertStatus(429);
    }

    public function test_pending_shared_knowledge_is_not_retrieved(): void
    {
        config(['ai_helper.rate_limit_per_minute' => 60]);
        $this->actingAs(User::factory()->create(['status' => 'active']));

        $pending = AiHelperKnowledgeEntry::create([
            'module_key' => 'inspection',
            'route_key' => 'inspection',
            'title' => 'Pending shared guidance',
            'content' => 'Pending content should not appear.',
            'visibility' => AiHelperKnowledgeEntry::VISIBILITY_SHARED,
            'review_status' => AiHelperKnowledgeEntry::REVIEW_PENDING,
            'status' => AiHelperKnowledgeEntry::STATUS_ACTIVE,
            'active' => true,
        ]);
        AiHelperKnowledgeChunk::create([
            'knowledge_entry_id' => $pending->id,
            'chunk_index' => 0,
            'content' => 'Pending content should not appear.',
            'content_hash' => hash('sha256', 'pending'),
            'module_key' => 'inspection',
            'route_key' => 'inspection',
            'active' => true,
        ]);

        $approved = AiHelperKnowledgeEntry::create([
            'module_key' => 'inspection',
            'route_key' => 'inspection',
            'title' => 'Approved shared guidance',
            'content' => 'Approved content should appear.',
            'visibility' => AiHelperKnowledgeEntry::VISIBILITY_SHARED,
            'review_status' => AiHelperKnowledgeEntry::REVIEW_APPROVED,
            'status' => AiHelperKnowledgeEntry::STATUS_ACTIVE,
            'active' => true,
        ]);
        AiHelperKnowledgeChunk::create([
            'knowledge_entry_id' => $approved->id,
            'chunk_index' => 0,
            'content' => 'Approved content should appear.',
            'content_hash' => hash('sha256', 'approved'),
            'module_key' => 'inspection',
            'route_key' => 'inspection',
            'active' => true,
        ]);

        $response = $this->getJson('/api/ai-helper/context?path=/inspection')->assertOk();

        $guidanceText = collect($response->json('data.guidance'))->pluck('content')->join(' ');
        $this->assertStringContainsString('Approved content should appear.', $guidanceText);
        $this->assertStringNotContainsString('Pending content should not appear.', $guidanceText);
    }

    public function test_personal_knowledge_is_retrieved_only_for_uploader(): void
    {
        config(['ai_helper.rate_limit_per_minute' => 60]);
        $owner = User::factory()->create(['status' => 'active']);
        $other = User::factory()->create(['status' => 'active']);

        $entry = AiHelperKnowledgeEntry::create([
            'uploaded_by' => $owner->id,
            'module_key' => 'inspection',
            'route_key' => 'inspection',
            'title' => 'Personal inspection guidance',
            'content' => 'Owner-only content should appear.',
            'visibility' => AiHelperKnowledgeEntry::VISIBILITY_PERSONAL,
            'review_status' => AiHelperKnowledgeEntry::REVIEW_APPROVED,
            'status' => AiHelperKnowledgeEntry::STATUS_ACTIVE,
            'active' => true,
        ]);
        AiHelperKnowledgeChunk::create([
            'knowledge_entry_id' => $entry->id,
            'chunk_index' => 0,
            'content' => 'Owner-only content should appear.',
            'content_hash' => hash('sha256', 'owner-only'),
            'module_key' => 'inspection',
            'route_key' => 'inspection',
            'active' => true,
        ]);

        $this->actingAs($other);
        $otherResponse = $this->getJson('/api/ai-helper/context?path=/inspection')->assertOk();
        $this->assertStringNotContainsString(
            'Owner-only content should appear.',
            collect($otherResponse->json('data.guidance'))->pluck('content')->join(' '),
        );

        $this->actingAs($owner);
        $ownerResponse = $this->getJson('/api/ai-helper/context?path=/inspection')->assertOk();
        $this->assertStringContainsString(
            'Owner-only content should appear.',
            collect($ownerResponse->json('data.guidance'))->pluck('content')->join(' '),
        );
    }

    public function test_global_knowledge_is_retrieved_from_any_page_context(): void
    {
        config(['ai_helper.rate_limit_per_minute' => 60]);
        $this->actingAs(User::factory()->create(['status' => 'active']));

        $entry = AiHelperKnowledgeEntry::create([
            'module_key' => null,
            'route_key' => null,
            'title' => 'General fire safety guidance',
            'content' => 'Fire safety general guidance should appear.',
            'scope_type' => AiHelperKnowledgeEntry::SCOPE_GLOBAL,
            'visibility' => AiHelperKnowledgeEntry::VISIBILITY_SHARED,
            'review_status' => AiHelperKnowledgeEntry::REVIEW_APPROVED,
            'status' => AiHelperKnowledgeEntry::STATUS_ACTIVE,
            'active' => true,
        ]);
        AiHelperKnowledgeChunk::create([
            'knowledge_entry_id' => $entry->id,
            'chunk_index' => 0,
            'content' => 'Fire safety general guidance should appear.',
            'content_hash' => hash('sha256', 'global-fire-safety'),
            'module_key' => null,
            'route_key' => null,
            'active' => true,
        ]);

        $response = $this->getJson('/api/ai-helper/context?path=/inspection')->assertOk();

        $guidanceText = collect($response->json('data.guidance'))->pluck('content')->join(' ');
        $this->assertStringContainsString('Fire safety general guidance should appear.', $guidanceText);
    }

    public function test_global_knowledge_upload_stores_without_route_or_module_scope(): void
    {
        config(['ai_helper.rate_limit_per_minute' => 60]);
        Queue::fake();
        $this->actingAs(User::factory()->create(['status' => 'active']));

        $this->post('/api/ai-helper/knowledge', [
            'file' => UploadedFile::fake()->create('fire-safety.pdf', 12, 'application/pdf'),
            'title' => 'Fire Safety Act',
            'scope_type' => AiHelperKnowledgeEntry::SCOPE_GLOBAL,
            'visibility' => AiHelperKnowledgeEntry::VISIBILITY_PERSONAL,
            'acknowledged' => 'true',
        ])
            ->assertCreated()
            ->assertJsonPath('data.scope_type', AiHelperKnowledgeEntry::SCOPE_GLOBAL)
            ->assertJsonPath('data.module_key', null)
            ->assertJsonPath('data.route_key', null);

        $entry = AiHelperKnowledgeEntry::query()->where('title', 'Fire Safety Act')->first();
        $this->assertNotNull($entry);
        $this->assertNull($entry->module_key);
        $this->assertNull($entry->route_key);
        Queue::assertPushed(ProcessAiHelperKnowledgeEntry::class);
    }

    public function test_module_knowledge_upload_stores_selected_allowed_module(): void
    {
        config(['ai_helper.rate_limit_per_minute' => 60]);
        Queue::fake();
        $this->actingAs(User::factory()->create(['status' => 'active']));

        $this->post('/api/ai-helper/knowledge', [
            'file' => UploadedFile::fake()->create('reports-guide.pdf', 12, 'application/pdf'),
            'title' => 'Reports Guide',
            'scope_type' => AiHelperKnowledgeEntry::SCOPE_MODULE,
            'module_key' => 'reports',
            'visibility' => AiHelperKnowledgeEntry::VISIBILITY_PERSONAL,
            'acknowledged' => 'true',
        ])
            ->assertCreated()
            ->assertJsonPath('data.scope_type', AiHelperKnowledgeEntry::SCOPE_MODULE)
            ->assertJsonPath('data.module_key', 'reports')
            ->assertJsonPath('data.route_key', null);
    }

    public function test_shared_knowledge_upload_is_approved_immediately_for_later_audit(): void
    {
        config([
            'ai_helper.rate_limit_per_minute' => 60,
            'ai_helper.knowledge_require_shared_review' => true,
        ]);
        Queue::fake();
        $this->actingAs(User::factory()->create(['status' => 'active']));

        $this->post('/api/ai-helper/knowledge', [
            'file' => UploadedFile::fake()->create('shared-guide.pdf', 12, 'application/pdf'),
            'title' => 'Shared Guide',
            'scope_type' => AiHelperKnowledgeEntry::SCOPE_GLOBAL,
            'visibility' => AiHelperKnowledgeEntry::VISIBILITY_SHARED,
            'acknowledged' => 'true',
        ])
            ->assertCreated()
            ->assertJsonPath('data.visibility', AiHelperKnowledgeEntry::VISIBILITY_SHARED)
            ->assertJsonPath('data.review_status', AiHelperKnowledgeEntry::REVIEW_APPROVED)
            ->assertJsonPath('data.active', false)
            ->assertJsonPath(
                'message',
                'Knowledge uploaded. Ask AI can use the extracted text after processing. System administrators may audit shared guidance later.',
            );
    }

    public function test_module_knowledge_upload_rejects_invalid_module(): void
    {
        config(['ai_helper.rate_limit_per_minute' => 60]);
        Queue::fake();
        $this->actingAs(User::factory()->create(['status' => 'active']));

        $this->post('/api/ai-helper/knowledge', [
            'file' => UploadedFile::fake()->create('unknown-guide.pdf', 12, 'application/pdf'),
            'title' => 'Unknown Guide',
            'scope_type' => AiHelperKnowledgeEntry::SCOPE_MODULE,
            'module_key' => 'unknown-module',
            'visibility' => AiHelperKnowledgeEntry::VISIBILITY_PERSONAL,
            'acknowledged' => 'true',
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'AI_HELPER_KNOWLEDGE_INVALID_MODULE');

        $this->assertDatabaseMissing('ai_helper_knowledge_entries', [
            'title' => 'Unknown Guide',
        ]);
        Queue::assertNothingPushed();
    }

    public function test_knowledge_upload_rejects_when_user_quota_is_exceeded(): void
    {
        config([
            'ai_helper.rate_limit_per_minute' => 60,
            'ai_helper.knowledge_max_active_uploads_per_user' => 1,
        ]);
        Queue::fake();
        $user = User::factory()->create(['status' => 'active']);
        $this->actingAs($user);

        AiHelperKnowledgeEntry::create([
            'uploaded_by' => $user->id,
            'title' => 'Existing guidance',
            'content' => '',
            'source_size' => 100,
            'visibility' => AiHelperKnowledgeEntry::VISIBILITY_PERSONAL,
            'review_status' => AiHelperKnowledgeEntry::REVIEW_APPROVED,
            'status' => AiHelperKnowledgeEntry::STATUS_ACTIVE,
            'active' => true,
        ]);

        $this->post('/api/ai-helper/knowledge', [
            'file' => UploadedFile::fake()->create('over-limit.pdf', 12, 'application/pdf'),
            'title' => 'Over Limit',
            'scope_type' => AiHelperKnowledgeEntry::SCOPE_GLOBAL,
            'visibility' => AiHelperKnowledgeEntry::VISIBILITY_PERSONAL,
            'acknowledged' => 'true',
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'AI_HELPER_KNOWLEDGE_UPLOAD_LIMIT')
            ->assertJsonStructure(['request_id']);

        Queue::assertNothingPushed();
    }

    public function test_knowledge_storage_quota_counts_soft_deleted_retained_files(): void
    {
        config([
            'ai_helper.rate_limit_per_minute' => 60,
            'ai_helper.knowledge_max_active_uploads_per_user' => 100,
            'ai_helper.knowledge_max_upload_bytes_per_user' => 200,
        ]);
        Queue::fake();
        $user = User::factory()->create(['status' => 'active']);
        $this->actingAs($user);

        $entry = AiHelperKnowledgeEntry::create([
            'uploaded_by' => $user->id,
            'title' => 'Deleted retained file',
            'content' => '',
            'source_size' => 190,
            'source_path' => 'ai-helper/knowledge/test/deleted.pdf',
            'visibility' => AiHelperKnowledgeEntry::VISIBILITY_PERSONAL,
            'review_status' => AiHelperKnowledgeEntry::REVIEW_APPROVED,
            'status' => AiHelperKnowledgeEntry::STATUS_ACTIVE,
            'active' => true,
        ]);
        $entry->delete();

        $this->post('/api/ai-helper/knowledge', [
            'file' => UploadedFile::fake()->create('new.pdf', 12, 'application/pdf'),
            'title' => 'New upload',
            'scope_type' => AiHelperKnowledgeEntry::SCOPE_GLOBAL,
            'visibility' => AiHelperKnowledgeEntry::VISIBILITY_PERSONAL,
            'acknowledged' => 'true',
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'AI_HELPER_KNOWLEDGE_STORAGE_LIMIT');

        Queue::assertNothingPushed();
    }

    public function test_markdown_upload_requires_system_administrator(): void
    {
        config(['ai_helper.rate_limit_per_minute' => 60]);
        $this->actingAs(User::factory()->create(['status' => 'active']));

        $this->post('/api/ai-helper/knowledge/markdown', [
            'file' => $this->markdownUpload('general-guidance.md', '# General Guidance'.PHP_EOL.PHP_EOL.'Use this guidance.'),
            'acknowledged' => 'true',
        ])->assertStatus(403);
    }

    public function test_admin_can_upload_markdown_knowledge_and_chunks_immediately(): void
    {
        config(['ai_helper.rate_limit_per_minute' => 60]);
        $admin = $this->systemAdministrator();
        $this->actingAs($admin);

        $content = <<<'MD'
---
title: Fire Safety Markdown
scope_type: global
tags: fire-safety,safety
summary: Shared fire safety reference for VMECC operations.
---

Fire safety guidance should be available to Ask AI immediately after upload.
Keep evacuation routes clear and check emergency access before operational work starts.
MD;

        $this->post('/api/ai-helper/knowledge/markdown', [
            'file' => $this->markdownUpload('fire-safety.md', $content),
            'acknowledged' => 'true',
        ])
            ->assertCreated()
            ->assertJsonPath('data.title', 'Fire Safety Markdown')
            ->assertJsonPath('data.visibility', AiHelperKnowledgeEntry::VISIBILITY_SHARED)
            ->assertJsonPath('data.review_status', AiHelperKnowledgeEntry::REVIEW_APPROVED)
            ->assertJsonPath('data.status', AiHelperKnowledgeEntry::STATUS_ACTIVE)
            ->assertJsonPath('data.active', true)
            ->assertJsonPath('data.scope_type', AiHelperKnowledgeEntry::SCOPE_GLOBAL)
            ->assertJsonPath('data.source_filename', 'fire-safety.md');

        $entry = AiHelperKnowledgeEntry::query()->where('source_filename', 'fire-safety.md')->first();
        $this->assertNotNull($entry);
        $this->assertSame($admin->id, $entry->uploaded_by);
        $this->assertSame('text/markdown', $entry->source_mime);
        $this->assertSame(AiHelperKnowledgeEntry::VISIBILITY_SHARED, $entry->visibility);
        $this->assertSame(AiHelperKnowledgeEntry::REVIEW_APPROVED, $entry->review_status);
        $this->assertGreaterThan(0, $entry->chunks()->count());

        $response = $this->getJson('/api/ai-helper/context?path=/inspection')->assertOk();
        $guidanceText = collect($response->json('data.guidance'))->pluck('content')->join(' ');
        $this->assertStringContainsString('Fire safety guidance should be available to Ask AI immediately', $guidanceText);
    }

    public function test_markdown_upload_rejects_invalid_file_extension(): void
    {
        config(['ai_helper.rate_limit_per_minute' => 60]);
        $this->actingAs($this->systemAdministrator());

        $this->post('/api/ai-helper/knowledge/markdown', [
            'file' => $this->markdownUpload('not-markdown.txt', 'Plain text is not accepted here.'),
            'acknowledged' => 'true',
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'AI_HELPER_MARKDOWN_INVALID_FILE');
    }

    public function test_markdown_upload_rejects_unsupported_frontmatter_key(): void
    {
        config(['ai_helper.rate_limit_per_minute' => 60]);
        $this->actingAs($this->systemAdministrator());

        $content = <<<'MD'
---
title: Bad Markdown
unexpected_key: no
---

Guidance content.
MD;

        $this->post('/api/ai-helper/knowledge/markdown', [
            'file' => $this->markdownUpload('bad-frontmatter.md', $content),
            'acknowledged' => 'true',
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'AI_HELPER_MARKDOWN_INVALID')
            ->assertJsonStructure(['request_id']);
    }

    public function test_admin_diagnostics_exposes_operational_status_without_secret(): void
    {
        config([
            'ai_helper.rate_limit_per_minute' => 60,
            'ai_helper.enabled' => true,
            'ai_helper.api_key' => 'sk-secret-value',
        ]);
        $this->actingAs($this->systemAdministrator());
        $entry = AiHelperKnowledgeEntry::create([
            'title' => 'Deleted retained file',
            'content' => '',
            'source_size' => 1234,
            'source_path' => 'ai-helper/knowledge/test/deleted.pdf',
            'visibility' => AiHelperKnowledgeEntry::VISIBILITY_SHARED,
            'review_status' => AiHelperKnowledgeEntry::REVIEW_APPROVED,
            'status' => AiHelperKnowledgeEntry::STATUS_ACTIVE,
            'active' => true,
        ]);
        $entry->delete();

        $this->getJson('/api/ai-helper/diagnostics')
            ->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.configured', true)
            ->assertJsonPath('data.storage.used_bytes', 1234)
            ->assertJsonStructure(['data' => ['queue', 'storage', 'recent_failed_uploads'], 'request_id'])
            ->assertJsonMissing(['api_key' => 'sk-secret-value'])
            ->assertJsonMissing(['model']);
    }

    public function test_markdown_upload_requires_acknowledgement(): void
    {
        config(['ai_helper.rate_limit_per_minute' => 60]);
        $this->actingAs($this->systemAdministrator());

        $this->withHeader('Accept', 'application/json')->post('/api/ai-helper/knowledge/markdown', [
            'file' => $this->markdownUpload('missing-ack.md', '# Missing acknowledgement'),
        ])->assertStatus(422);
    }

    public function test_markdown_module_scope_requires_valid_module(): void
    {
        config(['ai_helper.rate_limit_per_minute' => 60]);
        $this->actingAs($this->systemAdministrator());

        $this->post('/api/ai-helper/knowledge/markdown', [
            'file' => $this->markdownUpload('invalid-module.md', '# Invalid Module'.PHP_EOL.PHP_EOL.'Guidance.'),
            'scope_type' => AiHelperKnowledgeEntry::SCOPE_MODULE,
            'module_key' => 'unknown-module',
            'acknowledged' => 'true',
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'AI_HELPER_KNOWLEDGE_INVALID_MODULE');
    }

    public function test_pdf_processing_accepts_text_only_pdf_without_image_warning(): void
    {
        $entry = AiHelperKnowledgeEntry::create([
            'uploaded_by' => User::factory()->create(['status' => 'active'])->id,
            'title' => 'Text PDF',
            'content' => '',
            'source_path' => 'ai-helper/knowledge/test/text.pdf',
            'source_mime' => 'application/pdf',
            'visibility' => AiHelperKnowledgeEntry::VISIBILITY_PERSONAL,
            'review_status' => AiHelperKnowledgeEntry::REVIEW_APPROVED,
            'status' => AiHelperKnowledgeEntry::STATUS_PROCESSING,
            'active' => false,
        ]);

        $this->mock(AiHelperPdfKnowledgeExtractor::class, function ($mock) {
            $mock->shouldReceive('extract')->once()->andReturn($this->pdfExtractionResult(
                text: str_repeat('Readable text content for Ask AI. ', 35),
                pageCount: 2,
            ));
        });

        app(AiHelperKnowledgeProcessingService::class)->process($entry->id);

        $entry->refresh();
        $this->assertSame(AiHelperKnowledgeEntry::STATUS_ACTIVE, $entry->status);
        $this->assertTrue($entry->active);
        $this->assertSame(0, $entry->pdf_image_count);
        $this->assertNull($entry->processing_warnings);
        $this->assertGreaterThan(0, $entry->chunks()->count());
    }

    public function test_pdf_processing_accepts_pdf_with_images_and_stores_warning(): void
    {
        $entry = AiHelperKnowledgeEntry::create([
            'uploaded_by' => User::factory()->create(['status' => 'active'])->id,
            'title' => 'Mixed PDF',
            'content' => '',
            'source_path' => 'ai-helper/knowledge/test/mixed.pdf',
            'source_mime' => 'application/pdf',
            'visibility' => AiHelperKnowledgeEntry::VISIBILITY_PERSONAL,
            'review_status' => AiHelperKnowledgeEntry::REVIEW_APPROVED,
            'status' => AiHelperKnowledgeEntry::STATUS_PROCESSING,
            'active' => false,
        ]);

        $this->mock(AiHelperPdfKnowledgeExtractor::class, function ($mock) {
            $mock->shouldReceive('extract')->once()->andReturn($this->pdfExtractionResult(
                text: str_repeat('Readable workflow guidance with enough useful words for Ask AI. ', 45),
                pageCount: 4,
                imageCount: 3,
                pagesWithImages: 2,
                imageCoverage: 50,
                warnings: ['This PDF contains images. Ask AI used only the readable text.'],
            ));
        });

        app(AiHelperKnowledgeProcessingService::class)->process($entry->id);

        $entry->refresh();
        $this->assertSame(AiHelperKnowledgeEntry::STATUS_ACTIVE, $entry->status);
        $this->assertTrue($entry->active);
        $this->assertSame(3, $entry->pdf_image_count);
        $this->assertSame(2, $entry->pdf_pages_with_images);
        $this->assertSame(50, $entry->pdf_image_coverage_estimate);
        $this->assertSame(
            ['This PDF contains images. Ask AI used only the readable text.'],
            $entry->processing_warnings,
        );
        $this->assertGreaterThan(0, $entry->chunks()->count());
    }

    public function test_pdf_processing_rejects_image_heavy_pdf_with_too_little_text(): void
    {
        $entry = AiHelperKnowledgeEntry::create([
            'uploaded_by' => User::factory()->create(['status' => 'active'])->id,
            'title' => 'Image Heavy PDF',
            'content' => '',
            'source_path' => 'ai-helper/knowledge/test/image-heavy.pdf',
            'source_mime' => 'application/pdf',
            'visibility' => AiHelperKnowledgeEntry::VISIBILITY_PERSONAL,
            'review_status' => AiHelperKnowledgeEntry::REVIEW_APPROVED,
            'status' => AiHelperKnowledgeEntry::STATUS_PROCESSING,
            'active' => false,
        ]);

        $this->mock(AiHelperPdfKnowledgeExtractor::class, function ($mock) {
            $mock->shouldReceive('extract')->once()->andReturn($this->pdfExtractionResult(
                text: 'Short text.',
                pageCount: 3,
                imageCount: 5,
                pagesWithImages: 3,
                imageCoverage: 100,
                warnings: ['This PDF contains images. Ask AI used only the readable text.'],
            ));
        });

        app(AiHelperKnowledgeProcessingService::class)->process($entry->id);

        $entry->refresh();
        $this->assertSame(AiHelperKnowledgeEntry::STATUS_FAILED, $entry->status);
        $this->assertFalse($entry->active);
        $this->assertSame(
            'This PDF appears to be mostly image-based. Ask AI can only learn readable text, so upload a text-based PDF instead.',
            $entry->error,
        );
        $this->assertSame(5, $entry->pdf_image_count);
        $this->assertSame(0, $entry->chunks()->count());
    }

    public function test_knowledge_list_exposes_safe_pdf_metrics_without_full_content(): void
    {
        config(['ai_helper.rate_limit_per_minute' => 60]);
        $this->actingAs(User::factory()->create(['status' => 'active']));

        $entry = AiHelperKnowledgeEntry::create([
            'module_key' => null,
            'route_key' => null,
            'title' => 'Safe Metadata Guidance',
            'content' => 'Full content must not be returned from the list endpoint.',
            'summary' => 'Safe summary.',
            'source_filename' => 'safe-metadata.pdf',
            'source_mime' => 'application/pdf',
            'visibility' => AiHelperKnowledgeEntry::VISIBILITY_SHARED,
            'review_status' => AiHelperKnowledgeEntry::REVIEW_APPROVED,
            'status' => AiHelperKnowledgeEntry::STATUS_ACTIVE,
            'active' => true,
            'pdf_page_count' => 2,
            'pdf_image_count' => 1,
            'pdf_pages_with_images' => 1,
            'pdf_readable_text_characters' => 1200,
            'pdf_readable_word_count' => 180,
            'pdf_image_coverage_estimate' => 50,
            'processing_warnings' => ['This PDF contains images. Ask AI used only the readable text.'],
        ]);
        AiHelperKnowledgeChunk::create([
            'knowledge_entry_id' => $entry->id,
            'chunk_index' => 0,
            'content' => 'Chunk content must not be returned from the list endpoint.',
            'content_hash' => hash('sha256', 'safe-metadata'),
            'active' => true,
        ]);

        $response = $this->getJson('/api/ai-helper/knowledge')->assertOk();
        $first = collect($response->json('data'))->firstWhere('id', $entry->id);

        $this->assertSame('Safe summary.', $first['summary']);
        $this->assertSame(1, $first['pdf_image_count']);
        $this->assertSame(['This PDF contains images. Ask AI used only the readable text.'], $first['processing_warnings']);
        $this->assertArrayNotHasKey('content', $first);
        $this->assertArrayNotHasKey('content_preview', $first);
        $this->assertArrayNotHasKey('chunks', $first);
    }

    public function test_owner_can_fetch_personal_knowledge_detail_with_full_extracted_text(): void
    {
        config(['ai_helper.rate_limit_per_minute' => 60]);
        $owner = User::factory()->create(['status' => 'active']);
        $this->actingAs($owner);

        $entry = AiHelperKnowledgeEntry::create([
            'uploaded_by' => $owner->id,
            'title' => 'Owner knowledge',
            'content' => "Line one\nLine two\nLine three",
            'summary' => 'Owner summary.',
            'source_filename' => 'owner-guide.pdf',
            'source_mime' => 'application/pdf',
            'source_path' => 'ai-helper/knowledge/'.$owner->id.'/owner-guide.pdf',
            'visibility' => AiHelperKnowledgeEntry::VISIBILITY_PERSONAL,
            'review_status' => AiHelperKnowledgeEntry::REVIEW_APPROVED,
            'status' => AiHelperKnowledgeEntry::STATUS_ACTIVE,
            'active' => true,
        ]);

        Storage::fake('local');
        Storage::disk('local')->put($entry->source_path, 'pdf-bytes');

        $this->getJson("/api/ai-helper/knowledge/{$entry->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $entry->id)
            ->assertJsonPath('data.extracted_content', "Line one\nLine two\nLine three")
            ->assertJsonPath('data.extracted_content_available', true)
            ->assertJsonPath('data.original_available', true)
            ->assertJsonMissing(['chunks' => []])
            ->assertJsonMissing(['content_preview' => '']);
    }

    public function test_seeded_markdown_shows_no_original_content(): void
    {
        config(['ai_helper.rate_limit_per_minute' => 60]);
        $owner = User::factory()->create(['status' => 'active']);
        $this->actingAs($owner);

        $entry = AiHelperKnowledgeEntry::create([
            'uploaded_by' => $owner->id,
            'title' => 'Seeded markdown knowledge',
            'content' => "# Seeded heading\n\nSeeded guidance.",
            'summary' => 'Seeded markdown summary.',
            'source_filename' => 'seeded-guidance.md',
            'source_mime' => 'text/markdown',
            'source_path' => 'seed:seeded-guidance',
            'visibility' => AiHelperKnowledgeEntry::VISIBILITY_SHARED,
            'review_status' => AiHelperKnowledgeEntry::REVIEW_APPROVED,
            'status' => AiHelperKnowledgeEntry::STATUS_ACTIVE,
            'active' => true,
        ]);

        $this->getJson("/api/ai-helper/knowledge/{$entry->id}")
            ->assertOk()
            ->assertJsonPath('data.original_available', false)
            ->assertJsonPath('data.extracted_content_available', true)
            ->assertJsonPath('data.extracted_content', "# Seeded heading\n\nSeeded guidance.");
    }

    public function test_non_owner_cannot_fetch_another_users_personal_knowledge_detail(): void
    {
        config(['ai_helper.rate_limit_per_minute' => 60]);
        $owner = User::factory()->create(['status' => 'active']);
        $other = User::factory()->create(['status' => 'active']);

        $entry = AiHelperKnowledgeEntry::create([
            'uploaded_by' => $owner->id,
            'title' => 'Private knowledge',
            'content' => 'Private content.',
            'visibility' => AiHelperKnowledgeEntry::VISIBILITY_PERSONAL,
            'review_status' => AiHelperKnowledgeEntry::REVIEW_APPROVED,
            'status' => AiHelperKnowledgeEntry::STATUS_ACTIVE,
            'active' => true,
        ]);

        $this->actingAs($other);

        $this->getJson("/api/ai-helper/knowledge/{$entry->id}")
            ->assertStatus(404)
            ->assertJsonPath('code', 'AI_HELPER_KNOWLEDGE_NOT_FOUND');
    }

    public function test_non_owner_can_fetch_approved_shared_active_knowledge_detail(): void
    {
        config(['ai_helper.rate_limit_per_minute' => 60]);
        $owner = User::factory()->create(['status' => 'active']);
        $viewer = User::factory()->create(['status' => 'active']);

        $entry = AiHelperKnowledgeEntry::create([
            'uploaded_by' => $owner->id,
            'title' => 'Shared knowledge',
            'content' => 'Shared extracted content.',
            'visibility' => AiHelperKnowledgeEntry::VISIBILITY_SHARED,
            'review_status' => AiHelperKnowledgeEntry::REVIEW_APPROVED,
            'status' => AiHelperKnowledgeEntry::STATUS_ACTIVE,
            'active' => true,
        ]);

        $this->actingAs($viewer);

        $this->getJson("/api/ai-helper/knowledge/{$entry->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $entry->id)
            ->assertJsonPath('data.extracted_content', 'Shared extracted content.');
    }

    public function test_non_owner_cannot_fetch_non_active_shared_knowledge_detail(): void
    {
        config(['ai_helper.rate_limit_per_minute' => 60]);
        $owner = User::factory()->create(['status' => 'active']);
        $viewer = User::factory()->create(['status' => 'active']);

        $entry = AiHelperKnowledgeEntry::create([
            'uploaded_by' => $owner->id,
            'title' => 'Pending shared knowledge',
            'content' => 'Pending content.',
            'visibility' => AiHelperKnowledgeEntry::VISIBILITY_SHARED,
            'review_status' => AiHelperKnowledgeEntry::REVIEW_PENDING,
            'status' => AiHelperKnowledgeEntry::STATUS_ACTIVE,
            'active' => false,
        ]);

        $this->actingAs($viewer);

        $this->getJson("/api/ai-helper/knowledge/{$entry->id}")
            ->assertStatus(404)
            ->assertJsonPath('code', 'AI_HELPER_KNOWLEDGE_NOT_FOUND');
    }

    public function test_owner_can_fetch_processing_knowledge_detail_and_file(): void
    {
        config(['ai_helper.rate_limit_per_minute' => 60]);
        Storage::fake('local');
        $owner = User::factory()->create(['status' => 'active']);
        $this->actingAs($owner);

        $entry = AiHelperKnowledgeEntry::create([
            'uploaded_by' => $owner->id,
            'title' => 'Processing knowledge',
            'content' => '',
            'source_filename' => 'processing.pdf',
            'source_mime' => 'application/pdf',
            'source_path' => 'ai-helper/knowledge/'.$owner->id.'/processing.pdf',
            'visibility' => AiHelperKnowledgeEntry::VISIBILITY_PERSONAL,
            'review_status' => AiHelperKnowledgeEntry::REVIEW_APPROVED,
            'status' => AiHelperKnowledgeEntry::STATUS_PROCESSING,
            'active' => false,
        ]);
        Storage::disk('local')->put($entry->source_path, 'processing-pdf');

        $this->getJson("/api/ai-helper/knowledge/{$entry->id}")
            ->assertOk()
            ->assertJsonPath('data.extracted_content_available', false);

        $this->get("/api/ai-helper/knowledge/{$entry->id}/file")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertHeader('content-disposition', 'inline; filename="processing.pdf"');
    }

    public function test_pdf_file_endpoint_streams_inline_for_visible_knowledge(): void
    {
        config(['ai_helper.rate_limit_per_minute' => 60]);
        Storage::fake('local');
        $user = User::factory()->create(['status' => 'active']);
        $this->actingAs($user);

        $entry = AiHelperKnowledgeEntry::create([
            'uploaded_by' => $user->id,
            'title' => 'PDF knowledge',
            'content' => 'Extracted content.',
            'source_filename' => 'guide.pdf',
            'source_mime' => 'application/pdf',
            'source_path' => 'ai-helper/knowledge/'.$user->id.'/guide.pdf',
            'visibility' => AiHelperKnowledgeEntry::VISIBILITY_PERSONAL,
            'review_status' => AiHelperKnowledgeEntry::REVIEW_APPROVED,
            'status' => AiHelperKnowledgeEntry::STATUS_ACTIVE,
            'active' => true,
        ]);
        Storage::disk('local')->put($entry->source_path, 'pdf-content');

        $this->get("/api/ai-helper/knowledge/{$entry->id}/file")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertHeader('content-disposition', 'inline; filename="guide.pdf"');
    }

    public function test_markdown_file_endpoint_streams_inline_for_visible_knowledge(): void
    {
        config(['ai_helper.rate_limit_per_minute' => 60]);
        Storage::fake('local');
        $user = User::factory()->create(['status' => 'active']);
        $this->actingAs($user);

        $entry = AiHelperKnowledgeEntry::create([
            'uploaded_by' => $user->id,
            'title' => 'Markdown knowledge',
            'content' => 'Rendered guidance.',
            'source_filename' => 'guide.md',
            'source_mime' => 'text/markdown',
            'source_path' => 'ai-helper/knowledge/markdown/'.$user->id.'/guide.md',
            'visibility' => AiHelperKnowledgeEntry::VISIBILITY_SHARED,
            'review_status' => AiHelperKnowledgeEntry::REVIEW_APPROVED,
            'status' => AiHelperKnowledgeEntry::STATUS_ACTIVE,
            'active' => true,
        ]);
        Storage::disk('local')->put($entry->source_path, "---\ntitle: Guide\n---\n\n# Heading\nBody");

        $this->get("/api/ai-helper/knowledge/{$entry->id}/file")
            ->assertOk()
            ->assertHeader('content-type', 'text/markdown; charset=UTF-8')
            ->assertHeader('content-disposition', 'inline; filename="guide.md"');
    }

    public function test_seeded_markdown_file_endpoint_is_not_available(): void
    {
        config(['ai_helper.rate_limit_per_minute' => 60]);
        $user = User::factory()->create(['status' => 'active']);
        $this->actingAs($user);

        $entry = AiHelperKnowledgeEntry::create([
            'uploaded_by' => $user->id,
            'title' => 'Seeded Markdown knowledge',
            'content' => "# Seeded heading\n\nSeeded text.",
            'source_filename' => 'seeded-guide.md',
            'source_mime' => 'text/markdown',
            'source_path' => 'seed:seeded-guide',
            'visibility' => AiHelperKnowledgeEntry::VISIBILITY_SHARED,
            'review_status' => AiHelperKnowledgeEntry::REVIEW_APPROVED,
            'status' => AiHelperKnowledgeEntry::STATUS_ACTIVE,
            'active' => true,
        ]);

        $this->get("/api/ai-helper/knowledge/{$entry->id}/file")
            ->assertStatus(404)
            ->assertJsonPath('code', 'AI_HELPER_KNOWLEDGE_FILE_NOT_FOUND');
    }

    public function test_knowledge_file_endpoint_returns_not_found_when_source_file_is_missing(): void
    {
        config(['ai_helper.rate_limit_per_minute' => 60]);
        Storage::fake('local');
        $user = User::factory()->create(['status' => 'active']);
        $this->actingAs($user);

        $entry = AiHelperKnowledgeEntry::create([
            'uploaded_by' => $user->id,
            'title' => 'Missing file knowledge',
            'content' => 'Extracted content.',
            'source_filename' => 'missing.pdf',
            'source_mime' => 'application/pdf',
            'source_path' => 'ai-helper/knowledge/'.$user->id.'/missing.pdf',
            'visibility' => AiHelperKnowledgeEntry::VISIBILITY_PERSONAL,
            'review_status' => AiHelperKnowledgeEntry::REVIEW_APPROVED,
            'status' => AiHelperKnowledgeEntry::STATUS_ACTIVE,
            'active' => true,
        ]);

        $this->get("/api/ai-helper/knowledge/{$entry->id}/file")
            ->assertStatus(404)
            ->assertJsonPath('code', 'AI_HELPER_KNOWLEDGE_FILE_NOT_FOUND');
    }

    public function test_conversation_input_excludes_failed_empty_messages_and_respects_character_limit(): void
    {
        config([
            'ai_helper.history_turns' => 10,
            'ai_helper.history_max_characters' => 1000,
        ]);
        $thread = AiHelperThread::create([
            'user_id' => User::factory()->create(['status' => 'active'])->id,
            'title' => 'Conversation bounds',
        ]);

        $thread->messages()->create([
            'role' => AiHelperMessage::ROLE_ASSISTANT,
            'content' => '',
            'status' => AiHelperMessage::STATUS_FAILED,
        ]);
        $thread->messages()->create([
            'role' => AiHelperMessage::ROLE_USER,
            'content' => str_repeat('older ', 300),
            'status' => AiHelperMessage::STATUS_COMPLETED,
        ]);
        $current = $thread->messages()->create([
            'role' => AiHelperMessage::ROLE_USER,
            'content' => 'Current short question',
            'status' => AiHelperMessage::STATUS_COMPLETED,
        ]);

        $input = app(AiHelperConversationService::class)->inputForThread($thread, $current->id);

        $this->assertCount(1, $input);
        $this->assertSame('Current short question', $input[0]['content']);
    }

    public function test_admin_rejecting_knowledge_requires_review_note(): void
    {
        config(['ai_helper.rate_limit_per_minute' => 60]);
        $admin = User::factory()->create(['status' => 'active']);
        $role = Role::query()->firstOrCreate([
            'name' => 'System Administrator',
            'guard_name' => 'web',
        ]);
        $admin->assignRole($role);

        $entry = AiHelperKnowledgeEntry::create([
            'module_key' => 'inspection',
            'route_key' => 'inspection',
            'title' => 'Pending shared guidance',
            'content' => 'Pending content.',
            'visibility' => AiHelperKnowledgeEntry::VISIBILITY_SHARED,
            'review_status' => AiHelperKnowledgeEntry::REVIEW_PENDING,
            'status' => AiHelperKnowledgeEntry::STATUS_ACTIVE,
            'active' => false,
        ]);

        $this->actingAs($admin);

        $this->patchJson("/api/ai-helper/knowledge-review/{$entry->id}", [
            'review_status' => AiHelperKnowledgeEntry::REVIEW_REJECTED,
        ])->assertStatus(422);

        $this->patchJson("/api/ai-helper/knowledge-review/{$entry->id}", [
            'review_status' => AiHelperKnowledgeEntry::REVIEW_REJECTED,
            'review_note' => 'The guidance is not applicable to this operation.',
        ])
            ->assertOk()
            ->assertJsonPath('data.review_status', AiHelperKnowledgeEntry::REVIEW_REJECTED)
            ->assertJsonPath('data.active', false);
    }

    private function pdfExtractionResult(
        string $text,
        int $pageCount = 1,
        int $imageCount = 0,
        int $pagesWithImages = 0,
        int $imageCoverage = 0,
        array $warnings = [],
    ): array {
        $words = preg_split('/[^\pL\pN]+/u', $text) ?: [];

        return [
            'text' => $text,
            'page_count' => $pageCount,
            'image_count' => $imageCount,
            'pages_with_images' => $pagesWithImages,
            'readable_text_characters' => strlen($text),
            'readable_word_count' => count(array_filter($words, fn (string $word) => $word !== '')),
            'image_coverage_estimate' => $imageCoverage,
            'warnings' => $warnings,
        ];
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

    private function markdownUpload(string $name, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'ai-helper-md-');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, 'text/markdown', null, true);
    }
}
