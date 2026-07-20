<?php

namespace App\Services;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;

final class AiHelperConcurrencyGuard
{
    public function acquire(int $userId): ?AiHelperConcurrencyLease
    {
        $seconds = max(
            30,
            (int) config('ai_helper.concurrency_lock_seconds', 90),
            (int) config('ai_helper.request_deadline_seconds', 50) + 30,
        );
        $userLock = $this->acquireSlot(
            'ai-helper:generation:user:'.$userId,
            max(1, (int) config('ai_helper.max_concurrent_per_user', 1)),
            $seconds,
        );
        if (! $userLock) {
            return null;
        }

        try {
            $globalLock = $this->acquireSlot(
                'ai-helper:generation:global',
                max(1, (int) config('ai_helper.max_concurrent_global', 3)),
                $seconds,
            );
        } catch (\Throwable $e) {
            $userLock->release();
            throw $e;
        }
        if (! $globalLock) {
            $userLock->release();

            return null;
        }

        return new AiHelperConcurrencyLease($userLock, $globalLock);
    }

    private function acquireSlot(string $prefix, int $slots, int $seconds): ?Lock
    {
        for ($slot = 0; $slot < $slots; $slot++) {
            $lock = Cache::lock($prefix.':'.$slot, $seconds);
            if ($lock->get()) {
                return $lock;
            }
        }

        return null;
    }
}
