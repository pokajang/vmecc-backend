<?php

namespace Tests\Unit;

use App\Services\AiHelperConcurrencyGuard;
use App\Services\AiHelperRequestDeduplicator;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class AiHelperConcurrencyGuardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config([
            'ai_helper.max_concurrent_per_user' => 1,
            'ai_helper.max_concurrent_global' => 1,
            'ai_helper.concurrency_lock_seconds' => 60,
            'ai_helper.request_deduplication_seconds' => 600,
        ]);
    }

    public function test_limits_per_user_and_global_concurrency_and_releases_slots(): void
    {
        $guard = app(AiHelperConcurrencyGuard::class);
        $first = $guard->acquire(10);

        $this->assertNotNull($first);
        $this->assertNull($guard->acquire(10));
        $this->assertNull($guard->acquire(11));

        $first->release();
        $second = $guard->acquire(11);
        $this->assertNotNull($second);
        $second->release();
    }

    public function test_request_uuid_is_reserved_until_explicitly_released(): void
    {
        $deduplicator = app(AiHelperRequestDeduplicator::class);
        $uuid = '7597cfd5-5458-43f0-8428-f0d3d34b14c4';

        $this->assertTrue($deduplicator->reserve(10, $uuid));
        $this->assertFalse($deduplicator->reserve(10, $uuid));
        $deduplicator->complete(10, $uuid);
        $this->assertFalse($deduplicator->reserve(10, $uuid));
        $deduplicator->release(10, $uuid);
        $this->assertTrue($deduplicator->reserve(10, $uuid));
    }
}
