<?php

namespace Tests\Unit;

use App\Services\InspectionReports\InspectionReportPhotoSanitizer;
use App\Services\InspectionReports\InspectionReportViewDataBuilder;
use App\Services\InspectionReports\PdfFooterTextFitter;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class InspectionReportPdfHardeningTest extends TestCase
{
    private const VALID_PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR4nGNgYAAAAAMAASsJTYQAAAAASUVORK5CYII=';

    #[Test]
    public function it_sanitizes_nested_images_and_marks_unsafe_images_unavailable(): void
    {
        $result = app(InspectionReportPhotoSanitizer::class)->sanitize([
            'photos' => [
                ['description' => 'Valid', 'url' => self::VALID_PNG],
                ['description' => 'Remote', 'url' => 'https://example.com/photo.jpg'],
                ['description' => 'Corrupt', 'url' => 'data:image/png;base64,QUFB'],
                ['description' => 'MIME mismatch', 'url' => str_replace('image/png', 'image/jpeg', self::VALID_PNG)],
            ],
            'checks' => [[
                'photos' => [['description' => 'Nested', 'url' => self::VALID_PNG]],
            ]],
        ]);

        $this->assertSame(2, $result->imageCount);
        $this->assertSame(3, $result->unavailableImageCount);
        $this->assertSame(0, $result->omittedImageCount);
        $this->assertFalse($result->record['photos'][0]['imageUnavailable']);
        $this->assertTrue($result->record['photos'][1]['imageUnavailable']);
        $this->assertTrue($result->record['photos'][2]['imageUnavailable']);
        $this->assertTrue($result->record['photos'][3]['imageUnavailable']);
        $this->assertSame('', $result->record['photos'][1]['url']);
        $this->assertFalse($result->record['checks'][0]['photos'][0]['imageUnavailable']);
    }

    #[Test]
    public function it_shortens_only_the_report_id_when_a_footer_is_too_wide(): void
    {
        $fitter = app(PdfFooterTextFitter::class);
        $suffix = ' | Page 12 of 12 | Generated 13 Jul 2026, 12:30';
        $measure = fn (string $text): float => mb_strlen($text, 'UTF-8') * 5;

        $footer = $fitter->fit(str_repeat('VERY-LONG-REPORT-ID-', 10), $suffix, $measure, 400);

        $this->assertLessThanOrEqual(400, $measure($footer));
        $this->assertStringContainsString('...', $footer);
        $this->assertStringEndsWith($suffix, $footer);
    }

    #[Test]
    public function it_enforces_the_configured_pdf_image_count_limit(): void
    {
        config()->set('inspection_reports.pdf.max_images', 1);

        $result = app(InspectionReportPhotoSanitizer::class)->sanitize([
            'photos' => [
                ['description' => 'First', 'url' => self::VALID_PNG],
                ['description' => 'Second', 'url' => self::VALID_PNG],
            ],
        ]);

        $this->assertSame(1, $result->imageCount);
        $this->assertSame(0, $result->unavailableImageCount);
        $this->assertSame(1, $result->omittedImageCount);
        $this->assertFalse($result->record['photos'][0]['imageUnavailable']);
        $this->assertTrue($result->record['photos'][1]['imageOmitted']);
        $this->assertSame('', $result->record['photos'][1]['url']);
        $viewData = app(InspectionReportViewDataBuilder::class)->build($result->record);
        $this->assertCount(1, $viewData['reportEvidence']['photos']);
    }

    #[Test]
    public function it_rejects_oversized_base64_before_it_can_reach_the_image_decoder(): void
    {
        config()->set('inspection_reports.pdf.max_image_bytes', 3);

        $result = app(InspectionReportPhotoSanitizer::class)->sanitize([
            'photos' => [[
                'description' => 'Oversized encoded input',
                'url' => 'data:image/png;base64,'.str_repeat('QUFB', 4),
            ]],
        ]);

        $this->assertSame(0, $result->imageCount);
        $this->assertSame(1, $result->unavailableImageCount);
        $this->assertTrue($result->record['photos'][0]['imageUnavailable']);
    }

    #[Test]
    public function it_preserves_a_footer_that_already_fits(): void
    {
        $fitter = app(PdfFooterTextFitter::class);
        $suffix = ' | Page 1 of 1';

        $this->assertSame(
            'INS-01'.$suffix,
            $fitter->fit('INS-01', $suffix, fn (string $text): int => strlen($text), 100),
        );
    }
}
