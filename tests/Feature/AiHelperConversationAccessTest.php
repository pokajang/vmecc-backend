<?php

namespace Tests\Feature;

use App\Models\AiHelperKnowledgeEntry;
use App\Models\AiHelperMessage;
use App\Models\AiHelperThread;
use App\Models\User;
use App\Services\AiHelperConversationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiHelperConversationAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_restricted_assistant_content_is_tombstoned_before_model_history_is_built(): void
    {
        $thread = $this->thread();
        $userMessage = $thread->messages()->create([
            'role' => AiHelperMessage::ROLE_USER,
            'content' => 'How do I approve this?',
            'status' => AiHelperMessage::STATUS_COMPLETED,
        ]);
        $thread->messages()->create([
            'role' => AiHelperMessage::ROLE_ASSISTANT,
            'content' => 'Restricted approval instruction. [S1]',
            'status' => AiHelperMessage::STATUS_COMPLETED,
            'retrieval_metadata' => ['document_ids' => [44]],
        ]);
        $latest = $thread->messages()->create([
            'role' => AiHelperMessage::ROLE_USER,
            'content' => 'What comes next?',
            'status' => AiHelperMessage::STATUS_COMPLETED,
        ]);

        $history = app(AiHelperConversationService::class)->inputForThread($thread, $latest->id, []);

        $this->assertStringNotContainsString('Restricted approval instruction', json_encode($history));
        $this->assertStringContainsString('access changed', json_encode($history));
        $this->assertContains('How do I approve this?', collect($history)->pluck('content')->all());
        $this->assertNotNull($userMessage);
    }

    public function test_thread_preview_does_not_expose_evidence_the_user_can_no_longer_access(): void
    {
        $viewer = User::factory()->create(['status' => 'active']);
        $otherUser = User::factory()->create(['status' => 'active']);
        $entry = AiHelperKnowledgeEntry::query()->create([
            'uploaded_by' => $otherUser->id,
            'knowledge_type' => AiHelperKnowledgeEntry::KNOWLEDGE_UPLOADED_MARKDOWN,
            'title' => 'Private guidance',
            'content' => 'Private workflow details.',
            'source_filename' => 'private.md',
            'source_mime' => 'text/markdown',
            'source_path' => 'knowledge/private.md',
            'scope_type' => AiHelperKnowledgeEntry::SCOPE_GLOBAL,
            'visibility' => AiHelperKnowledgeEntry::VISIBILITY_PERSONAL,
            'status' => AiHelperKnowledgeEntry::STATUS_ACTIVE,
            'review_status' => AiHelperKnowledgeEntry::REVIEW_APPROVED,
            'active' => true,
            'extraction_complete' => true,
        ]);
        $thread = $this->thread($viewer);
        $assistant = $thread->messages()->create([
            'role' => AiHelperMessage::ROLE_ASSISTANT,
            'content' => 'Do not leak this revoked answer.',
            'status' => AiHelperMessage::STATUS_COMPLETED,
            'retrieval_metadata' => ['document_ids' => [$entry->id]],
        ]);
        $assistant->forceFill(['created_at' => now()->addSecond(), 'updated_at' => now()->addSecond()])->save();
        $thread->touch();

        $response = $this->actingAs($viewer)->getJson('/api/ai-helper/threads')->assertOk();

        $preview = (string) data_get($response->json(), 'data.0.last_message');
        $this->assertStringNotContainsString('Do not leak', $preview);
        $this->assertStringContainsString('access has changed', $preview);
    }

    private function thread(?User $user = null): AiHelperThread
    {
        return AiHelperThread::query()->create([
            'user_id' => ($user ?? User::factory()->create(['status' => 'active']))->id,
            'title' => 'Access test',
            'conversation_purpose' => 'chat',
        ]);
    }
}
