<?php

namespace App\Services;

use App\Exceptions\ReportImageException;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ReportMediaStorageService
{
    private const DISK = 'local';

    public function storeVerifiedPair(
        int $userId,
        string $publicId,
        string $imageBytes,
        string $thumbnailBytes,
    ): array {
        $imagePath = "report-media/{$userId}/{$publicId}.jpg";
        $thumbnailPath = "report-media/{$userId}/{$publicId}-thumb.jpg";
        $disk = Storage::disk(self::DISK);

        try {
            $this->writeVerified(
                $disk,
                $imagePath,
                $imageBytes,
                'storage_write_failed',
                'The photo could not be saved. Try again.',
            );
            $this->writeVerified(
                $disk,
                $thumbnailPath,
                $thumbnailBytes,
                'thumbnail_write_failed',
                'The photo preview could not be saved. Try again.',
            );
        } catch (Throwable $exception) {
            $this->deletePair($imagePath, $thumbnailPath);
            if ($exception instanceof ReportImageException) {
                throw $exception;
            }

            throw new ReportImageException(
                'storage_write_failed',
                'The photo could not be saved. Try again.',
                507,
            );
        }

        return [
            'disk' => self::DISK,
            'imagePath' => $imagePath,
            'thumbnailPath' => $thumbnailPath,
        ];
    }

    public function deletePair(?string $imagePath, ?string $thumbnailPath): void
    {
        $paths = array_values(array_filter([$imagePath, $thumbnailPath]));
        if ($paths === []) {
            return;
        }

        try {
            Storage::disk(self::DISK)->delete($paths);
        } catch (Throwable $exception) {
            Log::warning('report_media_cleanup_failed', [
                'paths' => $paths,
                'exception' => $exception::class,
            ]);
        }
    }

    private function writeVerified(
        Filesystem $disk,
        string $path,
        string $bytes,
        string $errorCode,
        string $message,
    ): void {
        if ($bytes === '' || ! $disk->put($path, $bytes) || ! $disk->exists($path)) {
            throw new ReportImageException($errorCode, $message, 507);
        }

        $storedBytes = $disk->get($path);
        if (
            strlen($storedBytes) !== strlen($bytes)
            || ! hash_equals(hash('sha256', $bytes), hash('sha256', $storedBytes))
        ) {
            throw new ReportImageException(
                'storage_verification_failed',
                'The saved photo could not be verified. Try again.',
                507,
            );
        }
    }
}
