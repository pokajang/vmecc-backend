<?php

namespace Tests\Unit;

use App\Services\AiHelperProviderCircuitBreaker;
use App\Services\AiHelperProviderException;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Tests\TestCase;

class AiHelperProviderCircuitBreakerTest extends TestCase
{
    public function test_it_opens_after_the_configured_number_of_retryable_failures_and_resets_on_success(): void
    {
        config([
            'ai_helper.provider_circuit_failure_threshold' => 2,
            'ai_helper.provider_circuit_cooldown_seconds' => 30,
        ]);
        $breaker = new AiHelperProviderCircuitBreaker(new Repository(new ArrayStore));

        $breaker->recordFailure(true);
        $breaker->assertAvailable('generation');
        $breaker->recordFailure(true);

        try {
            $breaker->assertAvailable('generation');
            $this->fail('Expected the provider circuit to be open.');
        } catch (AiHelperProviderException $exception) {
            $this->assertSame('AI_HELPER_PROVIDER_CIRCUIT_OPEN', $exception->failureCode);
        }

        $breaker->recordSuccess();
        $breaker->assertAvailable('generation');
        $this->addToAssertionCount(1);
    }

    public function test_non_retryable_failures_do_not_open_the_circuit(): void
    {
        config(['ai_helper.provider_circuit_failure_threshold' => 1]);
        $breaker = new AiHelperProviderCircuitBreaker(new Repository(new ArrayStore));

        $breaker->recordFailure(false);
        $breaker->assertAvailable('structured_response');

        $this->addToAssertionCount(1);
    }
}
