<?php

namespace App\Services;

use App\Models\AiHelperKnowledgeEntry;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Str;
use RuntimeException;

class AiHelperEmbeddingService
{
    public function isAvailable(): bool
    {
        return (bool) config('ai_helper.embedding_enabled', true)
            && trim((string) config('ai_helper.api_key')) !== '';
    }

    /** @return array<int, float>|null */
    public function embedQuery(string $query): ?array
    {
        if (! $this->isAvailable() || trim($query) === '') {
            return null;
        }

        return $this->embedTexts([$query])[0] ?? null;
    }

    public function embedEntry(AiHelperKnowledgeEntry $entry): bool
    {
        if (! $this->isAvailable()) {
            return false;
        }

        $entry->loadMissing('chunks');
        $model = (string) config('ai_helper.embedding_model', 'text-embedding-3-small');
        $chunks = $entry->chunks->where('active', true)->values();
        if ($chunks->isEmpty()) {
            return false;
        }
        $alreadyCurrent = $entry->embedding_status === 'ready'
            && $entry->embedding_model === $model
            && is_array($entry->embedding)
            && $entry->embedding !== []
            && $chunks->every(function ($chunk) use ($model) {
                $source = trim((string) ($chunk->search_text ?: $chunk->content));

                return is_array($chunk->embedding)
                    && $chunk->embedding !== []
                    && $chunk->embedding_model === $model
                    && hash_equals((string) $chunk->embedding_hash, hash('sha256', $model."\n".$source));
            });
        if ($alreadyCurrent) {
            return true;
        }

        $entry->forceFill([
            'embedding_status' => 'processing',
            'embedding_error' => null,
        ])->save();

        try {
            $vectors = [];
            $texts = $chunks->map(fn ($chunk) => trim((string) ($chunk->search_text ?: $chunk->content)))->all();
            foreach ($this->batches($texts) as $batch) {
                $vectors = array_merge($vectors, $this->embedTexts($batch));
            }

            if (count($vectors) !== $chunks->count()) {
                throw new RuntimeException('Embedding provider returned an incomplete result set.');
            }

            foreach ($chunks as $index => $chunk) {
                $source = trim((string) ($chunk->search_text ?: $chunk->content));
                $chunk->forceFill([
                    'embedding' => $vectors[$index],
                    'embedding_model' => $model,
                    'embedding_hash' => hash('sha256', $model."\n".$source),
                    'embedded_at' => now(),
                ])->save();
            }

            $entryVector = $this->centroid($vectors);
            $entry->forceFill([
                'embedding' => $entryVector,
                'embedding_model' => $model,
                'embedding_hash' => hash('sha256', $model."\n".$chunks->pluck('embedding_hash')->join('|')),
                'embedding_status' => 'ready',
                'embedded_at' => now(),
                'embedding_error' => null,
            ])->save();

            return true;
        } catch (\Throwable $e) {
            $entry->forceFill([
                'embedding_status' => 'failed',
                'embedding_error' => Str::limit($e->getMessage(), 1000, ''),
            ])->save();
            throw $e;
        }
    }

    /** @return array<int, array<int, float>> */
    private function embedTexts(array $texts): array
    {
        $texts = array_values(array_map(static fn ($value) => trim((string) $value), $texts));
        if ($texts === [] || collect($texts)->contains(fn (string $value) => $value === '')) {
            throw new RuntimeException('Embedding input cannot be empty.');
        }

        $client = new Client([
            'base_uri' => rtrim((string) config('ai_helper.base_url'), '/').'/',
            'timeout' => max(5, (int) config('ai_helper.embedding_timeout', 30)),
        ]);
        $payload = [
            'model' => (string) config('ai_helper.embedding_model', 'text-embedding-3-small'),
            'input' => $texts,
            'encoding_format' => 'float',
        ];
        $dimensions = (int) config('ai_helper.embedding_dimensions', 512);
        if ($dimensions > 0) {
            $payload['dimensions'] = $dimensions;
        }

        try {
            $response = $client->post('embeddings', [
                'headers' => [
                    'Authorization' => 'Bearer '.config('ai_helper.api_key'),
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ],
                'json' => $payload,
            ]);
        } catch (RequestException $e) {
            if ($e->getResponse()?->getStatusCode() === 400) {
                throw new RuntimeException('AI knowledge embedding request was rejected; reduce the chunk or batch size.', previous: $e);
            }
            throw new RuntimeException('AI knowledge embedding provider is unavailable.', previous: $e);
        } catch (GuzzleException $e) {
            throw new RuntimeException('AI knowledge embedding provider is unavailable.', previous: $e);
        }

        $decoded = json_decode((string) $response->getBody(), true);
        $data = is_array($decoded['data'] ?? null) ? $decoded['data'] : [];
        usort($data, static fn (array $left, array $right) => ((int) ($left['index'] ?? 0)) <=> ((int) ($right['index'] ?? 0)));
        $vectors = [];
        foreach ($data as $item) {
            $vector = $item['embedding'] ?? null;
            if (! is_array($vector) || $vector === [] || collect($vector)->contains(fn ($value) => ! is_numeric($value))) {
                throw new RuntimeException('Embedding provider returned an invalid vector.');
            }
            $vectors[] = array_map(static fn ($value) => (float) $value, $vector);
        }

        return $vectors;
    }

    /** @return array<int, array<int, string>> */
    private function batches(array $texts): array
    {
        $maximumCount = max(1, min(128, (int) config('ai_helper.embedding_batch_size', 32)));
        $tokenBudget = max(1000, min(250000, (int) config('ai_helper.embedding_batch_token_budget', 100000)));
        $maximumCharacters = max(1000, (int) config('ai_helper.embedding_max_input_characters', 24000));
        $batches = [];
        $current = [];
        $estimatedTokens = 0;

        foreach ($texts as $text) {
            $text = trim((string) $text);
            $characters = Str::length($text);
            if ($characters > $maximumCharacters) {
                throw new RuntimeException("Embedding input exceeds the configured {$maximumCharacters}-character limit; re-index the source with smaller chunks.");
            }
            // UTF-8 byte length divided by two deliberately overestimates most
            // English/Malay operational text, leaving headroom under API limits.
            $textTokens = max(1, (int) ceil(strlen($text) / 2));
            if ($current !== [] && (count($current) >= $maximumCount || $estimatedTokens + $textTokens > $tokenBudget)) {
                $batches[] = $current;
                $current = [];
                $estimatedTokens = 0;
            }
            $current[] = $text;
            $estimatedTokens += $textTokens;
        }
        if ($current !== []) {
            $batches[] = $current;
        }

        return $batches;
    }

    /** @return array<int, float> */
    private function centroid(array $vectors): array
    {
        $dimensions = count($vectors[0] ?? []);
        if ($dimensions === 0) {
            return [];
        }
        $centroid = array_fill(0, $dimensions, 0.0);
        foreach ($vectors as $vector) {
            if (count($vector) !== $dimensions) {
                throw new RuntimeException('Embedding vectors have inconsistent dimensions.');
            }
            foreach ($vector as $index => $value) {
                $centroid[$index] += (float) $value;
            }
        }
        $count = count($vectors);

        return array_map(static fn (float $value) => $value / $count, $centroid);
    }
}
