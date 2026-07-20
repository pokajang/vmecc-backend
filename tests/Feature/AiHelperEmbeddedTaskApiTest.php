<?php

namespace Tests\Feature;

use App\Models\AiHelperMessage;
use App\Models\User;
use App\Services\AiHelperEmbeddedTaskService;
use App\Services\AiHelperOpenAiService;
use App\Services\AiHelperProviderException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiHelperEmbeddedTaskApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_explicit_embedded_task_streams_its_normalized_structured_payload_without_a_chat_thread(): void
    {
        config([
            'ai_helper.enabled' => true,
            'ai_helper.api_key' => 'test-key',
            'ai_helper.knowledge_strict_readiness' => true,
        ]);
        $this->actingAs(User::factory()->create(['status' => 'active']));
        $this->mock(AiHelperOpenAiService::class, function ($mock) {
            $mock->shouldReceive('isAvailable')->andReturnTrue();
            $mock->shouldNotReceive('streamResponse');
            $mock->shouldReceive('structuredResponse')->once()->andReturn([
                'data' => ['text' => 'Emergency exit was obstructed.'],
                'response_id' => 'response-embedded-1',
                'provider_request_id' => 'request-embedded-1',
                'usage' => ['input_tokens' => 25, 'output_tokens' => 8],
            ]);
        });

        $content = $this->postJson('/api/ai-helper/messages/stream', [
            'message' => '{"sourceText":"laluan kecemasan terhalang"}',
            'conversation_purpose' => 'embedded_helper',
            'embedded_task' => AiHelperEmbeddedTaskService::INSPECTION_TRANSLATE_FINDING,
            'page_context' => ['path' => '/inspection'],
            'response_language' => 'en',
        ])->assertOk()->streamedContent();

        $this->assertStringContainsString('event: delta', $content);
        $this->assertStringContainsString('"embedded_task":"inspection_translate_finding"', $content);
        $this->assertStringContainsString('"text":"Emergency exit was obstructed."', $content);
        $this->assertStringContainsString('event: done', $content);
        $this->assertSame(0, AiHelperMessage::query()->count());
    }

    public function test_embedded_task_validation_rejects_unknown_tasks_and_chat_usage(): void
    {
        config(['ai_helper.enabled' => true, 'ai_helper.api_key' => 'test-key']);
        $this->actingAs(User::factory()->create(['status' => 'active']));

        $this->postJson('/api/ai-helper/messages/stream', [
            'message' => 'Translate this.',
            'conversation_purpose' => 'embedded_helper',
            'embedded_task' => 'unknown_task',
        ])->assertUnprocessable()->assertJsonValidationErrors('embedded_task');

        $this->postJson('/api/ai-helper/messages/stream', [
            'message' => 'Translate this.',
            'conversation_purpose' => 'embedded_helper',
        ])->assertUnprocessable()->assertJsonValidationErrors('embedded_task');

        $this->postJson('/api/ai-helper/messages/stream', [
            'message' => 'Translate this.',
            'conversation_purpose' => 'chat',
            'embedded_task' => AiHelperEmbeddedTaskService::INSPECTION_TRANSLATE_FINDING,
        ])->assertUnprocessable()->assertJsonValidationErrors('embedded_task');
    }

    public function test_embedded_tasks_reject_pasted_credentials_before_calling_the_provider(): void
    {
        config(['ai_helper.enabled' => true, 'ai_helper.api_key' => 'test-key']);
        $this->actingAs(User::factory()->create(['status' => 'active']));
        $this->mock(AiHelperOpenAiService::class, function ($mock) {
            $mock->shouldReceive('isAvailable')->andReturnTrue();
            $mock->shouldNotReceive('structuredResponse');
        });

        $this->postJson('/api/ai-helper/messages/stream', [
            'message' => '{"sourceText":"password: VerySecret123"}',
            'conversation_purpose' => 'embedded_helper',
            'embedded_task' => AiHelperEmbeddedTaskService::INSPECTION_TRANSLATE_FINDING,
        ])->assertStatus(422)->assertJsonPath('code', 'AI_HELPER_SENSITIVE_DATA_BLOCKED');
    }

    public function test_structured_provider_failures_keep_their_typed_sse_code(): void
    {
        config(['ai_helper.enabled' => true, 'ai_helper.api_key' => 'test-key']);
        $this->actingAs(User::factory()->create(['status' => 'active']));
        $this->mock(AiHelperOpenAiService::class, function ($mock) {
            $mock->shouldReceive('isAvailable')->andReturnTrue();
            $mock->shouldReceive('structuredResponse')->once()->andThrow(new AiHelperProviderException(
                'AI_HELPER_PROVIDER_RATE_LIMITED',
                'Provider rate limited.',
                true,
                429,
                'request-rate-limited',
            ));
        });

        $content = $this->postJson('/api/ai-helper/messages/stream', [
            'message' => '{"sourceText":"laluan kecemasan terhalang"}',
            'conversation_purpose' => 'embedded_helper',
            'embedded_task' => AiHelperEmbeddedTaskService::INSPECTION_TRANSLATE_FINDING,
            'page_context' => ['path' => '/inspection'],
            'response_language' => 'en',
        ])->assertOk()->streamedContent();

        $this->assertStringContainsString('event: error', $content);
        $this->assertStringContainsString('"code":"AI_HELPER_PROVIDER_RATE_LIMITED"', $content);
    }
}
