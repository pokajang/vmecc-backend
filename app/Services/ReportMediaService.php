<?php

namespace App\Services;

use App\Models\ReportMedia;
use App\Models\ReportMediaLink;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ReportMediaService
{
    private const MAX_COUNT = 10;

    private const MAX_TOTAL_BYTES = 12 * 1024 * 1024;

    private const MAX_DRAFT_REPORTS = 10;

    public function __construct(
        private readonly ReportMediaLeaseService $mediaLeaseService,
        private readonly ReportMediaModulePolicy $modulePolicy,
    ) {}

    public function syncPayloadLinks(array $payload, string $parentType, string $parentKey, int $userId, string $module): void
    {
        $module = $this->modulePolicy->normalize($module);
        if (! $this->modulePolicy->isSupported($module)) {
            return;
        }
        $result = DB::transaction(function () use ($payload, $parentType, $parentKey, $userId, $module): array {
            $rows = $this->uniquePhotoRows($this->collectPhotoRowsForModule($payload, $module));
            $draftTypeCount = is_array($payload['inspectionTypeDrafts'] ?? null)
                ? count($payload['inspectionTypeDrafts'])
                : 0;
            $limitMultiplier = $parentType === 'report_draft' && $module === 'inspection'
                ? min(self::MAX_DRAFT_REPORTS, max(1, $draftTypeCount))
                : 1;
            if (count($rows) > self::MAX_COUNT * $limitMultiplier) {
                throw ValidationException::withMessages(['photos' => ['Maximum 10 photos are allowed.']]);
            }

            $ids = array_values(array_unique(array_filter(array_column($rows, 'mediaId'))));
            sort($ids, SORT_STRING);
            $media = ReportMedia::query()
                ->whereIn('public_id', $ids)
                ->orderBy('public_id')
                ->lockForUpdate()
                ->get()
                ->keyBy('public_id');
            $total = 0;
            foreach ($rows as $row) {
                if ($row['mediaId'] !== '') {
                    $item = $media->get($row['mediaId']);
                    $alreadyLinked = $item?->links()->where('parent_type', $parentType)->where('parent_key', $parentKey)->exists();
                    if (! $item || ((int) $item->user_id !== $userId && ! $alreadyLinked) || $item->module !== $module) {
                        throw ValidationException::withMessages(['photos' => ['A photo reference is invalid or unauthorized.']]);
                    }
                    $total += (int) $item->size_bytes;
                } else {
                    $total += $row['legacyBytes'];
                }
            }
            if ($total > self::MAX_TOTAL_BYTES * $limitMultiplier) {
                throw ValidationException::withMessages(['photos' => ['Total photo size must be 12 MB or smaller.']]);
            }

            ReportMediaLink::query()->where('parent_type', $parentType)->where('parent_key', $parentKey)->delete();
            foreach ($ids as $id) {
                ReportMediaLink::query()->firstOrCreate([
                    'report_media_id' => $media[$id]->id,
                    'parent_type' => $parentType,
                    'parent_key' => $parentKey,
                ]);
            }
            $this->mediaLeaseService->releaseForMediaIds($media->pluck('id')->all());

            return [
                'linked_count' => count($ids),
                'photo_count' => count($rows),
            ];
        });

        DB::afterCommit(function () use ($module, $parentType, $result): void {
            Log::info('report_media_links_reconciled', [
                'module' => $module,
                'parent_type' => $parentType,
                'linked_count' => $result['linked_count'],
                'photo_count' => $result['photo_count'],
            ]);
        });
    }

    public function removeParentLinks(string $parentType, string $parentKey): void
    {
        ReportMediaLink::query()->where('parent_type', $parentType)->where('parent_key', $parentKey)->delete();
    }

    public function hydrateLinkedPayloadForPdf(
        array $payload,
        string $parentType,
        string $parentKey,
        string $module,
    ): array {
        $module = $this->modulePolicy->normalize($module);
        $rows = $this->uniquePhotoRows($this->collectPhotoRowsForModule($payload, $module));
        $ids = array_values(array_unique(array_filter(array_column($rows, 'mediaId'))));
        sort($ids, SORT_STRING);
        $media = collect();
        if ($this->modulePolicy->isSupported($module) && $ids !== []) {
            $media = ReportMedia::query()
                ->where('module', $module)
                ->whereIn('public_id', $ids)
                ->whereHas('links', function ($query) use ($parentType, $parentKey): void {
                    $query->where('parent_type', $parentType)->where('parent_key', $parentKey);
                })
                ->get()
                ->keyBy('public_id');
        }

        $legacyBytes = 0;

        return $this->mapPayloadForPdf($payload, $media, $legacyBytes);
    }

    public function pruneUnlinked(int $olderThanHours = 24): int
    {
        $now = now();
        $rows = ReportMedia::query()
            ->doesntHave('links')
            ->whereDoesntHave('leases', function ($query) use ($now): void {
                $query->where('expires_at', '>', $now)
                    ->where('absolute_expires_at', '>', $now);
            })
            ->where('created_at', '<', $now->copy()->subHours($olderThanHours))
            ->pluck('id');
        $deleted = 0;
        foreach ($rows as $row) {
            $didDelete = DB::transaction(function () use ($row, $now): bool {
                $media = ReportMedia::query()->lockForUpdate()->find($row);
                if (! $media || $media->links()->exists()) {
                    return false;
                }
                $hasActiveLease = $media->leases()
                    ->where('expires_at', '>', $now)
                    ->where('absolute_expires_at', '>', $now)
                    ->exists();
                if ($hasActiveLease) {
                    return false;
                }
                Storage::disk($media->disk)->delete($media->storage_path);
                if ($media->thumbnail_path) {
                    Storage::disk($media->disk)->delete($media->thumbnail_path);
                }
                $media->delete();

                return true;
            });
            if ($didDelete) {
                $deleted++;
            }
        }

        if ($deleted > 0) {
            Log::info('report_media_cleanup_completed', [
                'deleted_count' => $deleted,
                'older_than_hours' => $olderThanHours,
            ]);
        }

        return $deleted;
    }

    private function collectPhotoRows(array $node): array
    {
        $rows = [];
        $walk = function ($value) use (&$walk, &$rows): void {
            if (! is_array($value)) {
                return;
            }
            $mediaId = trim((string) ($value['mediaId'] ?? $value['media_id'] ?? ''));
            $url = trim((string) ($value['url'] ?? ''));
            if ($mediaId !== '' || str_starts_with($url, 'data:image/')) {
                $legacyBytes = 0;
                if ($mediaId === '' && preg_match('/^data:image\/[a-z0-9.+-]+;base64,(.+)$/is', $url, $match)) {
                    $decoded = base64_decode(preg_replace('/\s+/', '', $match[1]), true);
                    $legacyBytes = $decoded === false ? 0 : strlen($decoded);
                }
                $rows[] = [
                    'mediaId' => $mediaId,
                    'legacyBytes' => $legacyBytes,
                    'identity' => $mediaId !== '' ? 'managed:'.$mediaId : 'legacy:'.hash('sha256', $url),
                ];

                return;
            }
            foreach ($value as $child) {
                $walk($child);
            }
        };
        $walk($node);

        return $rows;
    }

    private function collectPhotoRowsForModule(array $payload, string $module): array
    {
        if ($module === 'drill' && (int) ($payload['schemaVersion'] ?? 0) === 2) {
            $photos = data_get($payload, 'postIncidentAnalysis.photos', []);

            return $this->collectPhotoRows(is_array($photos) ? $photos : []);
        }

        return $this->collectPhotoRows($payload);
    }

    private function uniquePhotoRows(array $rows): array
    {
        $unique = [];
        foreach ($rows as $row) {
            $identity = trim((string) ($row['identity'] ?? ''));
            if ($identity === '' || isset($unique[$identity])) {
                continue;
            }
            $unique[$identity] = $row;
        }

        return array_values($unique);
    }

    private function mapPayloadForPdf(array $node, Collection $media, int &$legacyBytes): array
    {
        $mediaId = trim((string) ($node['mediaId'] ?? $node['media_id'] ?? ''));
        if ($mediaId !== '') {
            $item = $media->get($mediaId);
            $node['url'] = '';
            $node['thumbnailUrl'] = '';
            $node['thumbnail_url'] = '';
            if (! $item) {
                return $node;
            }

            try {
                $disk = Storage::disk($item->disk);
                $path = $item->thumbnail_path && $disk->exists($item->thumbnail_path)
                    ? $item->thumbnail_path
                    : $item->storage_path;
                if (! $path || ! $disk->exists($path)) {
                    Log::warning('report_media_pdf_file_missing', [
                        'media_id' => $item->public_id,
                        'module' => $item->module,
                    ]);

                    return $node;
                }
                $mimeType = $path === $item->thumbnail_path ? 'image/jpeg' : $item->mime_type;
                $node['url'] = 'data:'.$mimeType.';base64,'.base64_encode($disk->get($path));
                $node['thumbnailUrl'] = $node['url'];
                $node['thumbnail_url'] = $node['url'];
            } catch (\Throwable $exception) {
                Log::warning('report_media_pdf_hydration_failed', [
                    'media_id' => $item->public_id,
                    'module' => $item->module,
                    'exception' => $exception::class,
                ]);
            }

            return $node;
        }

        if (array_key_exists('url', $node)) {
            $node['url'] = $this->sanitizeLegacyDataImage((string) $node['url'], $legacyBytes);
            if (array_key_exists('thumbnailUrl', $node)) {
                $node['thumbnailUrl'] = '';
            }
            if (array_key_exists('thumbnail_url', $node)) {
                $node['thumbnail_url'] = '';
            }
        }
        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $node[$key] = $this->mapPayloadForPdf($value, $media, $legacyBytes);
            }
        }

        return $node;
    }

    private function sanitizeLegacyDataImage(string $url, int &$legacyBytes): string
    {
        if (! preg_match('/^data:image\/(jpeg|jpg|png|webp);base64,(.+)$/is', trim($url), $match)) {
            return '';
        }
        $encoded = preg_replace('/\s+/', '', $match[2]);
        $decoded = is_string($encoded) ? base64_decode($encoded, true) : false;
        if ($decoded === false || $decoded === '') {
            return '';
        }
        $nextTotal = $legacyBytes + strlen($decoded);
        if ($nextTotal > self::MAX_TOTAL_BYTES) {
            return '';
        }
        $legacyBytes = $nextTotal;
        $mimeType = strtolower($match[1]) === 'jpg' ? 'jpeg' : strtolower($match[1]);

        return 'data:image/'.$mimeType.';base64,'.base64_encode($decoded);
    }
}
