<?php

namespace App\Services\InspectionReports;

class InspectionReportPhotoSanitizer
{
    private const ALLOWED_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    public function sanitize(array $record, ?int $maxImages = null): InspectionReportPhotoSanitizationResult
    {
        $imageCount = 0;
        $unavailableImageCount = 0;
        $omittedImageCount = 0;
        $photoSlotCount = 0;
        $totalImageBytes = 0;
        $maxImages = max(1, $maxImages ?? (int) config('inspection_reports.pdf.max_images', 20));

        $record = $this->sanitizeNode(
            $record,
            $imageCount,
            $unavailableImageCount,
            $omittedImageCount,
            $photoSlotCount,
            $totalImageBytes,
            $maxImages,
        );

        return new InspectionReportPhotoSanitizationResult(
            $record,
            $imageCount,
            $unavailableImageCount,
            $omittedImageCount,
            $totalImageBytes,
        );
    }

    private function sanitizeNode(
        array $node,
        int &$imageCount,
        int &$unavailableImageCount,
        int &$omittedImageCount,
        int &$photoSlotCount,
        int &$totalImageBytes,
        int $maxImages,
    ): array {
        if (array_key_exists('url', $node)) {
            if ($photoSlotCount >= $maxImages) {
                $node = $this->omitted($node, $omittedImageCount);
            } else {
                $photoSlotCount++;
                $node = $this->sanitizePhoto($node, $imageCount, $unavailableImageCount, $totalImageBytes);
            }
        }

        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $node[$key] = $this->sanitizeNode(
                    $value,
                    $imageCount,
                    $unavailableImageCount,
                    $omittedImageCount,
                    $photoSlotCount,
                    $totalImageBytes,
                    $maxImages,
                );
            }
        }

        return $node;
    }

    private function sanitizePhoto(
        array $photo,
        int &$imageCount,
        int &$unavailableImageCount,
        int &$totalImageBytes,
    ): array {
        $url = trim((string) ($photo['url'] ?? ''));
        if (preg_match('/^data:(image\/(?:jpeg|jpg|png|webp));base64,(.+)$/is', $url, $match) !== 1) {
            return $this->unavailable($photo, $unavailableImageCount);
        }

        $maxImageBytes = max(1, (int) config('inspection_reports.pdf.max_image_bytes', 2 * 1024 * 1024));
        $maxTotalBytes = max(1, (int) config('inspection_reports.pdf.max_total_image_bytes', 12 * 1024 * 1024));
        $encoded = $match[2];
        $maxEncodedLength = (4 * (int) ceil($maxImageBytes / 3)) + 4;
        if (preg_match('/\s/', $encoded) === 1 || strlen($encoded) > $maxEncodedLength) {
            return $this->unavailable($photo, $unavailableImageCount);
        }

        $bytes = base64_decode($encoded, true);
        $byteCount = is_string($bytes) ? strlen($bytes) : 0;
        if (
            $bytes === false
            || $bytes === ''
            || $byteCount > $maxImageBytes
            || ($totalImageBytes + $byteCount) > $maxTotalBytes
        ) {
            return $this->unavailable($photo, $unavailableImageCount);
        }

        $info = @getimagesizefromstring($bytes);
        $actualMime = strtolower((string) ($info['mime'] ?? ''));
        $width = (int) ($info[0] ?? 0);
        $height = (int) ($info[1] ?? 0);
        if (
            ! in_array($actualMime, self::ALLOWED_MIMES, true)
            || $width <= 0
            || $height <= 0
            || ($width * $height) > max(1, (int) config('inspection_reports.pdf.max_image_pixels', 16_000_000))
        ) {
            return $this->unavailable($photo, $unavailableImageCount);
        }

        $declaredMime = strtolower($match[1]) === 'image/jpg' ? 'image/jpeg' : strtolower($match[1]);
        if ($declaredMime !== $actualMime) {
            return $this->unavailable($photo, $unavailableImageCount);
        }

        $imageCount++;
        $totalImageBytes += $byteCount;
        $photo['url'] = 'data:'.$actualMime.';base64,'.base64_encode($bytes);
        $photo['imageUnavailable'] = false;
        $photo['imageOmitted'] = false;

        return $photo;
    }

    private function unavailable(array $photo, int &$unavailableImageCount): array
    {
        $unavailableImageCount++;
        $photo['url'] = '';
        $photo['imageUnavailable'] = true;
        $photo['imageOmitted'] = false;

        return $photo;
    }

    private function omitted(array $photo, int &$omittedImageCount): array
    {
        $omittedImageCount++;
        $photo['url'] = '';
        $photo['imageUnavailable'] = false;
        $photo['imageOmitted'] = true;

        return $photo;
    }
}
