<?php

namespace Tests\Unit;

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
