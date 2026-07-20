<?php

namespace App\Support;

use RuntimeException;

final class E2ePreflight
{
    /** @return array<string, string|int|bool> */
    public function assertReady(): array
    {
        E2eEnvironmentGuard::assertCurrentEnvironmentIsSafe();
        E2eRunLock::fromConfig()->assertOwned();

        $runId = (string) config('e2e.run_id');
        $runRoot = $this->canonicalDirectory((string) config('e2e.run_root'), 'run root');
        if (basename($runRoot) !== $runId) {
            throw new RuntimeException('The E2E run root must end with the current run ID.');
        }

        $applicationUrl = $this->assertLoopbackUrl((string) config('app.url'), 'application URL');
        $frontendUrl = $this->assertLoopbackUrl(
            (string) config('app.frontend_url'),
            'frontend URL',
        );

        $allowedOrigins = array_values(array_filter(config('cors.allowed_origins', [])));
        if ($allowedOrigins !== [$frontendUrl]) {
            throw new RuntimeException('CORS must allow only the canonical E2E frontend origin.');
        }

        $statefulDomains = array_values(array_filter(config('sanctum.stateful', [])));
        $frontendAuthority = parse_url($frontendUrl, PHP_URL_HOST).':'.parse_url(
            $frontendUrl,
            PHP_URL_PORT,
        );
        if ($statefulDomains !== [$frontendAuthority]) {
            throw new RuntimeException('Sanctum must trust only the canonical E2E frontend authority.');
        }

        if (config('mail.default') !== 'array') {
            throw new RuntimeException('E2E mail transport must be array.');
        }
        if (config('broadcasting.default') !== 'log') {
            throw new RuntimeException('E2E broadcasting must use the log driver.');
        }
        if ((bool) config('ai_helper.enabled') || trim((string) config('ai_helper.api_key')) !== '') {
            throw new RuntimeException('E2E AI access must be disabled and have no provider key.');
        }
        if ((bool) config('mail.workflow_notifications.enabled')
            || (bool) config('mail.message_digest.enabled')) {
            throw new RuntimeException('E2E email feature delivery switches must be disabled.');
        }
        if (trim((string) config('filesystems.disks.s3.key')) !== ''
            || trim((string) config('filesystems.disks.s3.secret')) !== '') {
            throw new RuntimeException('E2E processes must not inherit cloud-storage credentials.');
        }
        if (trim((string) config('logging.channels.slack.url')) !== '') {
            throw new RuntimeException('E2E processes must not inherit a Slack logging webhook.');
        }
        if ((bool) config('app.debug')) {
            throw new RuntimeException('Release-qualification E2E preflight requires APP_DEBUG=false.');
        }
        if (config('filesystems.default') !== 'local'
            || config('filesystems.public_uploads_disk') !== 'public') {
            throw new RuntimeException('E2E storage must use the isolated local and public disks.');
        }

        $isolatedDirectories = [
            'local storage' => (string) config('filesystems.disks.local.root'),
            'public storage' => (string) config('filesystems.disks.public.root'),
            'session storage' => (string) config('session.files'),
            'cache storage' => (string) config('cache.stores.file.path'),
            'download storage' => (string) config('e2e.download_root'),
            'log storage' => dirname((string) config('logging.channels.single.path')),
        ];
        $resolvedDirectories = [];
        foreach ($isolatedDirectories as $label => $path) {
            $resolved = $this->canonicalDirectory($path, $label);
            $this->assertWithin($resolved, $runRoot, $label);
            $resolvedDirectories[$label] = $resolved;
        }
        if (count(array_unique(array_values($resolvedDirectories))) !== count($resolvedDirectories)) {
            throw new RuntimeException('Each mutable E2E artifact category must use a distinct directory.');
        }

        $minimumFreeBytes = max(1, (int) config('e2e.minimum_free_bytes'));
        $freeBytes = disk_free_space($runRoot);
        if ($freeBytes === false || $freeBytes < $minimumFreeBytes) {
            throw new RuntimeException('The E2E run root does not have the required free disk space.');
        }

        return [
            'run_id' => $runId,
            'database' => (string) config('e2e.database.name'),
            'database_host' => (string) config('e2e.database.host'),
            'database_role' => (string) config('e2e.database.username'),
            'application_url' => $applicationUrl,
            'frontend_url' => $frontendUrl,
            'run_root' => $runRoot,
            'mail' => (string) config('mail.default'),
            'queue' => (string) config('queue.default'),
            'debug' => (bool) config('app.debug'),
            'free_bytes' => (int) $freeBytes,
        ];
    }

    private function assertLoopbackUrl(string $url, string $label): string
    {
        $parts = parse_url($url);
        if (! is_array($parts)
            || ($parts['scheme'] ?? null) !== 'http'
            || ($parts['host'] ?? null) !== '127.0.0.1'
            || isset($parts['user'])
            || isset($parts['pass'])
            || ! isset($parts['port'])) {
            throw new RuntimeException("The E2E {$label} must be an explicit 127.0.0.1 HTTP origin with a port.");
        }

        return rtrim($url, '/');
    }

    private function canonicalDirectory(string $path, string $label): string
    {
        $resolved = $path === '' ? false : realpath($path);
        if ($resolved === false || ! is_dir($resolved)) {
            throw new RuntimeException("The E2E {$label} must be an existing canonical directory.");
        }

        return str_replace('\\', '/', $resolved);
    }

    private function assertWithin(string $path, string $root, string $label): void
    {
        $normalizedPath = strtolower(rtrim($path, '/'));
        $normalizedRoot = strtolower(rtrim($root, '/'));
        if ($normalizedPath === $normalizedRoot
            || ! str_starts_with($normalizedPath, $normalizedRoot.'/')) {
            throw new RuntimeException("The E2E {$label} escapes the run root.");
        }
    }
}
