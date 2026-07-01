<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use RuntimeException;

class AiHelperOpenAiService
{
    public function isAvailable(): bool
    {
        return (bool) config('ai_helper.enabled') && trim((string) config('ai_helper.api_key')) !== '';
    }

    /**
     * @param callable(string): void $onDelta
     * @return array{response_id: ?string}
     */
    public function streamResponse(string $instructions, array $input, callable $onDelta): array
    {
        if (! $this->isAvailable()) {
            throw new RuntimeException('AI helper is not configured.');
        }

        $client = new Client([
            'base_uri' => rtrim((string) config('ai_helper.base_url'), '/').'/',
            'timeout' => (int) config('ai_helper.timeout', 60),
        ]);

        try {
            $response = $client->request('POST', 'responses', [
                'headers' => [
                    'Authorization' => 'Bearer '.config('ai_helper.api_key'),
                    'Accept' => 'text/event-stream',
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'model' => config('ai_helper.model'),
                    'instructions' => $instructions,
                    'input' => $input,
                    'stream' => true,
                    'store' => false,
                ],
                'stream' => true,
            ]);
        } catch (RequestException $e) {
            $message = $e->getResponse()?->getStatusCode()
                ? 'AI helper provider request failed.'
                : 'AI helper provider is unavailable.';
            throw new RuntimeException($message, previous: $e);
        } catch (GuzzleException $e) {
            throw new RuntimeException('AI helper provider is unavailable.', previous: $e);
        }

        $body = $response->getBody();
        $buffer = '';
        $eventName = '';
        $dataLines = [];
        $responseId = null;

        $flushEvent = function () use (&$eventName, &$dataLines, &$responseId, $onDelta) {
            if ($eventName === '' && $dataLines === []) {
                return;
            }

            $payload = implode("\n", $dataLines);
            $decoded = json_decode($payload, true);
            if (is_array($decoded)) {
                if ($eventName === 'response.output_text.delta') {
                    $delta = (string) ($decoded['delta'] ?? '');
                    if ($delta !== '') {
                        $onDelta($delta);
                    }
                }

                if ($eventName === 'response.completed') {
                    $responseId = $decoded['response']['id'] ?? $responseId;
                }
            }

            $eventName = '';
            $dataLines = [];
        };

        while (! $body->eof()) {
            $buffer .= $body->read(1024);
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

        return ['response_id' => $responseId];
    }
}
