<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class HolidayGuidanceTelemetry
{
    public function recordMismatch(array $context): void
    {
        $this->incrementCounter('mismatch', $context);
        Log::info('holiday_guidance.mismatch', $context);
    }

    public function recordMissingStateFallback(array $context): void
    {
        $this->incrementCounter('missing_state_fallback', $context);
        Log::warning('holiday_guidance.missing_state_fallback', $context);
    }

    public function recordLookupFailure(array $context): void
    {
        $this->incrementCounter('lookup_failure', $context);
        Log::error('holiday_guidance.lookup_failure', $context);
    }

    private function incrementCounter(string $event, array $context = []): void
    {
        $date = now()->toDateString();
        $module = trim((string) ($context['module'] ?? 'unknown')) ?: 'unknown';
        $counterKey = "holiday_guidance:{$event}:{$module}:{$date}";
        Cache::increment($counterKey);
        Cache::put($counterKey, (int) Cache::get($counterKey, 0), now()->addDays(30));
    }
}

