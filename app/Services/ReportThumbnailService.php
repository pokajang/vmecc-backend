<?php

namespace App\Services;

use App\Exceptions\ReportImageException;
use Throwable;

class ReportThumbnailService
{
    public function create(string $jpegBytes): array
    {
        $source = null;
        $thumbnail = null;
        $initialBufferLevel = ob_get_level();

        try {
            $source = @imagecreatefromstring($jpegBytes);
            if (! $source) {
                throw new ReportImageException('thumbnail_failed', 'Unable to create the photo preview.', 500);
            }

            $sourceWidth = imagesx($source);
            $sourceHeight = imagesy($source);
            $maxDimension = max(160, min(720, (int) config('report_media.thumbnail_max_dimension', 480)));
            $scale = min(1, $maxDimension / max($sourceWidth, $sourceHeight));
            $width = max(1, (int) round($sourceWidth * $scale));
            $height = max(1, (int) round($sourceHeight * $scale));
            $thumbnail = imagecreatetruecolor($width, $height);
            if (! $thumbnail) {
                throw new ReportImageException('thumbnail_failed', 'Unable to allocate the photo preview.', 500);
            }

            $white = imagecolorallocate($thumbnail, 255, 255, 255);
            imagefill($thumbnail, 0, 0, $white);
            if (! imagecopyresampled($thumbnail, $source, 0, 0, 0, 0, $width, $height, $sourceWidth, $sourceHeight)) {
                throw new ReportImageException('thumbnail_failed', 'Unable to resize the photo preview.', 500);
            }

            ob_start();
            $written = imagejpeg(
                $thumbnail,
                null,
                max(55, min(85, (int) config('report_media.thumbnail_quality', 76))),
            );
            $bytes = ob_get_clean();
            if (! $written || ! is_string($bytes) || $bytes === '') {
                throw new ReportImageException('thumbnail_failed', 'Unable to encode the photo preview.', 500);
            }

            return [
                'bytes' => $bytes,
                'sizeBytes' => strlen($bytes),
                'width' => $width,
                'height' => $height,
                'checksum' => hash('sha256', $bytes),
            ];
        } catch (ReportImageException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new ReportImageException('thumbnail_failed', 'Unable to create the photo preview.', 500);
        } finally {
            if ($thumbnail) {
                imagedestroy($thumbnail);
            }
            if ($source) {
                imagedestroy($source);
            }
            while (ob_get_level() > $initialBufferLevel) {
                @ob_end_clean();
            }
        }
    }
}
