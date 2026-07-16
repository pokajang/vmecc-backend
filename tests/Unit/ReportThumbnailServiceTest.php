<?php

namespace Tests\Unit;

use App\Services\ReportThumbnailService;
use Tests\TestCase;

class ReportThumbnailServiceTest extends TestCase
{
    public function test_it_creates_a_bounded_jpeg_thumbnail(): void
    {
        $image = imagecreatetruecolor(1280, 960);
        $white = imagecolorallocate($image, 255, 255, 255);
        imagefill($image, 0, 0, $white);
        ob_start();
        imagejpeg($image, null, 82);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        $result = app(ReportThumbnailService::class)->create($bytes);

        $this->assertLessThanOrEqual(480, max($result['width'], $result['height']));
        $this->assertSame('image/jpeg', (new \finfo(FILEINFO_MIME_TYPE))->buffer($result['bytes']));
        $this->assertSame(hash('sha256', $result['bytes']), $result['checksum']);
    }

    public function test_it_preserves_portrait_and_landscape_aspect_ratios(): void
    {
        foreach ([[1280, 720], [720, 1280]] as [$sourceWidth, $sourceHeight]) {
            $image = imagecreatetruecolor($sourceWidth, $sourceHeight);
            $white = imagecolorallocate($image, 255, 255, 255);
            imagefill($image, 0, 0, $white);
            ob_start();
            imagejpeg($image, null, 82);
            $bytes = (string) ob_get_clean();
            imagedestroy($image);

            $result = app(ReportThumbnailService::class)->create($bytes);

            $this->assertEqualsWithDelta(
                $sourceWidth / $sourceHeight,
                $result['width'] / $result['height'],
                0.01,
            );
            $this->assertSame($sourceWidth > $sourceHeight, $result['width'] > $result['height']);
        }
    }
}
