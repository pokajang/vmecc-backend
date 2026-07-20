<?php

namespace App\Services;

use App\Models\AiHelperDocument;
use App\Models\AiHelperKnowledgeEntry;
use InvalidArgumentException;

class AiHelperStorageCapacityService
{
    public const UPLOAD_DOCUMENTS = 'documents';

    public const UPLOAD_KNOWLEDGE = 'knowledge';

    /**
     * @param  array{minimum_free_percent?: float|int, minimum_free_bytes?: int, maximum_upload_percent?: float|int}  $thresholdOverrides
     * @return array<string, mixed>
     */
    public function status(
        array $thresholdOverrides = [],
        ?string $incomingType = null,
        int $incomingBytes = 0,
    ): array {
        return $this->assess(
            $this->filesystemSnapshot(),
            $this->uploadUsageSnapshot(),
            $this->thresholds($thresholdOverrides),
            $incomingType,
            max(0, $incomingBytes),
        );
    }

    /** @return array{ok: bool, code?: string, reason?: string, status: array<string, mixed>} */
    public function checkUpload(string $incomingType, int $incomingBytes): array
    {
        $status = $this->status([], $incomingType, $incomingBytes);

        return array_filter([
            'ok' => (bool) $status['ready'],
            'code' => $status['ready'] ? null : (string) ($status['error'] ?? 'AI_HELPER_STORAGE_CAPACITY_UNAVAILABLE'),
            'reason' => $status['ready'] ? null : (string) ($status['failure_reason'] ?? 'unavailable'),
            'status' => $status,
        ], static fn ($value) => $value !== null);
    }

    /**
     * Deterministically assess a capacity snapshot. Public so operational tests and
     * diagnostics can validate the exact same boundary used by upload admission.
     *
     * @param  array{available: bool, free_bytes?: int, total_bytes?: int, error?: string}  $filesystem
     * @param  array<string, array{used_bytes: int, limit_bytes: int}>  $uploads
     * @param  array{minimum_free_percent: float, minimum_free_bytes: int, maximum_upload_percent: float}  $thresholds
     * @return array<string, mixed>
     */
    public function assess(
        array $filesystem,
        array $uploads,
        array $thresholds,
        ?string $incomingType = null,
        int $incomingBytes = 0,
    ): array {
        if ($incomingType !== null && ! in_array($incomingType, [self::UPLOAD_DOCUMENTS, self::UPLOAD_KNOWLEDGE], true)) {
            throw new InvalidArgumentException("Unsupported Ask AI upload type: {$incomingType}");
        }

        $incomingBytes = max(0, $incomingBytes);
        $uploadPayload = [
            'maximum_used_percent' => $thresholds['maximum_upload_percent'],
        ];
        foreach ([self::UPLOAD_DOCUMENTS, self::UPLOAD_KNOWLEDGE] as $type) {
            $usedBytes = max(0, (int) ($uploads[$type]['used_bytes'] ?? 0));
            $limitBytes = max(0, (int) ($uploads[$type]['limit_bytes'] ?? 0));
            $projectedUsedBytes = $usedBytes + ($incomingType === $type ? $incomingBytes : 0);
            $usedPercent = $limitBytes > 0 ? round(($usedBytes / $limitBytes) * 100, 2) : null;
            $projectedUsedPercent = $limitBytes > 0
                ? round(($projectedUsedBytes / $limitBytes) * 100, 2)
                : null;
            $uploadPayload[$type] = [
                'used_bytes' => $usedBytes,
                'projected_used_bytes' => $projectedUsedBytes,
                'limit_bytes' => $limitBytes > 0 ? $limitBytes : null,
                'used_percent' => $usedPercent,
                'projected_used_percent' => $projectedUsedPercent,
                'ready' => $projectedUsedPercent === null
                    || $projectedUsedPercent < $thresholds['maximum_upload_percent'],
            ];
        }

        if (! ($filesystem['available'] ?? false)) {
            return [
                'ready' => false,
                'error' => (string) ($filesystem['error'] ?? 'AI_HELPER_STORAGE_CAPACITY_UNAVAILABLE'),
                'failure_reason' => 'filesystem_unavailable',
                'uploads' => $uploadPayload,
            ];
        }

        $totalBytes = max(0, (int) ($filesystem['total_bytes'] ?? 0));
        $freeBytes = max(0, (int) ($filesystem['free_bytes'] ?? 0));
        if ($totalBytes === 0) {
            return [
                'ready' => false,
                'error' => 'AI_HELPER_STORAGE_CAPACITY_UNAVAILABLE',
                'failure_reason' => 'filesystem_unavailable',
                'uploads' => $uploadPayload,
            ];
        }

        $projectedFreeBytes = max(0, $freeBytes - $incomingBytes);
        $freePercent = round(($freeBytes / $totalBytes) * 100, 2);
        $projectedFreePercent = round(($projectedFreeBytes / $totalBytes) * 100, 2);
        $filesystemReady = $incomingBytes <= $freeBytes
            && $projectedFreeBytes >= $thresholds['minimum_free_bytes']
            && $projectedFreePercent >= $thresholds['minimum_free_percent'];
        $relevantTypes = $incomingType === null
            ? [self::UPLOAD_DOCUMENTS, self::UPLOAD_KNOWLEDGE]
            : [$incomingType];
        $failedUploadType = collect($relevantTypes)->first(
            fn (string $type) => ! $uploadPayload[$type]['ready'],
        );
        $ready = $filesystemReady && $failedUploadType === null;
        $error = match (true) {
            ! $filesystemReady => 'AI_HELPER_STORAGE_HEADROOM_LIMIT',
            $failedUploadType === self::UPLOAD_DOCUMENTS => 'AI_HELPER_DOCUMENT_GLOBAL_STORAGE_LIMIT',
            $failedUploadType === self::UPLOAD_KNOWLEDGE => 'AI_HELPER_KNOWLEDGE_GLOBAL_STORAGE_LIMIT',
            default => null,
        };

        return array_filter([
            'ready' => $ready,
            'error' => $error,
            'failure_reason' => ! $filesystemReady
                ? 'filesystem_headroom'
                : ($failedUploadType === null ? null : 'upload_quota_headroom'),
            'filesystem' => [
                'free_bytes' => $freeBytes,
                'projected_free_bytes' => $projectedFreeBytes,
                'total_bytes' => $totalBytes,
                'free_percent' => $freePercent,
                'projected_free_percent' => $projectedFreePercent,
                'minimum_free_bytes' => $thresholds['minimum_free_bytes'],
                'minimum_free_percent' => $thresholds['minimum_free_percent'],
                'ready' => $filesystemReady,
            ],
            'uploads' => $uploadPayload,
        ], static fn ($value) => $value !== null);
    }

    /** @return array{available: bool, free_bytes?: int, total_bytes?: int, error?: string} */
    private function filesystemSnapshot(): array
    {
        $root = (string) config('filesystems.disks.local.root', storage_path('app'));
        $root = realpath($root) ?: $root;
        if (! is_dir($root)) {
            return [
                'available' => false,
                'error' => 'AI_HELPER_STORAGE_PATH_UNAVAILABLE',
            ];
        }
        if (! function_exists('disk_free_space') || ! function_exists('disk_total_space')) {
            return [
                'available' => false,
                'error' => 'AI_HELPER_STORAGE_CAPACITY_UNAVAILABLE',
            ];
        }

        $freeBytes = disk_free_space($root);
        $totalBytes = disk_total_space($root);
        if ($freeBytes === false || $totalBytes === false || $totalBytes <= 0) {
            return [
                'available' => false,
                'error' => 'AI_HELPER_STORAGE_CAPACITY_UNAVAILABLE',
            ];
        }

        return [
            'available' => true,
            'free_bytes' => (int) $freeBytes,
            'total_bytes' => (int) $totalBytes,
        ];
    }

    /** @return array<string, array{used_bytes: int, limit_bytes: int}> */
    private function uploadUsageSnapshot(): array
    {
        return [
            self::UPLOAD_DOCUMENTS => [
                'used_bytes' => (int) AiHelperDocument::withTrashed()->sum('source_size'),
                'limit_bytes' => (int) config('ai_helper.document_max_total_upload_bytes', 0),
            ],
            self::UPLOAD_KNOWLEDGE => [
                'used_bytes' => (int) AiHelperKnowledgeEntry::withTrashed()->sum('source_size'),
                'limit_bytes' => (int) config('ai_helper.knowledge_max_total_upload_bytes', 0),
            ],
        ];
    }

    /**
     * @param  array{minimum_free_percent?: float|int, minimum_free_bytes?: int, maximum_upload_percent?: float|int}  $overrides
     * @return array{minimum_free_percent: float, minimum_free_bytes: int, maximum_upload_percent: float}
     */
    private function thresholds(array $overrides): array
    {
        return [
            'minimum_free_percent' => max(0, min(100, (float) ($overrides['minimum_free_percent']
                ?? config('ai_helper.storage_minimum_free_percent', 20)))),
            'minimum_free_bytes' => max(0, (int) ($overrides['minimum_free_bytes']
                ?? ((int) config('ai_helper.storage_minimum_free_mb', 1024) * 1024 * 1024))),
            'maximum_upload_percent' => max(1, min(100, (float) ($overrides['maximum_upload_percent']
                ?? config('ai_helper.storage_maximum_upload_percent', 85)))),
        ];
    }
}
