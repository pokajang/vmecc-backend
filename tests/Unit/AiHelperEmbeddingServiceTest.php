<?php

namespace Tests\Unit;

use App\Services\AiHelperEmbeddingService;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

class AiHelperEmbeddingServiceTest extends TestCase
{
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
}
