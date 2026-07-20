<?php

namespace App\Services;

use DateTimeImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\ResponseInterface;
use Throwable;

class AiHelperOpenAiService
{
    private ?AiHelperProviderCircuitBreaker $resolvedCircuitBreaker = null;

    public function __construct(
        ?AiHelperProviderCircuitBreaker $circuitBreaker = null,
        private readonly ?ClientInterface $client = null,
    ) {
        $this->resolvedCircuitBreaker = $circuitBreaker;
    }

    public function isAvailable(): bool
    {
        return (bool) config('ai_helper.enabled') && trim((string) config('ai_helper.api_key')) !== '';
    }

    /**
     * @param  callable(string): void  $onDelta
     * @return array{response_id: ?string, provider_request_id: ?string, usage: array<string, int>}
     */
    public function streamResponse(
        string $instructions,
        array $input,
        callable $onDelta,
        ?AiHelperRequestDeadline $deadline = null,
        ?string $safetyIdentifier = null,
    ): array {
        $this->assertConfigured('generation');
        $deadline ??= AiHelperRequestDeadline::fromConfig();
        $payload = [
            'model' => config('ai_helper.model'),
            'instructions' => $instructions,
            'input' => $input,
            'stream' => true,
            'store' => false,
            'max_output_tokens' => max(128, (int) config('ai_helper.max_output_tokens', 1200)),
        ];
        if ($safetyIdentifier !== null && trim($safetyIdentifier) !== '') {
            $payload['safety_identifier'] = hash('sha256', trim($safetyIdentifier));
        }

        $response = $this->requestWithRetry(
            'generation',
            [
                'headers' => $this->headers('text/event-stream'),
                'json' => $payload,
                'stream' => true,
            ],
            $deadline,
            (int) config('ai_helper.timeout', 60),
        );
        $providerRequestId = $this->headerValue($response, 'x-request-id');
        $body = $response->getBody();
        $buffer = '';
        $eventName = '';
        $dataLines = [];
        $responseId = null;
        $usage = [];
        $completed = false;
        $outputCharacters = 0;
        $maximumOutputCharacters = max(2000, (int) config('ai_helper.max_output_characters', 20000));

        $flushEvent = function () use (
            &$eventName,
            &$dataLines,
            &$responseId,
            &$usage,
            &$completed,
            &$outputCharacters,
            $maximumOutputCharacters,
            $providerRequestId,
            $onDelta,
        ): void {
            if ($eventName === '' && $dataLines === []) {
                return;
            }

            $decoded = json_decode(implode("\n", $dataLines), true);
            if (is_array($decoded)) {
                $type = $eventName !== '' ? $eventName : (string) ($decoded['type'] ?? '');
                if ($type === 'response.output_text.delta') {
                    $delta = (string) ($decoded['delta'] ?? '');
                    $outputCharacters += strlen($delta);
                    if ($outputCharacters > $maximumOutputCharacters) {
                        throw new AiHelperProviderException(
                            'AI_HELPER_PROVIDER_OUTPUT_LIMIT',
                            'AI helper provider output exceeded the configured limit.',
                            false,
                            providerRequestId: $providerRequestId,
                            stage: 'generation',
                        );
                    }
                    if ($delta !== '') {
                        $onDelta($delta);
                    }
                }

                if ($type === 'response.completed') {
                    $completed = true;
                    $responseId = isset($decoded['response']['id'])
                        ? (string) $decoded['response']['id']
                        : $responseId;
                    $usage = $this->normalizeUsage($decoded['response']['usage'] ?? []);
                }

                if (in_array($type, ['error', 'response.failed', 'response.incomplete'], true)) {
                    $error = $decoded['error'] ?? $decoded['response']['error'] ?? [];
                    $retryable = $type === 'response.incomplete';
                    $this->circuitBreaker()->recordFailure($retryable);
                    throw new AiHelperProviderException(
                        $type === 'response.incomplete'
                            ? 'AI_HELPER_PROVIDER_OUTPUT_INCOMPLETE'
                            : 'AI_HELPER_PROVIDER_RESPONSE_FAILED',
                        $type === 'response.incomplete'
                            ? 'AI helper provider returned an incomplete response.'
                            : 'AI helper provider could not complete the response.',
                        $retryable,
                        providerRequestId: $providerRequestId,
                        stage: 'generation',
                    );
                }
            }

            $eventName = '';
            $dataLines = [];
        };

        while (! $body->eof()) {
            try {
                $buffer .= $body->read(1024);
            } catch (Throwable $e) {
                $this->circuitBreaker()->recordFailure(true);
                throw new AiHelperProviderException(
                    'AI_HELPER_PROVIDER_STREAM_INTERRUPTED',
                    'AI helper provider stream was interrupted.',
                    true,
                    providerRequestId: $providerRequestId,
                    stage: 'generation',
                    previous: $e,
                );
            }
            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = rtrim(substr($buffer, 0, $pos), "\r");
                $buffer = substr($buffer, $pos + 1);

                if ($line === '') {
                    $flushEvent();

                    continue;
                }
                if (str_starts_with($line, 'event:')) {
                    $eventName = trim(substr($line, 6));

                    continue;
                }
                if (str_starts_with($line, 'data:')) {
                    $data = trim(substr($line, 5));
                    if ($data !== '[DONE]') {
                        $dataLines[] = $data;
                    }
                }
            }
        }

        if (trim($buffer) !== '') {
            $dataLines[] = trim($buffer);
        }
        $flushEvent();

        if (! $completed) {
            $this->circuitBreaker()->recordFailure(true);
            throw new AiHelperProviderException(
                'AI_HELPER_PROVIDER_OUTPUT_INCOMPLETE',
                'AI helper provider stream ended before completion.',
                true,
                providerRequestId: $providerRequestId,
                stage: 'generation',
            );
        }

        return [
            'response_id' => $responseId,
            'provider_request_id' => $providerRequestId,
            'usage' => $usage,
        ];
    }

    /**
     * @return array{data: array<string, mixed>, response_id: ?string, provider_request_id: ?string, usage: array<string, int>}
     */
    public function structuredResponse(
        string $model,
        string $instructions,
        array $input,
        string $schemaName,
        array $schema,
        ?int $timeout = null,
        ?AiHelperRequestDeadline $deadline = null,
        ?string $safetyIdentifier = null,
    ): array {
        $this->assertConfigured('structured_response');
        $deadline ??= AiHelperRequestDeadline::fromSeconds(
            max(1, $timeout ?? (int) config('ai_helper.timeout', 60)),
        );
        $payload = [
            'model' => $model,
            'instructions' => $instructions,
            'input' => $input,
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => $schemaName,
                    'strict' => true,
                    'schema' => $schema,
                ],
            ],
            'store' => false,
            'max_output_tokens' => max(128, (int) config('ai_helper.max_output_tokens', 1200)),
        ];
        if ($safetyIdentifier !== null && trim($safetyIdentifier) !== '') {
            $payload['safety_identifier'] = hash('sha256', trim($safetyIdentifier));
        }

        $response = $this->requestWithRetry(
            'structured_response',
            [
                'headers' => $this->headers('application/json'),
                'json' => $payload,
            ],
            $deadline,
            max(1, $timeout ?? (int) config('ai_helper.timeout', 60)),
        );
        $providerRequestId = $this->headerValue($response, 'x-request-id');
        $payload = json_decode((string) $response->getBody(), true);
        if (! is_array($payload)) {
            throw $this->invalidResponse('invalid_json', $providerRequestId);
        }
        $text = $this->outputText($payload, $providerRequestId);
        $data = json_decode($text, true);
        if (! is_array($data)) {
            throw $this->invalidResponse('invalid_structured_json', $providerRequestId);
        }

        return [
            'data' => $data,
            'response_id' => isset($payload['id']) ? (string) $payload['id'] : null,
            'provider_request_id' => $providerRequestId,
            'usage' => $this->normalizeUsage($payload['usage'] ?? []),
        ];
    }

    private function assertConfigured(string $stage): void
    {
        if (! $this->isAvailable()) {
            throw new AiHelperProviderException(
                'AI_HELPER_PROVIDER_NOT_CONFIGURED',
                'AI helper is not configured.',
                false,
                stage: $stage,
            );
        }
    }

    /** @param array<string, mixed> $options */
    private function requestWithRetry(
        string $stage,
        array $options,
        AiHelperRequestDeadline $deadline,
        int $requestedTimeout,
    ): ResponseInterface {
        $this->circuitBreaker()->assertAvailable($stage);
        $maximumAttempts = 1 + max(0, min(1, (int) config('ai_helper.provider_max_retries', 1)));

        for ($attempt = 1; $attempt <= $maximumAttempts; $attempt++) {
            $attemptOptions = $options;
            $attemptOptions['timeout'] = $deadline->timeoutFor(max(1, $requestedTimeout));
            $attemptOptions['connect_timeout'] = min(
                $attemptOptions['timeout'],
                max(1, (int) config('ai_helper.connect_timeout', 5)),
            );

            try {
                // Count every real provider attempt, including retries, against
                // the one request-wide budget shared by retrieval and answering.
                $deadline->claimProviderCall($stage);
                $response = $this->httpClient()->request('POST', 'responses', $attemptOptions);
                $this->circuitBreaker()->recordSuccess();

                return $response;
            } catch (GuzzleException $e) {
                $failure = $this->providerFailure($e, $stage);
                $this->circuitBreaker()->recordFailure($failure->retryable);
                if (! $failure->retryable || $attempt >= $maximumAttempts) {
                    throw $failure;
                }

                $delayMs = $this->retryDelayMilliseconds($e, $attempt);
                if (! $deadline->hasTimeFor(($delayMs / 1000) + 0.25)) {
                    throw new AiHelperProviderException(
                        'AI_HELPER_DEADLINE_EXCEEDED',
                        'AI helper response deadline was exceeded.',
                        false,
                        $failure->httpStatus,
                        $failure->providerRequestId,
                        $stage,
                        $failure,
                    );
                }
                if ($delayMs > 0) {
                    usleep($delayMs * 1000);
                }
            }
        }

        throw new AiHelperProviderException(
            'AI_HELPER_PROVIDER_UNAVAILABLE',
            'AI helper provider is unavailable.',
            true,
            stage: $stage,
        );
    }

    private function providerFailure(GuzzleException $error, string $stage): AiHelperProviderException
    {
        $response = $error instanceof RequestException ? $error->getResponse() : null;
        $status = $response?->getStatusCode();
        $providerRequestId = $response ? $this->headerValue($response, 'x-request-id') : null;
        $retryable = $status === null || $status === 408 || $status === 429 || $status >= 500;
        $failureCode = match (true) {
            $status === 408 => 'AI_HELPER_PROVIDER_TIMEOUT',
            $status === 429 => 'AI_HELPER_PROVIDER_RATE_LIMITED',
            $status !== null && $status >= 500 => 'AI_HELPER_PROVIDER_UNAVAILABLE',
            $status === 401 || $status === 403 => 'AI_HELPER_PROVIDER_AUTHENTICATION_FAILED',
            $status !== null => 'AI_HELPER_PROVIDER_REQUEST_REJECTED',
            default => 'AI_HELPER_PROVIDER_UNAVAILABLE',
        };
        $message = match ($failureCode) {
            'AI_HELPER_PROVIDER_RATE_LIMITED' => 'AI helper provider is temporarily rate limited.',
            'AI_HELPER_PROVIDER_TIMEOUT' => 'AI helper provider request timed out.',
            'AI_HELPER_PROVIDER_AUTHENTICATION_FAILED' => 'AI helper provider authentication failed.',
            'AI_HELPER_PROVIDER_REQUEST_REJECTED' => 'AI helper provider rejected the request.',
            default => 'AI helper provider is unavailable.',
        };

        return new AiHelperProviderException(
            $failureCode,
            $message,
            $retryable,
            $status,
            $providerRequestId,
            $stage,
            $error,
        );
    }

    private function retryDelayMilliseconds(GuzzleException $error, int $attempt): int
    {
        $maximum = max(0, (int) config('ai_helper.provider_retry_max_delay_ms', 1500));
        $response = $error instanceof RequestException ? $error->getResponse() : null;
        $retryAfter = trim((string) ($response?->getHeaderLine('Retry-After') ?? ''));
        if ($retryAfter !== '') {
            if (ctype_digit($retryAfter)) {
                return min($maximum, ((int) $retryAfter) * 1000);
            }
            try {
                $date = new DateTimeImmutable($retryAfter);

                return min($maximum, max(0, ($date->getTimestamp() - time()) * 1000));
            } catch (Throwable) {
                // Fall through to bounded exponential backoff with jitter.
            }
        }

        $base = max(0, (int) config('ai_helper.provider_retry_base_milliseconds', 150));
        $ceiling = min($maximum, $base * (2 ** max(0, $attempt - 1)));

        return $ceiling > 0 ? random_int((int) floor($ceiling / 2), $ceiling) : 0;
    }

    private function httpClient(): ClientInterface
    {
        return $this->client ?? new Client([
            'base_uri' => rtrim((string) config('ai_helper.base_url'), '/').'/',
        ]);
    }

    private function circuitBreaker(): AiHelperProviderCircuitBreaker
    {
        return $this->resolvedCircuitBreaker ??= app(AiHelperProviderCircuitBreaker::class);
    }

    /** @return array<string, string> */
    private function headers(string $accept): array
    {
        return [
            'Authorization' => 'Bearer '.config('ai_helper.api_key'),
            'Accept' => $accept,
            'Content-Type' => 'application/json',
        ];
    }

    private function headerValue(ResponseInterface $response, string $name): ?string
    {
        $value = trim($response->getHeaderLine($name));

        return $value !== '' ? $value : null;
    }

    /** @return array<string, int> */
    private function normalizeUsage(mixed $usage): array
    {
        if (! is_array($usage)) {
            return [];
        }

        return collect([
            'input_tokens' => $usage['input_tokens'] ?? null,
            'cached_input_tokens' => $usage['input_tokens_details']['cached_tokens'] ?? null,
            'output_tokens' => $usage['output_tokens'] ?? null,
            'reasoning_tokens' => $usage['output_tokens_details']['reasoning_tokens'] ?? null,
            'total_tokens' => $usage['total_tokens'] ?? null,
        ])->filter(fn ($value) => is_numeric($value))
            ->map(fn ($value) => (int) $value)
            ->all();
    }

    private function outputText(array $payload, ?string $providerRequestId): string
    {
        if (is_string($payload['output_text'] ?? null) && trim($payload['output_text']) !== '') {
            return $payload['output_text'];
        }

        foreach ($payload['output'] ?? [] as $output) {
            foreach ($output['content'] ?? [] as $content) {
                if (($content['type'] ?? null) === 'output_text' && is_string($content['text'] ?? null)) {
                    return $content['text'];
                }
            }
        }

        throw $this->invalidResponse('missing_output_text', $providerRequestId);
    }

    private function invalidResponse(string $reason, ?string $providerRequestId): AiHelperProviderException
    {
        return new AiHelperProviderException(
            'AI_HELPER_PROVIDER_INVALID_RESPONSE',
            'AI helper provider returned an invalid response.',
            false,
            providerRequestId: $providerRequestId,
            stage: 'structured_response:'.$reason,
        );
    }
}
