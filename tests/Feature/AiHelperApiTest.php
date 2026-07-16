<?php

namespace Tests\Feature;

use App\Models\AiHelperKnowledgeEntry;
use App\Models\User;
use App\Services\AiHelperOpenAiService;
use Database\Seeders\AiHelperKnowledgeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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
        $this->seed(AiHelperKnowledgeSeeder::class);
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
        config(['ai_helper.enabled' => true, 'ai_helper.api_key' => 'test-key']);
        $this->actingAs(User::factory()->create(['status' => 'active']));
        $this->mock(AiHelperOpenAiService::class, function ($mock) {
            $mock->shouldReceive('isAvailable')->andReturnTrue();
            $mock->shouldReceive('streamResponse')->once()->andReturnUsing(function ($instructions, $input, $onDelta) {
                $onDelta('Hello from Ask AI.');

                return ['response_id' => 'resp_test_123'];
            });
        });

        $content = $this->postJson('/api/ai-helper/messages/stream', [
            'message' => 'What can I do here?',
            'page_context' => ['path' => '/dashboard'],
            'new_thread' => true,
        ])->assertOk()->streamedContent();

        $this->assertStringContainsString('event: meta', $content);
        $this->assertStringContainsString('event: heartbeat', $content);
        $this->assertStringContainsString('event: delta', $content);
        $this->assertStringContainsString('event: done', $content);
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
        $this->assertGreaterThan(0, $entry->chunks()->count());
    }

    public function test_admin_diagnostics_declares_markdown_only_mode_without_secrets(): void
    {
        config(['ai_helper.enabled' => true, 'ai_helper.api_key' => 'sk-secret-value']);
        $this->actingAs($this->systemAdministrator());

        $this->getJson('/api/ai-helper/diagnostics')
            ->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.configured', true)
            ->assertJsonPath('data.knowledge_runtime.mode', 'markdown_only')
            ->assertJsonPath('data.knowledge_runtime.pdf_ingestion_enabled', false)
            ->assertJsonPath('data.knowledge_runtime.external_ocr_required', false)
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

    private function markdownUpload(string $name, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'ai-helper-md-');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, 'text/markdown', null, true);
    }
}
