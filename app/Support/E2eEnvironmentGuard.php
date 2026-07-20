<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;

final class E2eEnvironmentGuard
{
    public static function assertCurrentEnvironmentIsSafe(): void
    {
        $connection = DB::connection();
        $identity = $connection->selectOne(<<<'SQL'
            select
                current_database() as database_name,
                current_user as database_username,
                host(inet_server_addr()) as server_address,
                rol.rolsuper as is_superuser,
                rol.rolcreatedb as can_create_database,
                rol.rolcreaterole as can_create_role,
                rol.rolreplication as can_replicate,
                rol.rolbypassrls as can_bypass_row_level_security
            from pg_roles rol
            where rol.rolname = current_user
            SQL);

        if (! $identity) {
            throw new RuntimeException('Unable to resolve the current PostgreSQL role identity.');
        }

        self::assertSafe(
            app()->environment(),
            (string) $identity->database_name,
            (string) $connection->getConfig('host'),
            (string) $identity->server_address,
            (string) $identity->database_username,
            (bool) $identity->is_superuser,
            (bool) $identity->can_create_database,
            (bool) $identity->can_create_role,
            (bool) $identity->can_replicate,
            (bool) $identity->can_bypass_row_level_security,
            (string) config('e2e.database.name'),
            (string) config('e2e.database.host'),
            (string) config('e2e.database.username'),
        );
    }

    public static function assertSafe(
        string $environment,
        string $databaseName,
        string $configuredHost,
        string $serverAddress,
        string $databaseUsername,
        bool $isSuperuser,
        bool $canCreateDatabase,
        bool $canCreateRole,
        bool $canReplicate,
        bool $canBypassRowLevelSecurity,
        string $allowedDatabaseName,
        string $allowedHost,
        string $allowedDatabaseUsername,
    ): void {
        $environment = strtolower(trim($environment));
        $databaseName = strtolower(trim($databaseName));
        $configuredHost = strtolower(trim($configuredHost));
        $serverAddress = strtolower(trim($serverAddress));
        $databaseUsername = strtolower(trim($databaseUsername));
        $allowedDatabaseName = strtolower(trim($allowedDatabaseName));
        $allowedHost = strtolower(trim($allowedHost));
        $allowedDatabaseUsername = strtolower(trim($allowedDatabaseUsername));

        if (! in_array($environment, ['testing', 'e2e'], true)) {
            throw new RuntimeException(
                "E2E data operations require APP_ENV=testing or APP_ENV=e2e; resolved '{$environment}'.",
            );
        }

        if ($allowedDatabaseName === '' || $databaseName !== $allowedDatabaseName) {
            throw new RuntimeException(
                "E2E data operations require the explicitly allowed database; resolved '{$databaseName}'.",
            );
        }

        if ($allowedHost === '' || $configuredHost !== $allowedHost || $serverAddress !== $allowedHost) {
            throw new RuntimeException(
                "E2E data operations require the explicitly allowed database host; configured '{$configuredHost}', resolved '{$serverAddress}'.",
            );
        }

        if (! in_array($allowedHost, ['127.0.0.1', '::1'], true)) {
            throw new RuntimeException('The E2E database host must be an explicit loopback address.');
        }

        if ($allowedDatabaseUsername === '' || $databaseUsername !== $allowedDatabaseUsername) {
            throw new RuntimeException(
                "E2E data operations require the explicitly allowed database role; resolved '{$databaseUsername}'.",
            );
        }

        if ($isSuperuser || $canCreateDatabase || $canCreateRole || $canReplicate || $canBypassRowLevelSecurity) {
            throw new RuntimeException('The E2E database role has unsafe cluster-level privileges.');
        }
    }
}
