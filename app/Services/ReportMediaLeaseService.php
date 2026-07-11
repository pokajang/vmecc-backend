<?php

namespace App\Services;

use App\Models\ReportMedia;
use App\Models\ReportMediaLease;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ReportMediaLeaseService
{
    public function createOrRenewForUpload(
        ReportMedia $media,
        int $userId,
        string $contextKey = '',
    ): ?ReportMediaLease {
        if ($media->links()->exists()) {
            $media->leases()->delete();

            return null;
        }

        $now = now();
        $lease = $media->leases()->lockForUpdate()->first() ?? new ReportMediaLease([
            'lease_uid' => (string) Str::uuid(),
            'user_id' => $userId,
            'absolute_expires_at' => $now->copy()->addDays($this->absoluteDays()),
        ]);
        if ((int) $lease->user_id !== $userId) {
            throw ValidationException::withMessages([
                'lease' => ['The media lease is invalid or unauthorized.'],
            ]);
        }

        $absoluteExpiry = $lease->absolute_expires_at ?? $now->copy()->addDays($this->absoluteDays());
        if ($absoluteExpiry->isPast()) {
            $absoluteExpiry = $now->copy()->addDays($this->absoluteDays());
        }
        $lease->fill([
            'context_key' => trim($contextKey),
            'expires_at' => $now->copy()->addHours($this->leaseHours())->min($absoluteExpiry),
            'absolute_expires_at' => $absoluteExpiry,
            'renewed_at' => $now,
        ]);
        $media->leases()->save($lease);

        Log::info('report_media_lease_saved', [
            'report_media_id' => $media->id,
            'user_id' => $userId,
            'outcome' => $lease->wasRecentlyCreated ? 'created' : 'renewed',
            'expires_at' => $lease->expires_at?->toIso8601String(),
        ]);

        return $lease->refresh();
    }

    public function renew(
        ReportMedia $media,
        int $userId,
        string $leaseUid,
        string $contextKey = '',
    ): ?ReportMediaLease {
        if ((int) $media->user_id !== $userId) {
            throw ValidationException::withMessages([
                'lease' => ['The media lease is invalid or unauthorized.'],
            ]);
        }
        if ($media->links()->exists()) {
            $media->leases()->delete();

            return null;
        }

        $lease = $media->leases()
            ->where('lease_uid', $leaseUid)
            ->where('user_id', $userId)
            ->lockForUpdate()
            ->first();
        if (! $lease) {
            throw ValidationException::withMessages([
                'lease' => ['The media lease is invalid or expired.'],
            ]);
        }

        return $this->createOrRenewForUpload($media, $userId, $contextKey ?: $lease->context_key);
    }

    public function releaseForMediaIds(array $mediaIds): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $mediaIds))));
        if ($ids === []) {
            return;
        }
        $released = ReportMediaLease::query()->whereIn('report_media_id', $ids)->delete();
        if ($released > 0) {
            Log::info('report_media_leases_finalized', [
                'media_count' => count($ids),
                'lease_count' => $released,
            ]);
        }
    }

    private function leaseHours(): int
    {
        return max(24, (int) config('report_media.lease_hours', 168));
    }

    private function absoluteDays(): int
    {
        return max(7, (int) config('report_media.lease_absolute_days', 30));
    }
}
