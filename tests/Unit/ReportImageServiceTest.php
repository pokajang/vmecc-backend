<?php

namespace Tests\Unit;

use App\Exceptions\ReportImageException;
use App\Services\ReportImageService;
use Illuminate\Http\UploadedFile;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ReportImageServiceTest extends TestCase
{
    public function test_it_reports_the_expanded_mobile_source_limit(): void
    {
        $capabilities = app(ReportImageService::class)->capabilities();

        $this->assertSame(30 * 1024 * 1024, $capabilities['maxSourceBytes']);
    }

    public function test_it_normalizes_an_image_to_a_small_jpeg(): void
    {
        $image = imagecreatetruecolor(1600, 900);
        $white = imagecolorallocate($image, 255, 255, 255);
        imagefilledrectangle($image, 0, 0, 1600, 900, $white);
        $basePath = tempnam(sys_get_temp_dir(), 'report-image-');
        $path = $basePath.'.png';
        imagepng($image, $path);
        imagedestroy($image);

        try {
            $result = app(ReportImageService::class)->normalize(new UploadedFile($path, 'camera.png', 'image/png', null, true), 'camera');
            $this->assertSame('image/jpeg', $result['mimeType']);
            $this->assertLessThanOrEqual(1280, max($result['width'], $result['height']));
            $this->assertLessThanOrEqual(1536 * 1024, $result['sizeBytes']);
        } finally {
            @unlink($basePath);
            @unlink($path);
        }
    }

    public function test_it_preserves_portrait_and_landscape_aspect_ratios(): void
    {
        foreach ([[1600, 900], [900, 1600]] as [$sourceWidth, $sourceHeight]) {
            $image = imagecreatetruecolor($sourceWidth, $sourceHeight);
            $white = imagecolorallocate($image, 255, 255, 255);
            imagefilledrectangle($image, 0, 0, $sourceWidth, $sourceHeight, $white);
            $basePath = tempnam(sys_get_temp_dir(), 'report-image-orientation-');
            $path = $basePath.'.png';
            imagepng($image, $path);
            imagedestroy($image);

            try {
                $result = app(ReportImageService::class)->normalize(
                    new UploadedFile($path, 'camera.png', 'image/png', null, true),
                    'camera',
                );

                $this->assertEqualsWithDelta(
                    $sourceWidth / $sourceHeight,
                    $result['width'] / $result['height'],
                    0.01,
                );
                $this->assertSame($sourceWidth > $sourceHeight, $result['width'] > $result['height']);
            } finally {
                @unlink($basePath);
                @unlink($path);
            }
        }
    }

    public function test_it_applies_phone_exif_orientation_before_storing_the_photo(): void
    {
        $image = imagecreatetruecolor(80, 40);
        $red = imagecolorallocate($image, 220, 20, 20);
        $green = imagecolorallocate($image, 20, 180, 20);
        $blue = imagecolorallocate($image, 20, 20, 220);
        $yellow = imagecolorallocate($image, 220, 200, 20);
        imagefilledrectangle($image, 0, 0, 39, 19, $red);
        imagefilledrectangle($image, 40, 0, 79, 19, $green);
        imagefilledrectangle($image, 0, 20, 39, 39, $blue);
        imagefilledrectangle($image, 40, 20, 79, 39, $yellow);

        $basePath = tempnam(sys_get_temp_dir(), 'report-image-exif-');
        $path = $basePath.'.jpg';
        imagejpeg($image, $path, 95);
        imagedestroy($image);

        $jpeg = file_get_contents($path);
        $exif = "Exif\0\0II\x2A\x00\x08\x00\x00\x00\x01\x00"
            ."\x12\x01\x03\x00\x01\x00\x00\x00\x06\x00\x00\x00\x00\x00\x00\x00";
        file_put_contents(
            $path,
            substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($exif) + 2).$exif.substr($jpeg, 2),
        );

        try {
            $result = app(ReportImageService::class)->normalize(
                new UploadedFile($path, 'phone-photo.jpg', 'image/jpeg', null, true),
            );

            $this->assertSame(40, $result['width']);
            $this->assertSame(80, $result['height']);

            $normalized = imagecreatefromstring($result['bytes']);
            $topLeft = imagecolorsforindex($normalized, imagecolorat($normalized, 5, 5));
            imagedestroy($normalized);
            $this->assertGreaterThan($topLeft['red'], $topLeft['blue']);
        } finally {
            @unlink($basePath);
            @unlink($path);
        }
    }

    public function test_it_rejects_a_file_that_claims_to_be_an_image_but_has_invalid_content(): void
    {
        $file = UploadedFile::fake()->createWithContent('spoofed.jpg', 'not an image');

        try {
            app(ReportImageService::class)->normalize($file);
            $this->fail('Invalid image content should be rejected.');
        } catch (ReportImageException $exception) {
            $this->assertSame('unsupported_file_type', $exception->errorCode);
            $this->assertSame(422, $exception->httpStatus);
        }
    }

    public function test_it_normalizes_avif_when_the_required_processor_is_available(): void
    {
        $magick = (new ExecutableFinder)->find('magick');
        if (! $magick) {
            $this->markTestSkipped('ImageMagick is unavailable.');
        }

        $image = imagecreatetruecolor(640, 480);
        $white = imagecolorallocate($image, 255, 255, 255);
        imagefilledrectangle($image, 0, 0, 640, 480, $white);
        $pngBasePath = tempnam(sys_get_temp_dir(), 'report-image-source-');
        $avifBasePath = tempnam(sys_get_temp_dir(), 'report-image-avif-');
        $pngPath = $pngBasePath.'.png';
        $avifPath = $avifBasePath.'.avif';
        imagepng($image, $pngPath);
        imagedestroy($image);

        try {
            $process = new Process([$magick, $pngPath, $avifPath]);
            $process->setTimeout(30)->mustRun();
            $result = app(ReportImageService::class)->normalize(
                new UploadedFile($avifPath, 'camera.avif', 'image/avif', null, true),
                'camera',
            );

            $this->assertSame('image/jpeg', $result['mimeType']);
            $this->assertLessThanOrEqual(1280, max($result['width'], $result['height']));
        } finally {
            @unlink($pngBasePath);
            @unlink($avifBasePath);
            @unlink($pngPath);
            @unlink($avifPath);
        }
    }
}
