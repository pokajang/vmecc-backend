<?php

namespace App\Services;

use App\Models\ReportMedia;
use App\Models\ReportMediaLink;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ReportMediaService
{
    private const MAX_COUNT = 10;

    private const MAX_TOTAL_BYTES = 12 * 1024 * 1024;

    public function syncPayloadLinks(array $payload, string $parentType, string $parentKey, int $userId, string $module): void
    {
        if (! in_array($module, ['inspection', 'erco'], true)) {
            return;
        }
        $rows = $this->collectPhotoRows($payload);
        if (count($rows) > self::MAX_COUNT) {
            throw ValidationException::withMessages(['photos' => ['Maximum 10 photos are allowed.']]);
        }

        $ids = array_values(array_unique(array_filter(array_column($rows, 'mediaId'))));
        $media = ReportMedia::query()->whereIn('public_id', $ids)->get()->keyBy('public_id');
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
        if ($total > self::MAX_TOTAL_BYTES) {
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
    }

    public function removeParentLinks(string $parentType, string $parentKey): void
    {
        ReportMediaLink::query()->where('parent_type', $parentType)->where('parent_key', $parentKey)->delete();
    }

    public function hydratePayloadForPdf(array $payload): array
    {
        return $this->mapPayload($payload, function (array $photo): array {
            $id = trim((string) ($photo['mediaId'] ?? $photo['media_id'] ?? ''));
            if ($id === '') {
                return $photo;
            }
            $media = ReportMedia::query()->where('public_id', $id)->first();
            if (! $media || ! Storage::disk($media->disk)->exists($media->storage_path)) {
                return $photo;
            }
            $photo['url'] = 'data:'.$media->mime_type.';base64,'.base64_encode(Storage::disk($media->disk)->get($media->storage_path));

            return $photo;
        });
    }

    public function pruneUnlinked(int $olderThanHours = 24): int
    {
        $rows = ReportMedia::query()->doesntHave('links')->where('created_at', '<', now()->subHours($olderThanHours))->get();
        foreach ($rows as $row) {
            Storage::disk($row->disk)->delete($row->storage_path);
            if ($row->thumbnail_path) {
                Storage::disk($row->disk)->delete($row->thumbnail_path);
            }
            $row->delete();
        }

        return $rows->count();
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
                $rows[] = ['mediaId' => $mediaId, 'legacyBytes' => $legacyBytes];

                return;
            }
            foreach ($value as $child) {
                $walk($child);
            }
        };
        $walk($node);

        return $rows;
    }

    private function mapPayload(array $node, callable $mapper): array
    {
        $mediaId = trim((string) ($node['mediaId'] ?? $node['media_id'] ?? ''));
        if ($mediaId !== '') {
            return $mapper($node);
        }
        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $node[$key] = $this->mapPayload($value, $mapper);
            }
        }

        return $node;
    }
}
