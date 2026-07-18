<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;

final class E2eEnvironmentGuard
{
    public static function assertCurrentEnvironmentIsSafe(): void
    {
        self::assertSafe(
            app()->environment(),
            (string) DB::connection()->getDatabaseName(),
        );
    }

    public static function assertSafe(string $environment, string $databaseName): void
    {
        $environment = strtolower(trim($environment));
        $databaseName = strtolower(trim($databaseName));

        if (! in_array($environment, ['testing', 'e2e'], true)) {
            throw new RuntimeException(
                "E2E data operations require APP_ENV=testing or APP_ENV=e2e; resolved '{$environment}'.",
            );
        }

        if (preg_match('/(?:^|_)(?:test|e2e)$/', $databaseName) !== 1) {
            throw new RuntimeException(
                "E2E data operations require a database name ending in _test or _e2e; resolved '{$databaseName}'.",
            );
        }
    }
}
