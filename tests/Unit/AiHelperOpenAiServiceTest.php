<?php

namespace Tests\Unit;

use App\Services\AiHelperOpenAiService;
use App\Services\AiHelperProviderCircuitBreaker;
use App\Services\AiHelperProviderException;
use App\Services\AiHelperRequestDeadline;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Tests\TestCase;

class AiHelperOpenAiServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'ai_helper.enabled' => true,
            'ai_helper.api_key' => 'test-key',
            'ai_helper.model' => 'test-model',
            'ai_helper.provider_max_retries' => 1,
            'ai_helper.provider_retry_base_milliseconds' => 0,
            'ai_helper.provider_retry_max_delay_ms' => 0,
            'ai_helper.provider_circuit_failure_threshold' => 10,
            'ai_helper.max_output_tokens' => 321,
            'ai_helper.max_provider_calls_per_request' => 8,
        ]);
    }

    public function test_stream_response_retries_a_rate_limit_once_and_returns_provider_metadata(): void
    {
        $events = <<<'SSE'
event: response.output_text.delta
data: {"delta":"Approved answer. [S1]"}

event: response.completed
data: {"response":{"id":"resp-1","usage":{"input_tokens":20,"input_tokens_details":{"cached_tokens":5},"output_tokens":8,"total_tokens":28}}}

SSE;
        $history = [];
        $mock = new MockHandler([
            new Response(429, ['Retry-After' => '0', 'x-request-id' => 'req-rate']),
            new Response(200, ['Content-Type' => 'text/event-stream', 'x-request-id' => 'req-ok'], $events),
        ]);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));
        $service = new AiHelperOpenAiService(
            app(AiHelperProviderCircuitBreaker::class),
            new Client(['handler' => $stack, 'base_uri' => 'https://provider.test/v1/']),
        );
        $content = '';

        $result = $service->streamResponse(
            'Use sources.',
            [['role' => 'user', 'content' => 'Question?']],
            function (string $delta) use (&$content): void {
                $content .= $delta;
            },
        );

        $this->assertSame('Approved answer. [S1]', $content);
        $this->assertSame('resp-1', $result['response_id']);
        $this->assertSame('req-ok', $result['provider_request_id']);
        $this->assertSame(20, $result['usage']['input_tokens']);
        $this->assertSame(5, $result['usage']['cached_input_tokens']);
        $this->assertCount(2, $history);
        $payload = json_decode((string) $history[1]['request']->getBody(), true);
        $this->assertSame(321, $payload['max_output_tokens']);
        $this->assertFalse($payload['store']);
    }

    public function test_structured_response_throws_a_typed_non_retryable_provider_failure(): void
    {
        $mock = new MockHandler([
            new Response(400, ['x-request-id' => 'req-bad'], '{"error":{"message":"bad"}}'),
        ]);
        $service = new AiHelperOpenAiService(
            app(AiHelperProviderCircuitBreaker::class),
            new Client(['handler' => HandlerStack::create($mock), 'base_uri' => 'https://provider.test/v1/']),
        );

        try {
            $service->structuredResponse(
                'test-model',
                'Return JSON.',
                [['role' => 'user', 'content' => '{}']],
                'result',
                ['type' => 'object', 'additionalProperties' => false, 'properties' => []],
            );
            $this->fail('Expected a typed provider failure.');
        } catch (AiHelperProviderException $exception) {
            $this->assertSame('AI_HELPER_PROVIDER_REQUEST_REJECTED', $exception->failureCode);
            $this->assertSame(400, $exception->httpStatus);
            $this->assertSame('req-bad', $exception->providerRequestId);
            $this->assertFalse($exception->retryable);
        }
    }

    public function test_stream_response_rejects_a_transport_eof_without_completed_event(): void
    {
        $events = <<<'SSE'
event: response.output_text.delta
data: {"delta":"Partial answer"}

SSE;
        $service = new AiHelperOpenAiService(
            app(AiHelperProviderCircuitBreaker::class),
            new Client([
                'handler' => HandlerStack::create(new MockHandler([
                    new Response(200, ['x-request-id' => 'req-partial'], $events),
                ])),
                'base_uri' => 'https://provider.test/v1/',
            ]),
        );

        $this->expectException(AiHelperProviderException::class);
        $this->expectExceptionMessage('ended before completion');

        $service->streamResponse('Answer.', [], static function (): void {});
    }

    public function test_shared_deadline_stops_the_next_provider_call_before_http_is_sent(): void
    {
        config(['ai_helper.max_provider_calls_per_request' => 1]);
        $history = [];
        $mock = new MockHandler([
            new Response(200, ['x-request-id' => 'req-one'], json_encode([
                'id' => 'resp-one',
                'output_text' => '{"ok":true}',
            ])),
            new Response(200, ['x-request-id' => 'req-two'], json_encode([
                'id' => 'resp-two',
                'output_text' => '{"ok":true}',
            ])),
        ]);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));
        $service = new AiHelperOpenAiService(
            app(AiHelperProviderCircuitBreaker::class),
            new Client(['handler' => $stack, 'base_uri' => 'https://provider.test/v1/']),
        );
        $deadline = AiHelperRequestDeadline::fromSeconds(20);
        $schema = [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['ok'],
            'properties' => ['ok' => ['type' => 'boolean']],
        ];

        $service->structuredResponse('test-model', 'JSON.', [], 'one', $schema, null, $deadline);

        try {
            $service->structuredResponse('test-model', 'JSON.', [], 'two', $schema, null, $deadline);
            $this->fail('Expected the request-wide provider call budget to stop the second call.');
        } catch (AiHelperProviderException $exception) {
            $this->assertSame('AI_HELPER_PROVIDER_CALL_BUDGET_EXCEEDED', $exception->failureCode);
        }
        $this->assertSame(1, $deadline->providerCalls());
        $this->assertCount(1, $history);
    }
}
