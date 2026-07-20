<?php

namespace App\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;

final class AiHelperProviderCircuitBreaker
{
    private const CACHE_KEY = 'ai-helper:provider-circuit';

    public function __construct(private readonly CacheRepository $cache) {}

    public function assertAvailable(string $stage): void
    {
        $state = $this->state();
        $openUntil = (int) ($state['open_until'] ?? 0);
        if ($openUntil <= time()) {
            if ($openUntil > 0) {
                $this->cache->forget(self::CACHE_KEY);
            }

            return;
        }

        throw new AiHelperProviderException(
            'AI_HELPER_PROVIDER_CIRCUIT_OPEN',
            'AI helper provider is temporarily unavailable.',
            true,
            stage: $stage,
        );
    }

    public function recordSuccess(): void
    {
        $this->cache->forget(self::CACHE_KEY);
    }

    public function recordFailure(bool $retryable): void
    {
        if (! $retryable) {
            return;
        }

        $threshold = max(1, (int) config('ai_helper.provider_circuit_failure_threshold', 3));
        $cooldown = max(1, (int) config('ai_helper.provider_circuit_cooldown_seconds', 30));
        $state = $this->state();
        $failures = (int) ($state['failures'] ?? 0) + 1;
        $openUntil = $failures >= $threshold ? time() + $cooldown : 0;

        $this->cache->put(self::CACHE_KEY, [
            'failures' => $failures,
            'open_until' => $openUntil,
        ], $cooldown);
    }

    /** @return array{failures?: int, open_until?: int} */
    private function state(): array
    {
        $state = $this->cache->get(self::CACHE_KEY, []);

        return is_array($state) ? $state : [];
    }
}
