<?php

namespace App\Services;

use App\Models\AiHelperKnowledgeChunk;
use App\Models\AiHelperKnowledgeEntry;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class AiHelperEmbeddingService
{
    private const DEFAULT_ROUTING_PROFILE_VERSION = 'routing-v1';

    private const DEFAULT_CHUNK_PROFILE_VERSION = 'contextual-v2';

    public function isAvailable(): bool
    {
        return (bool) config('ai_helper.embedding_enabled', true)
            && trim((string) config('ai_helper.api_key')) !== '';
    }

    /** @return array<int, float>|null */
    public function embedQuery(string $query, ?AiHelperRequestDeadline $deadline = null): ?array
    {
        if (! $this->isAvailable() || trim($query) === '') {
            return null;
        }

        $cacheSeconds = max(0, (int) config('ai_helper.query_embedding_cache_seconds', 3600));
        if ($cacheSeconds === 0) {
            return $this->embedTexts([$query], $deadline)[0] ?? null;
        }

        $key = 'ai-helper:query-embedding:'.hash('sha256', $this->indexFingerprint()."\n".trim($query));

        return Cache::remember($key, now()->addSeconds($cacheSeconds), fn () => $this->embedTexts([$query], $deadline)[0] ?? null);
    }

    public function embedEntry(
        AiHelperKnowledgeEntry $entry,
        ?int $ingestionVersion = null,
        ?string $ingestionRunId = null,
    ): bool {
        if (! $this->isAvailable()) {
            return false;
        }

        $entry->refresh()->loadMissing('sourceDocument');
        $targetVersion = $ingestionVersion ?? max(1, (int) $entry->ingestion_version);
        $stagedChunks = $entry->chunks()
            ->where('ingestion_version', $targetVersion)
            ->where('active', false)
            ->orderBy('chunk_index')
            ->get();
        $isStagedIndex = $stagedChunks->isNotEmpty()
            && $entry->status === AiHelperKnowledgeEntry::STATUS_PROCESSING;
        if ($ingestionVersion !== null && (! $isStagedIndex || ! $this->matchesIngestion($entry, $targetVersion, $ingestionRunId))) {
            return false;
        }

        $model = (string) config('ai_helper.embedding_model', 'text-embedding-3-small');
        $dimensions = (int) config('ai_helper.embedding_dimensions', 512);
        $fingerprint = $this->indexFingerprint();
        $chunks = $isStagedIndex
            ? $stagedChunks->values()
            : $entry->chunks()->where('active', true)->orderBy('chunk_index')->get();
        if ($chunks->isEmpty()) {
            return false;
        }
        $routingProfile = $this->routingProfile($entry);
        $routingHash = hash('sha256', $fingerprint."\n".$routingProfile);
        $alreadyCurrent = ! $isStagedIndex && $this->isEntryCurrent($entry);
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
            $texts[] = $routingProfile;
            foreach ($this->batches($texts) as $batch) {
                $vectors = array_merge($vectors, $this->embedTexts($batch));
            }

            if (count($vectors) !== $chunks->count() + 1) {
                throw new RuntimeException('Embedding provider returned an incomplete result set.');
            }
            $entryVector = array_pop($vectors);
            if (! $this->vectorHasExpectedDimensions($entryVector, $dimensions)
                || collect($vectors)->contains(fn ($vector) => ! $this->vectorHasExpectedDimensions($vector, $dimensions))) {
                throw new RuntimeException('Embedding provider returned vectors with unexpected dimensions.');
            }

            foreach ($chunks as $index => $chunk) {
                $source = trim((string) ($chunk->search_text ?: $chunk->content));
                $chunk->forceFill([
                    'embedding' => $vectors[$index],
                    'embedding_model' => $model,
                    'embedding_hash' => hash('sha256', $fingerprint."\n".$source),
                    'embedded_at' => now(),
                ])->save();
            }

            $embeddingAttributes = [
                'embedding' => $entryVector,
                'embedding_model' => $model,
                'embedding_hash' => $routingHash,
                'embedding_status' => 'ready',
                'embedded_at' => now(),
                'embedding_error' => null,
            ];

            if ($isStagedIndex) {
                return $this->promoteStagedVersion(
                    $entry->id,
                    $targetVersion,
                    $ingestionRunId,
                    $embeddingAttributes,
                    $chunks->modelKeys(),
                );
            }

            $entry->forceFill($embeddingAttributes)->save();

            return true;
        } catch (\Throwable $e) {
            $failure = AiHelperKnowledgeEntry::query()->whereKey($entry->id);
            if ($isStagedIndex) {
                $failure->where('status', AiHelperKnowledgeEntry::STATUS_PROCESSING)
                    ->where('ingestion_version', $targetVersion);
                if ($ingestionRunId !== null) {
                    $failure->where('ingestion_run_id', $ingestionRunId);
                }
            }
            $failure->update([
                // A staged queue job may still have framework-level retries.
                // Keep it reconcilable until the job's final failed callback
                // rolls the stage back to the last-known-good version.
                'embedding_status' => $isStagedIndex ? 'processing' : 'failed',
                'embedding_error' => Str::limit($e->getMessage(), 1000, ''),
                'updated_at' => now(),
            ]);
            throw $e;
        }
    }

    public function indexFingerprint(): string
    {
        return implode(':', [
            'v'.max(1, (int) config('ai_helper.index_profile_version', 4)),
            (string) config('ai_helper.embedding_model', 'text-embedding-3-small'),
            max(0, (int) config('ai_helper.embedding_dimensions', 512)),
            (string) config('ai_helper.embedding_routing_profile_version', self::DEFAULT_ROUTING_PROFILE_VERSION),
            (string) config('ai_helper.embedding_chunk_profile_version', self::DEFAULT_CHUNK_PROFILE_VERSION),
        ]);
    }

    public function isEntryCurrent(AiHelperKnowledgeEntry $entry): bool
    {
        $entry->loadMissing(['chunks', 'sourceDocument']);
        $chunks = $entry->chunks->where('active', true)->values();
        if ($chunks->isEmpty()) {
            return false;
        }

        return $this->isEntryVectorCurrent($entry)
            && $chunks->every(fn ($chunk) => $this->isChunkCurrent($chunk));
    }

    public function isEntryVectorCurrent(AiHelperKnowledgeEntry $entry): bool
    {
        $entry->loadMissing('sourceDocument');
        $model = (string) config('ai_helper.embedding_model', 'text-embedding-3-small');
        $dimensions = (int) config('ai_helper.embedding_dimensions', 512);
        $fingerprint = $this->indexFingerprint();
        $routingHash = hash('sha256', $fingerprint."\n".$this->routingProfile($entry));

        return $entry->embedding_status === 'ready'
            && $entry->embedding_model === $model
            && $this->vectorHasExpectedDimensions($entry->embedding, $dimensions)
            && hash_equals((string) $entry->embedding_hash, $routingHash);
    }

    public function isChunkCurrent(AiHelperKnowledgeChunk $chunk): bool
    {
        $model = (string) config('ai_helper.embedding_model', 'text-embedding-3-small');
        $dimensions = (int) config('ai_helper.embedding_dimensions', 512);
        $source = trim((string) ($chunk->search_text ?: $chunk->content));

        return $chunk->embedding_model === $model
            && $this->vectorHasExpectedDimensions($chunk->embedding, $dimensions)
            && hash_equals(
                (string) $chunk->embedding_hash,
                hash('sha256', $this->indexFingerprint()."\n".$source),
            );
    }

    private function vectorHasExpectedDimensions(mixed $vector, int $dimensions): bool
    {
        return is_array($vector)
            && $vector !== []
            && ($dimensions <= 0 || count($vector) === $dimensions);
    }

    private function routingProfile(AiHelperKnowledgeEntry $entry): string
    {
        $metadata = is_array($entry->retrieval_metadata) ? $entry->retrieval_metadata : [];
        $maximumCharacters = max(1000, (int) config('ai_helper.embedding_max_input_characters', 6000));
        $parts = collect([
            'Title: '.trim((string) ($entry->sourceDocument?->title ?: $entry->title)),
            'File: '.trim((string) ($entry->sourceDocument?->source_filename ?: $entry->source_filename)),
            'Summary: '.trim((string) $entry->summary),
            'Topics: '.collect($entry->tags ?? [])->filter()->join(', '),
            'Module: '.trim((string) $entry->module_key),
            'Route: '.trim((string) $entry->route_key),
            'Headings: '.collect($metadata['headings'] ?? [])->filter()->unique()->join(' > '),
        ])->map(fn (string $part) => trim($part))
            ->reject(fn (string $part) => str_ends_with($part, ':'))
            ->join("\n");

        return Str::limit($parts, $maximumCharacters, '');
    }

    /** @return array<int, array<int, float>> */
    protected function embedTexts(array $texts, ?AiHelperRequestDeadline $deadline = null): array
    {
        $texts = array_values(array_map(static fn ($value) => trim((string) $value), $texts));
        if ($texts === [] || collect($texts)->contains(fn (string $value) => $value === '')) {
            throw new RuntimeException('Embedding input cannot be empty.');
        }

        $timeout = max(5, (int) config('ai_helper.embedding_timeout', 30));
        if ($deadline) {
            $timeout = $deadline->timeoutFor($timeout);
            $deadline->claimProviderCall('embedding');
        }
        $client = new Client([
            'base_uri' => rtrim((string) config('ai_helper.base_url'), '/').'/',
            'timeout' => $timeout,
            'connect_timeout' => min($timeout, max(1, (int) config('ai_helper.connect_timeout', 5))),
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

    /**
     * @param  array<string, mixed>  $embeddingAttributes
     * @param  array<int, int|string>  $expectedChunkIds
     */
    private function promoteStagedVersion(
        int $entryId,
        int $ingestionVersion,
        ?string $ingestionRunId,
        array $embeddingAttributes,
        array $expectedChunkIds = [],
    ): bool {
        return DB::transaction(function () use ($entryId, $ingestionVersion, $ingestionRunId, $embeddingAttributes, $expectedChunkIds): bool {
            $entry = AiHelperKnowledgeEntry::query()->lockForUpdate()->find($entryId);
            if (! $entry || ! $this->matchesIngestion($entry, $ingestionVersion, $ingestionRunId)) {
                return false;
            }

            $targetChunks = $entry->chunks()
                ->where('ingestion_version', $ingestionVersion)
                ->where('active', false)
                ->orderBy('chunk_index')
                ->get();
            if ($targetChunks->isEmpty()) {
                return false;
            }

            if ($expectedChunkIds !== []) {
                $actual = $targetChunks->modelKeys();
                sort($actual);
                sort($expectedChunkIds);
                if ($actual !== $expectedChunkIds) {
                    throw new RuntimeException('The staged knowledge index changed while embeddings were being prepared.');
                }
            }

            // The active flags and entry-level routing vector become visible in
            // one commit. Readers therefore see either the complete old index
            // or the complete new index, never a partially embedded mixture.
            $entry->chunks()->where('active', true)->update(['active' => false]);
            $entry->chunks()
                ->where('ingestion_version', $ingestionVersion)
                ->update(['active' => true]);
            $entry->chunks()
                ->where('ingestion_version', '!=', $ingestionVersion)
                ->delete();
            $entry->pages()
                ->where('ingestion_version', '!=', $ingestionVersion)
                ->delete();

            $entry->forceFill([
                'status' => AiHelperKnowledgeEntry::STATUS_ACTIVE,
                'active' => true,
                'processed_at' => now(),
                'ingestion_completed_at' => now(),
                'extraction_complete' => true,
                'error' => null,
            ] + $embeddingAttributes)->save();

            return true;
        });
    }

    private function matchesIngestion(
        AiHelperKnowledgeEntry $entry,
        int $ingestionVersion,
        ?string $ingestionRunId,
    ): bool {
        return $entry->status === AiHelperKnowledgeEntry::STATUS_PROCESSING
            && (int) $entry->ingestion_version === $ingestionVersion
            && ($ingestionRunId === null
                || hash_equals((string) $entry->ingestion_run_id, $ingestionRunId));
    }
}
