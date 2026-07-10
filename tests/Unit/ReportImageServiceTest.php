<?php

namespace Tests\Unit;

use App\Services\ReportImageService;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class ReportImageServiceTest extends TestCase
{
    public function test_it_normalizes_an_image_to_a_small_jpeg(): void
    {
        $image = imagecreatetruecolor(1600, 900);
        $white = imagecolorallocate($image, 255, 255, 255);
        imagefilledrectangle($image, 0, 0, 1600, 900, $white);
        $path = tempnam(sys_get_temp_dir(), 'report-image-').'.png';
        imagepng($image, $path);
        imagedestroy($image);

        try {
            $result = app(ReportImageService::class)->normalize(new UploadedFile($path, 'camera.png', 'image/png', null, true), 'camera');
            $this->assertSame('image/jpeg', $result['mimeType']);
            $this->assertLessThanOrEqual(1280, max($result['width'], $result['height']));
            $this->assertLessThanOrEqual(1536 * 1024, $result['sizeBytes']);
        } finally {
            @unlink($path);
        }
    }
}
