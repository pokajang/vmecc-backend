<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

final class AiHelperRequestDeduplicator
{
    public function reserve(int $userId, ?string $requestUuid): bool
    {
        if (! $requestUuid) {
            return true;
        }

        return Cache::add(
            $this->key($userId, $requestUuid),
            'processing',
            now()->addSeconds(max(60, (int) config('ai_helper.request_deduplication_seconds', 600))),
        );
    }

    public function complete(int $userId, ?string $requestUuid): void
    {
        if (! $requestUuid) {
            return;
        }

        try {
            Cache::put(
                $this->key($userId, $requestUuid),
                'completed',
                now()->addSeconds(max(60, (int) config('ai_helper.request_deduplication_seconds', 600))),
            );
        } catch (Throwable $e) {
            Log::warning('Ask AI request completion marker could not be stored.', [
                'user_id' => $userId,
                'exception_class' => $e::class,
            ]);
        }
    }

    public function release(int $userId, ?string $requestUuid): void
    {
        if (! $requestUuid) {
            return;
        }

        try {
            Cache::forget($this->key($userId, $requestUuid));
        } catch (Throwable $e) {
            Log::warning('Ask AI request reservation could not be released.', [
                'user_id' => $userId,
                'exception_class' => $e::class,
            ]);
        }
    }

    private function key(int $userId, string $requestUuid): string
    {
        return 'ai-helper:request:'.$userId.':'.hash('sha256', $requestUuid);
    }
}
