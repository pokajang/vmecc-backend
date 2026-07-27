<?php

namespace Tests\Feature;

use App\Http\Requests\AiHelper\StreamAiHelperMessageRequest;
use App\Models\AiHelperMessage;
use App\Models\User;
use App\Services\AiHelperEmbeddedTaskService;
use App\Services\AiHelperOpenAiService;
use App\Services\AiHelperProviderException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AiHelperEmbeddedTaskApiTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('canonicalEmbeddedTaskRequests')]
    public function test_canonical_embedded_task_requests_reach_the_structured_provider(
        string $task,
        string $message,
        array $pageContext,
        array $providerData,
        string $expectedContent,
    ): void {
        config([
            'ai_helper.enabled' => true,
            'ai_helper.api_key' => 'test-key',
            'ai_helper.knowledge_strict_readiness' => true,
        ]);
        $this->actingAs(User::factory()->create(['status' => 'active']));
        $this->mock(AiHelperOpenAiService::class, function ($mock) use ($providerData) {
            $mock->shouldReceive('isAvailable')->andReturnTrue();
            $mock->shouldNotReceive('streamResponse');
            $mock->shouldReceive('structuredResponse')->once()->andReturn([
                'data' => $providerData,
                'response_id' => 'response-embedded-1',
                'provider_request_id' => 'request-embedded-1',
                'usage' => ['input_tokens' => 25, 'output_tokens' => 8],
            ]);
        });

        $content = $this->postJson('/api/ai-helper/messages/stream', [
            'message' => $message,
            'conversation_purpose' => 'embedded_helper',
            'embedded_task' => $task,
            'page_context' => $pageContext,
            'response_language' => 'en',
        ])->assertOk()->streamedContent();

        $this->assertStringContainsString('event: delta', $content);
        $this->assertStringContainsString('"embedded_task":"'.$task.'"', $content);
        $this->assertStringContainsString($expectedContent, $content);
        $this->assertStringContainsString('event: done', $content);
        $this->assertSame(0, AiHelperMessage::query()->count());
    }

    public static function canonicalEmbeddedTaskRequests(): array
    {
        $inspectionContext = [
            'path' => '/inspection/general',
            'search' => '',
            'route_key' => 'inspection.form.finding',
            'route_name' => '',
            'module_key' => 'inspection',
            'title' => 'Inspection Finding',
            'params' => [
                'inspection_type' => 'General Inspection',
                'zone' => '1',
                'main_area' => 'Manjung Hub',
                'location' => 'Reception',
            ],
        ];
        $ercoContext = [
            'path' => '/report/erco',
            'search' => '',
            'route_key' => 'reports.erco.form',
            'route_name' => '',
            'module_key' => 'reports',
            'title' => 'ERCO Report Form',
            'params' => ['report_type' => 'erco'],
        ];

        return [
            'inspection translation' => [
                AiHelperEmbeddedTaskService::INSPECTION_TRANSLATE_FINDING,
                '{"sourceText":"laluan kecemasan terhalang"}',
                $inspectionContext,
                ['text' => 'Emergency exit was obstructed.'],
                'Emergency exit was obstructed.',
            ],
            'ERCO summary generation' => [
                AiHelperEmbeddedTaskService::ERCO_GENERATE_SUMMARY,
                '{"reportType":"erco","summary":""}',
                $ercoContext,
                ['summary' => 'Pump was isolated safely.'],
                'Pump was isolated safely.',
            ],
            'ERCO summary improvement' => [
                AiHelperEmbeddedTaskService::ERCO_IMPROVE_SUMMARY,
                '{"reportType":"erco","summary":"Pump isolated safely."}',
                $ercoContext,
                ['summary' => 'The pump was isolated safely.'],
                'The pump was isolated safely.',
            ],
            'ERCO report review' => [
                AiHelperEmbeddedTaskService::ERCO_REVIEW_REPORT,
                '{"reportType":"erco","chronology":[]}',
                $ercoContext,
                ['items' => [['status' => 'looks_ok', 'message' => 'Chronology is clear.']]],
                'Chronology is clear.',
            ],
        ];
    }

    public function test_embedded_task_message_length_boundaries_match_the_frontend_contract(): void
    {
        config(['ai_helper.embedded_task_max_message_length' => 12000]);

        $atLimit = StreamAiHelperMessageRequest::create('/api/ai-helper/messages/stream', 'POST', [
            'message' => str_repeat('x', 12000),
            'conversation_purpose' => 'embedded_helper',
            'embedded_task' => AiHelperEmbeddedTaskService::ERCO_GENERATE_SUMMARY,
        ]);
        $overLimit = StreamAiHelperMessageRequest::create('/api/ai-helper/messages/stream', 'POST', [
            'message' => str_repeat('x', 12001),
            'conversation_purpose' => 'embedded_helper',
            'embedded_task' => AiHelperEmbeddedTaskService::ERCO_GENERATE_SUMMARY,
        ]);

        $atLimitValidator = Validator::make($atLimit->all(), $atLimit->rules());
        $overLimitValidator = Validator::make($overLimit->all(), $overLimit->rules());

        $this->assertFalse($atLimitValidator->fails());
        $this->assertTrue($overLimitValidator->fails());
        $this->assertArrayHasKey('message', $overLimitValidator->errors()->toArray());
    }

    public function test_embedded_tasks_keep_rejecting_unsupported_page_context_snapshots(): void
    {
        config(['ai_helper.enabled' => true, 'ai_helper.api_key' => 'test-key']);
        $this->actingAs(User::factory()->create(['status' => 'active']));
        $this->mock(AiHelperOpenAiService::class, function ($mock) {
            $mock->shouldNotReceive('structuredResponse');
        });

        $nonStringParam = StreamAiHelperMessageRequest::create(
            '/api/ai-helper/messages/stream',
            'POST',
            [
                'message' => '{"reportType":"erco"}',
                'conversation_purpose' => 'embedded_helper',
                'embedded_task' => AiHelperEmbeddedTaskService::ERCO_GENERATE_SUMMARY,
                'page_context' => [
                    'path' => '/report/erco',
                    'params' => ['chronology_count' => 4],
                ],
            ],
        );
        $nonStringParamValidator = Validator::make(
            $nonStringParam->all(),
            $nonStringParam->rules(),
        );

        $this->assertTrue($nonStringParamValidator->fails());
        $this->assertArrayHasKey(
            'page_context.params.chronology_count',
            $nonStringParamValidator->errors()->toArray(),
        );

        $this->postJson('/api/ai-helper/messages/stream', [
            'message' => '{"reportType":"erco"}',
            'conversation_purpose' => 'embedded_helper',
            'embedded_task' => AiHelperEmbeddedTaskService::ERCO_GENERATE_SUMMARY,
            'page_context' => [
                'path' => '/report/erco',
                'module_key' => 'reports',
                'form_snapshot' => ['incident_title' => 'Test'],
            ],
        ])->assertUnprocessable()
            ->assertJsonPath('code', 'AI_HELPER_VALIDATION_FAILED')
            ->assertJsonValidationErrors('page_context');
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
