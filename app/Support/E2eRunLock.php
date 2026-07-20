<?php

namespace App\Support;

use RuntimeException;

final class E2eRunLock
{
    private const RUN_ID_PATTERN = '/^VMECC-QA-\d{8}-\d{6}-[a-z0-9]{6}$/';

    public function __construct(
        private readonly string $lockRoot,
        private readonly string $runId,
        private readonly string $databaseName,
        private readonly string $runRoot,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            (string) config('e2e.lock_root'),
            (string) config('e2e.run_id'),
            (string) config('e2e.database.name'),
            (string) config('e2e.run_root'),
        );
    }

    /** @return resource */
    public function acquire()
    {
        $this->assertConfiguration();
        $this->assertDirectory($this->lockRoot, 'lock root');
        $this->assertDirectory($this->runRoot, 'run root');

        if (is_file($this->lockPath())) {
            throw new RuntimeException(
                'The E2E database is already locked or has unreconciled stale lock metadata.',
            );
        }

        $handle = @fopen($this->lockPath(), 'x+b');
        if ($handle === false) {
            throw new RuntimeException(
                'The E2E database is already locked or has unreconciled stale lock metadata.',
            );
        }

        try {
            $this->writeMetadata($handle);
        } catch (\Throwable $exception) {
            fclose($handle);
            @unlink($this->lockPath());
            throw $exception;
        }

        return $handle;
    }

    /** @param resource $handle */
    public function heartbeat($handle): void
    {
        $this->writeMetadata($handle);
    }

    /** @param resource $handle */
    public function release($handle): void
    {
        $metadata = $this->metadata();
        if (($metadata['run_id'] ?? null) !== $this->runId) {
            throw new RuntimeException('Refusing to release a lock owned by another E2E run.');
        }

        fclose($handle);
        if (! @unlink($this->lockPath()) && is_file($this->lockPath())) {
            throw new RuntimeException('Unable to remove the released E2E lock file.');
        }
        if (is_file($this->stopPath())) {
            @unlink($this->stopPath());
        }
    }

    /** @return array<string, mixed> */
    public function assertOwned(int $maximumHeartbeatAgeSeconds = 15): array
    {
        $this->assertConfiguration();
        $metadata = $this->metadata();

        if (($metadata['run_id'] ?? null) !== $this->runId) {
            throw new RuntimeException('The active E2E lock belongs to another run.');
        }
        if (($metadata['database'] ?? null) !== $this->databaseName) {
            throw new RuntimeException('The active E2E lock targets another database.');
        }
        if (($metadata['run_root'] ?? null) !== $this->canonicalPath($this->runRoot)) {
            throw new RuntimeException('The active E2E lock targets another run root.');
        }

        $heartbeat = (int) ($metadata['heartbeat_unix'] ?? 0);
        if ($heartbeat <= 0 || time() - $heartbeat > $maximumHeartbeatAgeSeconds) {
            throw new RuntimeException('The E2E lock heartbeat is stale and requires reconciliation.');
        }

        return $metadata;
    }

    public function requestStop(): void
    {
        $this->assertOwned();
        if (file_put_contents($this->stopPath(), $this->runId, LOCK_EX) === false) {
            throw new RuntimeException('Unable to request E2E lock shutdown.');
        }
    }

    public function stopRequested(): bool
    {
        if (! is_file($this->stopPath())) {
            return false;
        }

        return trim((string) file_get_contents($this->stopPath())) === $this->runId;
    }

    public function isReleased(): bool
    {
        return ! is_file($this->lockPath());
    }

    private function assertConfiguration(): void
    {
        if (preg_match(self::RUN_ID_PATTERN, $this->runId) !== 1) {
            throw new RuntimeException('E2E_RUN_ID is missing or invalid.');
        }
        if ($this->databaseName === '') {
            throw new RuntimeException('The E2E database allowlist is missing.');
        }
    }

    private function assertDirectory(string $path, string $label): void
    {
        if ($path === '' || ! is_dir($path) || realpath($path) === false) {
            throw new RuntimeException("The E2E {$label} must be an existing directory.");
        }
    }

    private function lockPath(): string
    {
        return rtrim($this->lockRoot, '\\/').DIRECTORY_SEPARATOR.$this->databaseName.'.lock.json';
    }

    private function stopPath(): string
    {
        return rtrim($this->lockRoot, '\\/').DIRECTORY_SEPARATOR.$this->databaseName.'.stop';
    }

    /** @return array<string, mixed> */
    private function metadata(): array
    {
        if (! is_file($this->lockPath())) {
            throw new RuntimeException('No active E2E lock was found.');
        }

        $handle = @fopen($this->lockPath(), 'rb');
        if ($handle === false || ! flock($handle, LOCK_SH)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new RuntimeException('Unable to read the E2E lock metadata safely.');
        }

        try {
            $contents = stream_get_contents($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        $decoded = json_decode((string) $contents, true);
        if (! is_array($decoded)) {
            throw new RuntimeException('The E2E lock metadata is invalid.');
        }

        return $decoded;
    }

    /** @param resource $handle */
    private function writeMetadata($handle): void
    {
        $metadata = [
            'run_id' => $this->runId,
            'database' => $this->databaseName,
            'run_root' => $this->canonicalPath($this->runRoot),
            'owner_pid' => getmypid(),
            'heartbeat_at' => gmdate(DATE_ATOM),
            'heartbeat_unix' => time(),
        ];

        if (! flock($handle, LOCK_EX)) {
            throw new RuntimeException('Unable to lock the E2E metadata for writing.');
        }

        try {
            rewind($handle);
            if (! ftruncate($handle, 0)
                || fwrite($handle, json_encode($metadata, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)) === false
                || ! fflush($handle)) {
                throw new RuntimeException('Unable to write E2E lock metadata.');
            }
        } finally {
            flock($handle, LOCK_UN);
        }
    }

    private function canonicalPath(string $path): string
    {
        $resolved = realpath($path);
        if ($resolved === false) {
            throw new RuntimeException('Unable to canonicalize an E2E path.');
        }

        return str_replace('\\', '/', $resolved);
    }
}
