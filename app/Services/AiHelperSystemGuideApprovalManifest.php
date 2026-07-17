<?php

namespace App\Services;

use App\Models\AiHelperKnowledgeEntry;
use Carbon\CarbonImmutable;
use JsonException;
use RuntimeException;

class AiHelperSystemGuideApprovalManifest
{
    /** @var array<string, array<string, mixed>>|null */
    private ?array $records = null;

    public function path(): string
    {
        return database_path('ai-helper-system-guides/approvals.json');
    }

    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        if ($this->records !== null) {
            return $this->records;
        }

        $path = $this->path();
        $raw = is_file($path) ? file_get_contents($path) : false;
        if ($raw === false) {
            throw new RuntimeException("System-guide approval manifest is missing: {$path}");
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('System-guide approval manifest is invalid JSON.', previous: $exception);
        }
        if (! is_array($decoded) || ! array_is_list($decoded)) {
            throw new RuntimeException('System-guide approval manifest must be a JSON array.');
        }

        $records = [];
        foreach ($decoded as $index => $record) {
            if (! is_array($record)) {
                throw new RuntimeException("System-guide approval record {$index} must be an object.");
            }
            $key = $this->requiredString($record, 'key', $index);
            if (isset($records[$key])) {
                throw new RuntimeException("Duplicate system-guide approval key: {$key}");
            }
            $version = $record['version'] ?? null;
            if (! is_int($version) || $version < 1) {
                throw new RuntimeException("System-guide approval {$key} has an invalid version.");
            }
            $hash = $this->requiredString($record, 'content_sha256', $index);
            if (preg_match('/^[a-f0-9]{64}$/', $hash) !== 1) {
                throw new RuntimeException("System-guide approval {$key} has an invalid content hash.");
            }
            $approvedOn = CarbonImmutable::parse($this->requiredString($record, 'approved_on', $index))->startOfDay();
            if ($approvedOn->isFuture()) {
                throw new RuntimeException("System-guide approval {$key} has a future approval date.");
            }

            $records[$key] = [
                'key' => $key,
                'version' => $version,
                'content_sha256' => $hash,
                'owner' => $this->requiredString($record, 'owner', $index),
                'approval_reference' => $this->requiredString($record, 'approval_reference', $index),
                'approved_by' => $this->requiredString($record, 'approved_by', $index),
                'approved_on' => $approvedOn,
            ];
        }

        return $this->records = $records;
    }

    public function validateApprovedGuide(array $metadata, string $content, string $source): array
    {
        $record = $this->all()[$metadata['key']] ?? null;
        if ($record === null) {
            throw new RuntimeException("Approved system guide has no approval manifest record in {$source}.");
        }
        if ($record['version'] !== $metadata['version']
            || $record['owner'] !== $metadata['owner']
            || ! hash_equals($record['content_sha256'], $this->contentHash($content))) {
            throw new RuntimeException("System-guide approval does not match the final content in {$source}.");
        }

        return $record;
    }

    public function matchesEntry(AiHelperKnowledgeEntry $entry): bool
    {
        $prefix = 'seed:system-guide:';
        if (! str_starts_with((string) $entry->source_path, $prefix)) {
            return false;
        }
        $key = substr((string) $entry->source_path, strlen($prefix));
        $record = $this->all()[$key] ?? null;

        return $record !== null
            && $record['version'] === (int) $entry->version
            && $record['owner'] === $entry->guide_owner
            && is_string($entry->content_hash)
            && hash_equals($record['content_sha256'], $entry->content_hash);
    }

    /** @return array<int, string> */
    public function registryErrors(array $catalogKeys): array
    {
        try {
            $records = $this->all();
        } catch (RuntimeException $exception) {
            return [$exception->getMessage()];
        }

        return collect(array_keys($records))
            ->reject(fn (string $key) => in_array($key, $catalogKeys, true))
            ->map(fn (string $key) => "Approval manifest contains unknown guide {$key}")
            ->values()
            ->all();
    }

    public function contentHash(string $content): string
    {
        $content = str_replace(["\r\n", "\r", "\0"], ["\n", "\n", ''], $content);
        $lines = array_map(static fn (string $line) => rtrim($line), explode("\n", $content));

        return hash('sha256', trim(implode("\n", $lines)));
    }

    private function requiredString(array $record, string $key, int $index): string
    {
        $value = $record[$key] ?? null;
        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException("System-guide approval record {$index} is missing {$key}.");
        }

        return trim($value);
    }
}
