<?php

namespace Tests\Unit;

use App\Support\E2eEnvironmentGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class E2eEnvironmentGuardTest extends TestCase
{
    #[DataProvider('safeTargets')]
    public function test_it_accepts_only_exact_non_privileged_test_targets(array $target): void
    {
        E2eEnvironmentGuard::assertSafe(...$target);

        $this->addToAssertionCount(1);
    }

    public static function safeTargets(): array
    {
        return [
            'testing' => [self::safeTarget()],
            'e2e and case normalization' => [[
                'E2E', 'VMECC_TEST', '127.0.0.1', '127.0.0.1', 'VMECC_E2E',
                false, false, false, false, false,
                'vmecc_test', '127.0.0.1', 'vmecc_e2e',
            ]],
            'IPv6 loopback' => [[
                'testing', 'vmecc_test', '::1', '::1', 'vmecc_e2e',
                false, false, false, false, false,
                'vmecc_test', '::1', 'vmecc_e2e',
            ]],
        ];
    }

    #[DataProvider('unsafeTargets')]
    public function test_it_rejects_unsafe_targets(array $target): void
    {
        $this->expectException(RuntimeException::class);

        E2eEnvironmentGuard::assertSafe(...$target);
    }

    public static function unsafeTargets(): array
    {
        return [
            'local environment' => [self::safeTarget(environment: 'local')],
            'production environment' => [self::safeTarget(environment: 'production')],
            'wrong database' => [self::safeTarget(databaseName: 'another_test')],
            'empty database allowlist' => [self::safeTarget(allowedDatabaseName: '')],
            'configured host mismatch' => [self::safeTarget(configuredHost: 'localhost')],
            'resolved host mismatch' => [self::safeTarget(serverAddress: '192.0.2.10')],
            'non-loopback allowlist' => [self::safeTarget(
                configuredHost: '192.0.2.10',
                serverAddress: '192.0.2.10',
                allowedHost: '192.0.2.10',
            )],
            'wrong database role' => [self::safeTarget(databaseUsername: 'postgres')],
            'empty role allowlist' => [self::safeTarget(allowedDatabaseUsername: '')],
            'superuser role' => [self::safeTarget(isSuperuser: true)],
            'createdb role' => [self::safeTarget(canCreateDatabase: true)],
            'createrole role' => [self::safeTarget(canCreateRole: true)],
            'replication role' => [self::safeTarget(canReplicate: true)],
            'bypass RLS role' => [self::safeTarget(canBypassRowLevelSecurity: true)],
        ];
    }

    private static function safeTarget(
        string $environment = 'testing',
        string $databaseName = 'vmecc_test',
        string $configuredHost = '127.0.0.1',
        string $serverAddress = '127.0.0.1',
        string $databaseUsername = 'vmecc_e2e',
        bool $isSuperuser = false,
        bool $canCreateDatabase = false,
        bool $canCreateRole = false,
        bool $canReplicate = false,
        bool $canBypassRowLevelSecurity = false,
        string $allowedDatabaseName = 'vmecc_test',
        string $allowedHost = '127.0.0.1',
        string $allowedDatabaseUsername = 'vmecc_e2e',
    ): array {
        return [
            $environment,
            $databaseName,
            $configuredHost,
            $serverAddress,
            $databaseUsername,
            $isSuperuser,
            $canCreateDatabase,
            $canCreateRole,
            $canReplicate,
            $canBypassRowLevelSecurity,
            $allowedDatabaseName,
            $allowedHost,
            $allowedDatabaseUsername,
        ];
    }
}
