<?php

namespace Tests\Feature;

use App\Services\InspectionReports\InspectionReportPdfRenderer;
use Carbon\Carbon;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Group;
use Smalot\PdfParser\Parser;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\Fixtures\InspectionReportAuditScenarios;
use Tests\TestCase;

#[Group('pdf-audit')]
class InspectionReportVisualAuditTest extends TestCase
{
    public function test_all_inspection_types_render_visual_audit_artifacts(): void
    {
        if (! filter_var(env('INSPECTION_PDF_VISUAL_AUDIT', false), FILTER_VALIDATE_BOOL)) {
            $this->markTestSkipped('Set INSPECTION_PDF_VISUAL_AUDIT=1 to generate visual audit artifacts.');
        }

        $pdftoppm = (new ExecutableFinder)->find('pdftoppm');
        $this->assertNotNull($pdftoppm, 'Poppler pdftoppm is required for the visual PDF audit.');

        Carbon::setTestNow('2026-07-13 12:30:00');
        $outputDirectory = base_path('output/pdf/inspection-report-audit');
        File::ensureDirectoryExists($outputDirectory);
        File::cleanDirectory($outputDirectory);
        $baselinePath = base_path('tests/Fixtures/inspection-report-visual-baseline.json');
        $updateBaseline = filter_var(env('INSPECTION_PDF_UPDATE_BASELINE', false), FILTER_VALIDATE_BOOL);
        $baseline = ! $updateBaseline && File::exists($baselinePath)
            ? json_decode(File::get($baselinePath), true, flags: JSON_THROW_ON_ERROR)
            : [];
        $manifest = [];
        $scenarios = InspectionReportAuditScenarios::all();
        $this->assertPhotoOrientation($scenarios['general']['photos'][0], 'landscape');
        $this->assertPhotoOrientation($scenarios['general']['photos'][1], 'portrait');

        try {
            foreach ($scenarios as $type => $record) {
                $startedAt = microtime(true);
                $pdf = app(InspectionReportPdfRenderer::class)->render($record);
                $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
                $pdfPath = $outputDirectory.'/'.$type.'.pdf';
                File::put($pdfPath, $pdf);

                $pages = (new Parser)->parseContent($pdf)->getPages();
                $text = collect($pages)->map->getText()->implode("\n");
                $normalizedText = mb_strtoupper($text, 'UTF-8');
                $this->assertNotEmpty($pages, "{$type} produced no PDF pages.");
                $this->assertLessThan(10 * 1024 * 1024, strlen($pdf), "{$type} PDF exceeded 10 MB.");
                $this->assertLessThan(20_000, $durationMs, "{$type} PDF exceeded the audit render budget.");
                $this->assertStringContainsString('WORKFLOW SIGN-OFFS', $normalizedText);
                if ($type === 'hse-v2') {
                    $this->assertStringNotContainsString('ADDITIONAL REPORT EVIDENCE', $normalizedText);
                    $this->assertStringContainsString('UNSAFE CONDITION', $normalizedText);
                    $this->assertStringContainsString('STOPPED ACCESS', $normalizedText);
                    $this->assertStringNotContainsString('STALE UNSAFE-ACT', $normalizedText);
                    $this->assertStringNotContainsString('CRITICAL', $normalizedText);
                    $this->assertStringNotContainsString('LEGACY CORRECTIVE', $normalizedText);
                    $this->assertStringNotContainsString('LEGACY RESPONSIBLE', $normalizedText);
                } else {
                    $this->assertStringContainsString('ADDITIONAL REPORT EVIDENCE', $normalizedText);
                    $this->assertLessThan(
                        strpos($normalizedText, 'WORKFLOW SIGN-OFFS'),
                        strpos($normalizedText, 'ADDITIONAL REPORT EVIDENCE'),
                    );
                }

                foreach ($pages as $pageNumber => $page) {
                    $this->assertStringContainsString(
                        'Page '.($pageNumber + 1).' of '.count($pages),
                        $page->getText(),
                        "{$type} page footer is missing or incorrect.",
                    );
                }

                $prefix = $outputDirectory.'/'.$type;
                $process = new Process([$pdftoppm, '-q', '-r', '144', '-png', $pdfPath, $prefix]);
                $process->setTimeout(60)->mustRun();
                $pngs = glob($prefix.'-*.png') ?: [];
                $this->assertCount(count($pages), $pngs, "{$type} PNG page count differs from its PDF.");
                $pngPages = [];
                foreach ($pngs as $pageIndex => $png) {
                    $dimensions = getimagesize($png);
                    $this->assertNotFalse($dimensions, "{$png} is not a readable PNG.");
                    $this->assertGreaterThan(1000, $dimensions[0]);
                    $this->assertGreaterThan(1400, $dimensions[1]);
                    $averageHash = $this->averageHash($png);
                    $pngPages[] = [
                        'file' => basename($png),
                        'width' => $dimensions[0],
                        'height' => $dimensions[1],
                        'averageHash' => $averageHash,
                    ];

                    if (isset($baseline[$type]['pngPages'][$pageIndex])) {
                        $expected = $baseline[$type]['pngPages'][$pageIndex];
                        $this->assertSame($expected['width'], $dimensions[0], "{$type} page width changed.");
                        $this->assertSame($expected['height'], $dimensions[1], "{$type} page height changed.");
                        $this->assertLessThanOrEqual(
                            8,
                            $this->hashDistance((string) $expected['averageHash'], $averageHash),
                            "{$type} page ".($pageIndex + 1).' differs materially from its visual baseline.',
                        );
                    }
                }

                if (isset($baseline[$type]['pages'])) {
                    $this->assertSame($baseline[$type]['pages'], count($pages), "{$type} page count changed.");
                }

                $manifest[$type] = [
                    'pages' => count($pages),
                    'pdfBytes' => strlen($pdf),
                    'renderDurationMs' => $durationMs,
                    'pdfSha256' => hash('sha256', $pdf),
                    'pngPages' => $pngPages,
                ];
            }
        } finally {
            Carbon::setTestNow();
        }

        File::put(
            $outputDirectory.'/manifest.json',
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL,
        );
        if ($updateBaseline) {
            $candidate = [];
            foreach ($manifest as $type => $result) {
                $candidate[$type] = [
                    'pages' => $result['pages'],
                    'pngPages' => array_map(
                        fn (array $page): array => [
                            'width' => $page['width'],
                            'height' => $page['height'],
                            'averageHash' => $page['averageHash'],
                        ],
                        $result['pngPages'],
                    ),
                ];
            }
            File::put(
                $outputDirectory.'/visual-baseline-candidate.json',
                json_encode($candidate, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL,
            );
        }
    }

    private function averageHash(string $path): string
    {
        $source = imagecreatefrompng($path);
        $this->assertNotFalse($source, "Unable to decode {$path} for visual comparison.");
        $sample = imagecreatetruecolor(8, 8);
        imagecopyresampled($sample, $source, 0, 0, 0, 0, 8, 8, imagesx($source), imagesy($source));
        $values = [];
        for ($y = 0; $y < 8; $y++) {
            for ($x = 0; $x < 8; $x++) {
                $color = imagecolorat($sample, $x, $y);
                $red = ($color >> 16) & 0xFF;
                $green = ($color >> 8) & 0xFF;
                $blue = $color & 0xFF;
                $values[] = (int) round(($red * 0.299) + ($green * 0.587) + ($blue * 0.114));
            }
        }
        imagedestroy($sample);
        imagedestroy($source);

        $average = array_sum($values) / count($values);
        $bits = implode('', array_map(fn (int $value): string => $value >= $average ? '1' : '0', $values));
        $hash = '';
        for ($offset = 0; $offset < 64; $offset += 4) {
            $hash .= dechex(bindec(substr($bits, $offset, 4)));
        }

        return $hash;
    }

    private function hashDistance(string $left, string $right): int
    {
        if (strlen($left) !== strlen($right)) {
            return PHP_INT_MAX;
        }

        $distance = 0;
        for ($index = 0; $index < strlen($left); $index++) {
            $distance += substr_count(decbin(hexdec($left[$index]) ^ hexdec($right[$index])), '1');
        }

        return $distance;
    }

    private function assertPhotoOrientation(array $photo, string $orientation): void
    {
        $url = (string) ($photo['url'] ?? '');
        $encoded = str_contains($url, ',') ? substr($url, strpos($url, ',') + 1) : '';
        $bytes = base64_decode($encoded, true);
        $dimensions = is_string($bytes) ? getimagesizefromstring($bytes) : false;
        $this->assertNotFalse($dimensions, "The {$orientation} audit photo is invalid.");
        if ($orientation === 'portrait') {
            $this->assertGreaterThan($dimensions[0], $dimensions[1]);
        } else {
            $this->assertGreaterThan($dimensions[1], $dimensions[0]);
        }
    }
}
