<?php

namespace Tests\Unit;

use App\Models\AiHelperKnowledgeChunk;
use App\Services\AiHelperEmbeddingService;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

class AiHelperEmbeddingServiceTest extends TestCase
{
    public function test_index_fingerprint_includes_model_dimensions_and_profile_versions(): void
    {
        config([
            'ai_helper.index_profile_version' => 7,
            'ai_helper.embedding_model' => 'embedding-test',
            'ai_helper.embedding_dimensions' => 256,
            'ai_helper.embedding_routing_profile_version' => 'routing-v7',
            'ai_helper.embedding_chunk_profile_version' => 'chunk-v9',
        ]);

        $this->assertSame(
            'v7:embedding-test:256:routing-v7:chunk-v9',
            (new AiHelperEmbeddingService)->indexFingerprint(),
        );
    }

    public function test_it_batches_embedding_inputs_by_count_and_conservative_token_budget(): void
    {
        config([
            'ai_helper.embedding_batch_size' => 2,
            'ai_helper.embedding_batch_token_budget' => 1000,
            'ai_helper.embedding_max_input_characters' => 6000,
        ]);
        $method = new ReflectionMethod(AiHelperEmbeddingService::class, 'batches');

        $batches = $method->invoke(new AiHelperEmbeddingService, [
            str_repeat('a', 1500),
            str_repeat('b', 1500),
            str_repeat('c', 20),
        ]);

        $this->assertCount(2, $batches);
        $this->assertCount(1, $batches[0]);
        $this->assertCount(2, $batches[1]);
        $this->assertSame(str_repeat('c', 20), $batches[1][1]);
    }

    public function test_it_rejects_an_oversized_embedding_input_before_calling_the_provider(): void
    {
        config(['ai_helper.embedding_max_input_characters' => 6000]);
        $method = new ReflectionMethod(AiHelperEmbeddingService::class, 'batches');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('exceeds the configured 6000-character limit');

        $method->invoke(new AiHelperEmbeddingService, [str_repeat('x', 6001)]);
    }

    public function test_chunk_compatibility_rejects_legacy_fingerprints_and_wrong_dimensions(): void
    {
        config([
            'ai_helper.index_profile_version' => 4,
            'ai_helper.embedding_model' => 'embedding-test',
            'ai_helper.embedding_dimensions' => 2,
            'ai_helper.embedding_routing_profile_version' => 'routing-v1',
            'ai_helper.embedding_chunk_profile_version' => 'contextual-v2',
        ]);
        $service = new AiHelperEmbeddingService;
        $content = 'Current approved guidance.';
        $chunk = new AiHelperKnowledgeChunk([
            'content' => $content,
            'search_text' => $content,
            'embedding' => [0.25, 0.75],
            'embedding_model' => 'embedding-test',
            'embedding_hash' => hash('sha256', $service->indexFingerprint()."\n".$content),
        ]);

        $this->assertTrue($service->isChunkCurrent($chunk));

        $chunk->embedding_hash = 'legacy-fingerprint';
        $this->assertFalse($service->isChunkCurrent($chunk));

        $chunk->embedding_hash = hash('sha256', $service->indexFingerprint()."\n".$content);
        $chunk->embedding = [0.25];
        $this->assertFalse($service->isChunkCurrent($chunk));
    }
}
