<?php

namespace App\Services;

use App\Exceptions\ReportImageException;
use Illuminate\Http\UploadedFile;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

class ReportImageService
{
    private const CAMERA_MAX_BYTES = 12 * 1024 * 1024;

    private const UPLOAD_MAX_BYTES = 15 * 1024 * 1024;

    private const MAX_PIXELS = 100_000_000;

    private const GD_MAX_PIXELS = 50_000_000;

    private const MAX_DIMENSION = 1280;

    private const TARGET_BYTES = 750 * 1024;

    private const HARD_MAX_BYTES = 1536 * 1024;

    private const STANDARD_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    private const HEIF_MIMES = ['image/heic', 'image/heif', 'image/heic-sequence', 'image/heif-sequence'];

    public function capabilities(): array
    {
        $processor = $this->externalProcessor();
        $storagePath = (string) config('filesystems.disks.local.root', storage_path('app'));
        $diskFreeBytes = @disk_free_space($storagePath);
        $heic = $this->processorSupportsHeic($processor);
        $uploadMaxBytes = $this->bytesFromIni((string) ini_get('upload_max_filesize'));
        $postMaxBytes = $this->bytesFromIni((string) ini_get('post_max_size'));
        $minimumDiskFreeBytes = max(0, (int) config('report_media.minimum_disk_free_bytes', 1073741824));
        $storageWritable = $this->canWriteDirectory($storagePath);
        $temporaryWritable = is_writable(sys_get_temp_dir());
        $ready = extension_loaded('gd')
            && extension_loaded('exif')
            && $storageWritable
            && $temporaryWritable
            && $diskFreeBytes !== false
            && $diskFreeBytes >= $minimumDiskFreeBytes
            && $uploadMaxBytes >= self::UPLOAD_MAX_BYTES
            && $postMaxBytes >= self::UPLOAD_MAX_BYTES
            && (! config('report_media.require_heic_processor', true) || $heic);

        return [
            'processor' => $processor['name'] ?? 'gd',
            'libvips' => ($processor['name'] ?? '') === 'libvips',
            'imagemagick' => ($processor['name'] ?? '') === 'imagemagick',
            'gd' => extension_loaded('gd'),
            'exif' => extension_loaded('exif'),
            'heic' => $heic,
            'maxSourceBytes' => self::UPLOAD_MAX_BYTES,
            'maxPixels' => self::MAX_PIXELS,
            'temporaryDirectoryWritable' => $temporaryWritable,
            'storageDirectoryWritable' => $storageWritable,
            'diskFreeBytes' => $diskFreeBytes === false ? null : (int) $diskFreeBytes,
            'phpUploadMaxFilesize' => ini_get('upload_max_filesize') ?: null,
            'phpPostMaxSize' => ini_get('post_max_size') ?: null,
            'phpMemoryLimit' => ini_get('memory_limit') ?: null,
            'minimumDiskFreeBytes' => $minimumDiskFreeBytes,
            'temporaryUserQuotaBytes' => (int) config('report_media.temporary_user_quota_bytes'),
            'ready' => $ready,
        ];
    }

    public function normalize(UploadedFile $file, string $source = 'upload'): array
    {
        $this->assertNotAnimatedWebp($file);
        $source = $source === 'camera' ? 'camera' : 'upload';
        $maxBytes = $source === 'camera' ? self::CAMERA_MAX_BYTES : self::UPLOAD_MAX_BYTES;
        $size = (int) $file->getSize();
        if ($size <= 0) {
            throw new ReportImageException('invalid_file', 'The selected image is empty or unreadable.');
        }
        if ($size > $maxBytes) {
            throw new ReportImageException('file_too_large', 'The selected image exceeds the upload limit.');
        }

        $path = (string) $file->getRealPath();
        $mime = strtolower((string) (new \finfo(FILEINFO_MIME_TYPE))->file($path));
        if (! in_array($mime, [...self::STANDARD_MIMES, ...self::HEIF_MIMES], true)) {
            throw new ReportImageException('unsupported_file_type', 'Only valid JPEG, PNG, WebP, HEIC, and HEIF images are supported.');
        }
        [$width, $height] = $this->sourceDimensions($path);
        if ($width <= 0 || $height <= 0) {
            throw new ReportImageException('image_decode_failed', 'The image dimensions could not be read.');
        }
        if (($width * $height) > self::MAX_PIXELS) {
            throw new ReportImageException('image_dimensions_too_large', 'The image dimensions are too large to process safely.');
        }

        $external = $this->externalProcessor();
        if ($external) {
            try {
                return $this->normalizeExternally($path, $size, $external);
            } catch (ReportImageException $exception) {
                if (in_array($mime, self::HEIF_MIMES, true)) {
                    throw $exception;
                }
            }
        }
        if (! in_array($mime, self::STANDARD_MIMES, true)) {
            throw new ReportImageException('unsupported_file_type', 'HEIC/HEIF processing is unavailable on this server.');
        }
        if (($width * $height) > self::GD_MAX_PIXELS || ! $this->hasSafeMemoryBudget($width, $height)) {
            throw new ReportImageException('image_dimensions_too_large', 'The image dimensions are too large for the available processor.');
        }

        return $this->normalizeWithGd($path, $mime, $size);
    }

    private function assertNotAnimatedWebp(UploadedFile $file): void
    {
        if (strtolower((string) $file->getMimeType()) !== 'image/webp') {
            return;
        }
        $handle = @fopen((string) $file->getRealPath(), 'rb');
        if (! $handle) {
            return;
        }
        try {
            $header = (string) fread($handle, 131072);
            if (str_starts_with($header, 'RIFF') && str_contains($header, 'ANIM')) {
                throw new ReportImageException(
                    'unsupported_file_type',
                    'Animated WebP files are not supported. Upload a still JPEG, PNG, WebP, HEIC, or HEIF image.',
                );
            }
        } finally {
            fclose($handle);
        }
    }

    private function externalProcessor(): ?array
    {
        $finder = new ExecutableFinder;
        $vips = env('REPORT_IMAGE_VIPS_BINARY') ?: $finder->find('vipsthumbnail');
        if ($vips) {
            return ['name' => 'libvips', 'binary' => $vips];
        }
        $magick = env('REPORT_IMAGE_MAGICK_BINARY') ?: $finder->find('magick');

        return $magick ? ['name' => 'imagemagick', 'binary' => $magick] : null;
    }

    private function sourceDimensions(string $path): array
    {
        $info = @getimagesize($path);
        if ($info) {
            return [(int) $info[0], (int) $info[1]];
        }
        $finder = new ExecutableFinder;
        $vipsHeader = $finder->find('vipsheader');
        if ($vipsHeader) {
            $widthProcess = new Process([$vipsHeader, '-f', 'width', $path]);
            $heightProcess = new Process([$vipsHeader, '-f', 'height', $path]);
            $widthProcess->setTimeout(10)->run();
            $heightProcess->setTimeout(10)->run();
            if ($widthProcess->isSuccessful() && $heightProcess->isSuccessful()) {
                return [(int) trim($widthProcess->getOutput()), (int) trim($heightProcess->getOutput())];
            }
        }
        $magick = env('REPORT_IMAGE_MAGICK_BINARY') ?: $finder->find('magick');
        if (! $magick) {
            return [0, 0];
        }
        $process = new Process([$magick, 'identify', '-format', '%w %h', $path]);
        $process->setTimeout(10)->run();
        if (! $process->isSuccessful()) {
            return [0, 0];
        }
        $parts = preg_split('/\s+/', trim($process->getOutput()));

        return [(int) ($parts[0] ?? 0), (int) ($parts[1] ?? 0)];
    }

    private function normalizeExternally(string $path, int $originalSize, array $processor): array
    {
        $base = tempnam(sys_get_temp_dir(), 'vmecc-report-image-');
        if ($base === false) {
            throw new ReportImageException('processing_failed', 'Unable to allocate image workspace.');
        }
        @unlink($base);
        $output = $base.'.jpg';
        $best = null;
        try {
            foreach ([82, 75, 68, 60, 52, 45] as $quality) {
                @unlink($output);
                $command = $processor['name'] === 'libvips'
                    ? [$processor['binary'], $path, '--size', self::MAX_DIMENSION.'x'.self::MAX_DIMENSION, '--rotate', '--output', $output.'[Q='.$quality.',strip,optimize_coding,background=255]']
                    : [$processor['binary'], $path.'[0]', '-auto-orient', '-thumbnail', self::MAX_DIMENSION.'x'.self::MAX_DIMENSION.'>', '-background', 'white', '-alpha', 'remove', '-alpha', 'off', '-strip', '-quality', (string) $quality, $output];
                $process = new Process($command);
                $process->setTimeout(30)->run();
                if (! $process->isSuccessful() || ! is_file($output)) {
                    continue;
                }
                $bytes = file_get_contents($output);
                if ($bytes === false || $bytes === '') {
                    continue;
                }
                if ($best === null || strlen($bytes) < strlen($best)) {
                    $best = $bytes;
                }
                if (strlen($bytes) <= self::TARGET_BYTES) {
                    break;
                }
            }
            if ($best === null) {
                throw new ReportImageException('image_decode_failed', 'The image could not be decoded by the server processor.');
            }
            if (strlen($best) > self::HARD_MAX_BYTES) {
                throw new ReportImageException('processing_failed', 'The image remains too large after normalization.');
            }
            file_put_contents($output, $best);
            $info = @getimagesize($output);
            if (! $info) {
                throw new ReportImageException('processing_failed', 'The normalized image is invalid.');
            }

            return ['bytes' => $best, 'mimeType' => 'image/jpeg', 'sizeBytes' => strlen($best), 'originalSize' => $originalSize, 'width' => (int) $info[0], 'height' => (int) $info[1], 'wasCompressed' => true, 'processor' => $processor['name']];
        } finally {
            @unlink($output);
            @unlink($base);
        }
    }

    private function normalizeWithGd(string $path, string $mime, int $originalSize): array
    {
        $sourceImage = null;
        $outputImage = null;
        try {
            $sourceImage = match ($mime) {
                'image/jpeg' => @imagecreatefromjpeg($path),
                'image/png' => @imagecreatefrompng($path),
                'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
                default => false,
            };
            if (! $sourceImage) {
                throw new ReportImageException('image_decode_failed', 'The image could not be decoded.');
            }
            if ($mime === 'image/jpeg') {
                $sourceImage = $this->applyExifOrientation($sourceImage, $path);
            }
            $sourceWidth = imagesx($sourceImage);
            $sourceHeight = imagesy($sourceImage);
            $ratio = min(1, self::MAX_DIMENSION / max($sourceWidth, $sourceHeight));
            $targetWidth = max(1, (int) round($sourceWidth * $ratio));
            $targetHeight = max(1, (int) round($sourceHeight * $ratio));
            $outputImage = imagecreatetruecolor($targetWidth, $targetHeight);
            if (! $outputImage) {
                throw new ReportImageException('processing_failed', 'The image could not be prepared.');
            }
            $white = imagecolorallocate($outputImage, 255, 255, 255);
            imagefilledrectangle($outputImage, 0, 0, $targetWidth, $targetHeight, $white);
            if (! imagecopyresampled($outputImage, $sourceImage, 0, 0, 0, 0, $targetWidth, $targetHeight, $sourceWidth, $sourceHeight)) {
                throw new ReportImageException('processing_failed', 'The image could not be resized.');
            }
            $best = null;
            foreach ([82, 75, 68, 60, 52, 45] as $quality) {
                ob_start();
                $encoded = imagejpeg($outputImage, null, $quality);
                $bytes = (string) ob_get_clean();
                if ($encoded && $bytes !== '' && ($best === null || strlen($bytes) < strlen($best))) {
                    $best = $bytes;
                }
                if ($encoded && strlen($bytes) <= self::TARGET_BYTES) {
                    break;
                }
            }
            if ($best === null || strlen($best) > self::HARD_MAX_BYTES) {
                throw new ReportImageException('processing_failed', 'The image remains too large after normalization.');
            }

            return ['bytes' => $best, 'mimeType' => 'image/jpeg', 'sizeBytes' => strlen($best), 'originalSize' => $originalSize, 'width' => $targetWidth, 'height' => $targetHeight, 'wasCompressed' => strlen($best) < $originalSize || $mime !== 'image/jpeg' || $ratio < 1, 'processor' => 'gd'];
        } finally {
            if ($outputImage instanceof \GdImage) {
                imagedestroy($outputImage);
            }
            if ($sourceImage instanceof \GdImage) {
                imagedestroy($sourceImage);
            }
        }
    }

    private function applyExifOrientation(\GdImage $image, string $path): \GdImage
    {
        if (! function_exists('exif_read_data')) {
            return $image;
        }
        $exif = @exif_read_data($path);
        $orientation = (int) ($exif['Orientation'] ?? 1);
        if (in_array($orientation, [2, 4, 5, 7], true)) {
            imageflip($image, in_array($orientation, [2, 5], true) ? IMG_FLIP_HORIZONTAL : IMG_FLIP_VERTICAL);
        }
        $rotated = match ($orientation) {
            3, 4 => imagerotate($image, 180, 0), 5, 6 => imagerotate($image, -90, 0), 7, 8 => imagerotate($image, 90, 0), default => false
        };
        if ($rotated instanceof \GdImage) {
            imagedestroy($image);

            return $rotated;
        }

        return $image;
    }

    private function hasSafeMemoryBudget(int $width, int $height): bool
    {
        $limit = $this->bytesFromIni((string) ini_get('memory_limit'));
        if ($limit <= 0) {
            return true;
        }

        return memory_get_usage(true) + (int) ceil($width * $height * 5.5) + (16 * 1024 * 1024) < ($limit * 0.85);
    }

    private function processorSupportsHeic(?array $processor): bool
    {
        $name = (string) ($processor['name'] ?? '');
        if ($name === 'libvips') {
            return $this->libvipsSupportsHeic((string) ($processor['binary'] ?? ''));
        }
        if ($name !== 'imagemagick') {
            return false;
        }
        $binary = (string) ($processor['binary'] ?? '');
        if ($binary === '') {
            return false;
        }
        try {
            $process = new Process([$binary, '-list', 'format']);
            $process->setTimeout(5);
            $process->run();

            $output = $process->getOutput()."\n".$process->getErrorOutput();

            return $process->isSuccessful() && preg_match('/^\s*HEI[CF]\*?\s+.*r/mi', $output) === 1;
        } catch (Throwable) {
            return false;
        }
    }

    private function libvipsSupportsHeic(string $thumbnailBinary): bool
    {
        $finder = new ExecutableFinder;
        $binary = $finder->find('vips');
        if (! $binary && $thumbnailBinary !== '') {
            $candidate = dirname($thumbnailBinary).DIRECTORY_SEPARATOR.(PHP_OS_FAMILY === 'Windows' ? 'vips.exe' : 'vips');
            if (is_file($candidate)) {
                $binary = $candidate;
            }
        }
        if (! $binary) {
            return false;
        }
        try {
            $process = new Process([$binary, '-l', 'foreign-load']);
            $process->setTimeout(5);
            $process->run();
            $output = strtolower($process->getOutput()."\n".$process->getErrorOutput());

            return $process->isSuccessful()
                && (str_contains($output, 'foreignloadheif') || str_contains($output, 'heifload'));
        } catch (Throwable) {
            return false;
        }
    }

    private function canWriteDirectory(string $path): bool
    {
        if (! is_dir($path) && ! @mkdir($path, 0755, true) && ! is_dir($path)) {
            return false;
        }
        $probe = rtrim($path, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'.report-media-health-'.bin2hex(random_bytes(8));
        try {
            return @file_put_contents($probe, 'ok', LOCK_EX) === 2;
        } catch (Throwable) {
            return false;
        } finally {
            @unlink($probe);
        }
    }

    private function bytesFromIni(string $value): int
    {
        $value = strtolower(trim($value));
        if ($value === '' || $value === '-1') {
            return -1;
        }

        return (int) ((float) $value * match (substr($value, -1)) {
            'g' => 1024 ** 3, 'm' => 1024 ** 2, 'k' => 1024, default => 1
        });
    }
}
