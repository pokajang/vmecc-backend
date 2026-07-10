<?php

namespace App\Services;

use App\Exceptions\ReportImageException;
use App\Models\LeaveAttachment;
use App\Models\ReportMedia;

class PhotoUploadCapacityService
{
    public function assertCanAccept(int $userId, int $incomingBytes): void
    {
        $storagePath = (string) config('filesystems.disks.local.root', storage_path('app'));
        $freeBytes = @disk_free_space($storagePath);
        $minimumFreeBytes = max(0, (int) config('report_media.minimum_disk_free_bytes', 1073741824));
        if ($freeBytes === false || $freeBytes - max(0, $incomingBytes) < $minimumFreeBytes) {
            throw new ReportImageException('storage_unavailable', 'Photo storage is temporarily unavailable. Try again later.', 503);
        }

        $reportBytes = (int) ReportMedia::query()->where('user_id', $userId)->doesntHave('links')->sum('size_bytes');
        $leaveBytes = (int) LeaveAttachment::query()->where('user_id', $userId)->whereNull('leave_id')->sum('size');
        $quota = max(32 * 1024 * 1024, (int) config('report_media.temporary_user_quota_bytes', 134217728));
        if ($reportBytes + $leaveBytes + max(0, $incomingBytes) > $quota) {
            throw new ReportImageException(
                'storage_quota_exceeded',
                'Temporary photo storage is full. Remove unused attachments or try again after cleanup.',
                429,
            );
        }
    }
}
