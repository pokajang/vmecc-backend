<?php

namespace Tests\Feature;

use App\Models\AiHelperDocument;
use App\Models\AiHelperKnowledgeChunk;
use App\Models\AiHelperKnowledgeEntry;
use App\Models\User;
use App\Services\AiHelperOpenAiService;
use Database\Seeders\AiHelperSystemGuideSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class AiHelperKnowledgeEvaluationCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_live_evaluation_fails_when_shadow_grounding_would_fail_enforcement(): void
    {
        config([
            'ai_helper.enabled' => true,
            'ai_helper.api_key' => 'test-key',
            'ai_helper.embedding_enabled' => false,
            'ai_helper.rerank_enabled' => false,
            'ai_helper.grounding_verification_mode' => 'shadow',
        ]);
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
        ]);
        AiHelperKnowledgeChunk::create([
            'knowledge_entry_id' => $entry->id,
            'chunk_index' => 0,
            'content' => $entry->content,
            'search_text' => $document->title.' '.$entry->content,
            'content_hash' => hash('sha256', $entry->content),
            'token_estimate' => 20,
            'active' => true,
        ]);
        $this->mock(AiHelperOpenAiService::class, function ($mock) {
            $mock->shouldReceive('isAvailable')->andReturnTrue();
            $mock->shouldReceive('streamResponse')->twice()->andReturnUsing(function ($instructions, $input, $onDelta) {
                $onDelta('999 is the official Malaysian Emergency Service Centre telephone number. [S1]');

                return ['response_id' => 'answer-1'];
            });
            $mock->shouldReceive('structuredResponse')->twice()->andReturn([
                'response_id' => 'verify-1',
                'data' => [
                    'verdict' => 'revise',
                    'question_answered' => true,
                    'claims' => [[
                        'claim' => '999 is the official Malaysian Emergency Service Centre telephone number.',
                        'source_ids' => ['S1'],
                        'supported' => false,
                        'contradicted' => false,
                        'missing_qualifier' => false,
                        'reason' => 'Simulated grounding failure',
                    ]],
                    'missing_requested_facts' => [],
                ],
            ]);
        });

        $exitCode = Artisan::call('ai-helper:evaluate-knowledge', [
            '--live' => true,
            '--case' => ['emergency_number'],
            '--json' => true,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('answer:grounding_would_fail_enforcement', Artisan::output());
    }

    public function test_global_system_guide_suite_gates_cross_route_bm_alias_retrieval_v4(): void
    {
        config([
            'ai_helper.system_guides_enabled' => true,
            'ai_helper.system_guide_final_corpus_enforced' => true,
            'ai_helper.embedding_enabled' => false,
            'ai_helper.retrieval_v3' => true,
            'ai_helper.retrieval_v4' => true,
            'ai_helper.rerank_enabled' => false,
        ]);
        $this->seed(AiHelperSystemGuideSeeder::class);
        Permission::findOrCreate('self.leave', 'web');
        $user = User::factory()->create(['status' => 'active']);
        $user->givePermissionTo('self.leave');
        $actorMap = tempnam(sys_get_temp_dir(), 'ai-helper-eval-');
        file_put_contents($actorMap, json_encode(['permission:self.leave' => $user->id], JSON_THROW_ON_ERROR));

        try {
            $exitCode = Artisan::call('ai-helper:evaluate-knowledge', [
                '--suite' => 'system-guide-global',
                '--case' => ['system-guide-global-alias-leave-bm-noise'],
                '--actor-map' => $actorMap,
                '--json' => true,
            ]);
        } finally {
            @unlink($actorMap);
        }

        $this->assertSame(0, $exitCode, Artisan::output());
    }
}
