<?php

namespace Tests\Unit;

use App\Support\E2eEnvironmentGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class E2eEnvironmentGuardTest extends TestCase
{
    #[DataProvider('safeTargets')]
    public function test_it_accepts_only_explicit_test_targets(string $environment, string $database): void
    {
        E2eEnvironmentGuard::assertSafe($environment, $database);

        $this->addToAssertionCount(1);
    }

    public static function safeTargets(): array
    {
        return [
            ['testing', 'vmecc_test'],
            ['e2e', 'vmecc_e2e'],
            ['TESTING', 'project_test'],
        ];
    }

    #[DataProvider('unsafeTargets')]
    public function test_it_rejects_unsafe_targets(string $environment, string $database): void
    {
        $this->expectException(RuntimeException::class);

        E2eEnvironmentGuard::assertSafe($environment, $database);
    }

    public static function unsafeTargets(): array
    {
        return [
            ['local', 'vmecc_test'],
            ['production', 'vmecc_e2e'],
            ['testing', 'vmecc'],
            ['e2e', 'production'],
            ['', 'vmecc_test'],
        ];
    }
}
