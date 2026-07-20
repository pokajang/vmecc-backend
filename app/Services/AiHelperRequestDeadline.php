<?php

namespace App\Services;

final class AiHelperRequestDeadline
{
    private int $providerCalls = 0;

    private function __construct(
        private readonly float $startedAt,
        private readonly float $deadlineAt,
    ) {}

    public static function fromSeconds(int|float $seconds): self
    {
        $startedAt = microtime(true);
        $seconds = max(1.0, (float) $seconds);

        return new self($startedAt, $startedAt + $seconds);
    }

    public static function fromConfig(): self
    {
        return self::fromSeconds(max(1, (int) config('ai_helper.request_deadline_seconds', 50)));
    }

    public function elapsedMilliseconds(): int
    {
        return max(0, (int) ((microtime(true) - $this->startedAt) * 1000));
    }

    public function remainingSeconds(): float
    {
        return max(0.0, $this->deadlineAt - microtime(true));
    }

    public function hasTimeFor(float $seconds): bool
    {
        return $this->remainingSeconds() > max(0.0, $seconds);
    }

    public function claimProviderCall(string $stage): void
    {
        $maximumCalls = max(1, (int) config('ai_helper.max_provider_calls_per_request', 8));
        if ($this->providerCalls >= $maximumCalls) {
            throw new AiHelperProviderException(
                'AI_HELPER_PROVIDER_CALL_BUDGET_EXCEEDED',
                'AI helper provider call budget was exceeded.',
                false,
                stage: $stage,
            );
        }

        $this->providerCalls++;
    }

    public function providerCalls(): int
    {
        return $this->providerCalls;
    }

    public function timeoutFor(int|float $requestedSeconds, float $minimumSeconds = 0.25): float
    {
        $remaining = $this->remainingSeconds();
        if ($remaining <= $minimumSeconds) {
            throw new AiHelperProviderException(
                'AI_HELPER_DEADLINE_EXCEEDED',
                'AI helper response deadline was exceeded.',
                false,
                stage: 'deadline',
            );
        }

        return max($minimumSeconds, min((float) $requestedSeconds, $remaining));
    }
}
