<?php

namespace App\Services;

use Illuminate\Contracts\Cache\Lock;
use Throwable;

final class AiHelperConcurrencyLease
{
    private bool $released = false;

    public function __construct(
        private readonly Lock $userLock,
        private readonly Lock $globalLock,
    ) {}

    public function release(): void
    {
        if ($this->released) {
            return;
        }

        $this->released = true;
        foreach ([$this->globalLock, $this->userLock] as $lock) {
            try {
                $lock->release();
            } catch (Throwable) {
                // Expired locks are harmless; never mask the response outcome.
            }
        }
    }
}
