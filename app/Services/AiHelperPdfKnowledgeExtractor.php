<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Smalot\PdfParser\Parser;
use Smalot\PdfParser\XObject\Image;
use Symfony\Component\Process\Process;
use Throwable;

class AiHelperPdfKnowledgeExtractor
{
    public function __construct(private readonly Parser $parser)
    {
    }

    /**
     * @return array{
     *     text: string,
     *     pages: array<int, array{number: int, text: string, extraction_mode: string}>,
     *     extraction_mode: string,
     *     extraction_complete: bool,
     *     page_count: int,
     *     image_count: int,
     *     pages_with_images: int,
     *     readable_text_characters: int,
     *     readable_word_count: int,
     *     image_coverage_estimate: int,
     *     warnings: array<int, string>
     * }
     */
    public function extract(string $absolutePath, int $maxCharacters = 0): array
    {
        [$parserText, $pages, $imageCount, $pagesWithImages] = $this->parseWithFallback($absolutePath);
        $pageCount = count($pages) ?: $this->pageCountFromPdfInfo($absolutePath);
        $nativePages = $this->extractNativePages($absolutePath, $pageCount);

        if ($nativePages === []) {
            $nativePages = $pageCount > 0
                ? $this->fallbackPages($parserText, $pageCount)
                : [];
        }

        $warnings = [];
        $resultPages = [];
        $usedOcr = false;
        foreach ($nativePages as $pageNumber => $nativeText) {
            $nativeText = $this->normalizeText($nativeText);
            $ocrText = '';

            if ($this->shouldOcr($nativeText)) {
                $ocrText = $this->extractOcrPage($absolutePath, $pageNumber);
                $usedOcr = $ocrText !== '';
            }

            $text = $this->mergePageText($nativeText, $ocrText);
            if ($text === '') {
                $warnings[] = "Page {$pageNumber} contains no readable text after native extraction and OCR.";
            }

            $resultPages[] = [
                'number' => $pageNumber,
                'text' => $text,
                'extraction_mode' => $nativeText !== '' && $ocrText !== ''
                    ? 'native_and_ocr'
                    : ($ocrText !== '' ? 'ocr' : 'native'),
            ];
        }

        $text = trim(implode("\n\n", array_map(
            static fn (array $page) => $page['text'],
            $resultPages,
        )));
        $readableCharacters = Str::length($text);

        if ($maxCharacters > 0 && $readableCharacters > $maxCharacters) {
            throw new RuntimeException(
                'Extracted text exceeds the configured knowledge limit. The document was not partially indexed.'
            );
        }

        $fallbackImageCount = $this->countRawImageMarkers($absolutePath);
        if ($fallbackImageCount > $imageCount) {
            $imageCount = $fallbackImageCount;
            $pagesWithImages = $pageCount > 0
                ? max($pagesWithImages, min($pageCount, $fallbackImageCount))
                : $pagesWithImages;
        }

        if ($imageCount > 0 && ! $usedOcr) {
            $warnings[] = 'This PDF contains images. Native text was indexed; image-only content requires OCR or visual analysis.';
        }
        if ($usedOcr) {
            $warnings[] = 'OCR text was extracted from pages with insufficient native text.';
        }

        return [
            'text' => $text,
            'pages' => $resultPages,
            'extraction_mode' => $usedOcr
                ? (collect($resultPages)->contains('extraction_mode', 'native_and_ocr') ? 'native_and_ocr' : 'ocr')
                : 'native',
            'extraction_complete' => $pageCount > 0 && count($resultPages) === $pageCount && $text !== '',
            'page_count' => $pageCount,
            'image_count' => $imageCount,
            'pages_with_images' => $pagesWithImages,
            'readable_text_characters' => $readableCharacters,
            'readable_word_count' => $this->wordCount($text),
            'image_coverage_estimate' => $this->imageCoverageEstimate($pageCount, $pagesWithImages, $imageCount),
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    /** @return array{0: string, 1: array<int, mixed>, 2: int, 3: int} */
    private function parseWithFallback(string $absolutePath): array
    {
        try {
            $pdf = $this->parser->parseFile($absolutePath);
            $pages = $pdf->getPages();
            [$imageCount, $pagesWithImages] = $this->countParsedImages($pages);

            return [(string) $pdf->getText(), $pages, $imageCount, $pagesWithImages];
        } catch (Throwable) {
            return ['', [], 0, 0];
        }
    }

    /** @return array<int, string> */
    private function extractNativePages(string $absolutePath, int $pageCount): array
    {
        if ($pageCount < 1) {
            return [];
        }

        $pages = [];
        for ($page = 1; $page <= $pageCount; $page++) {
            $text = $this->run([
                (string) config('ai_helper.knowledge_pdftotext_binary', 'pdftotext'),
                '-f', (string) $page,
                '-l', (string) $page,
                '-layout',
                $absolutePath,
                '-',
            ]);

            if ($text === null) {
                return [];
            }
            $pages[$page] = $text;
        }

        return $pages;
    }

    private function pageCountFromPdfInfo(string $absolutePath): int
    {
        $output = $this->run([
            (string) config('ai_helper.knowledge_pdfinfo_binary', 'pdfinfo'),
            $absolutePath,
        ]);
        if (! is_string($output) || ! preg_match('/^Pages:\s*(\d+)\s*$/mi', $output, $match)) {
            return 0;
        }

        return max(0, (int) $match[1]);
    }

    /** @return array<int, string> */
    private function fallbackPages(string $text, int $pageCount): array
    {
        $pages = array_fill(1, $pageCount, '');
        $pages[1] = $text;

        return $pages;
    }

    private function shouldOcr(string $nativeText): bool
    {
        return (bool) config('ai_helper.knowledge_ocr_enabled', true)
            && ($this->wordCount($nativeText) < 12 || Str::length($nativeText) < 100);
    }

    private function extractOcrPage(string $absolutePath, int $pageNumber): string
    {
        $temporaryDirectory = storage_path('app/ai-helper/knowledge-ocr/'.Str::uuid());
        File::ensureDirectoryExists($temporaryDirectory);
        $prefix = $temporaryDirectory.'/page';

        try {
            $rendered = $this->run([
                (string) config('ai_helper.knowledge_pdftoppm_binary', 'pdftoppm'),
                '-f', (string) $pageNumber,
                '-l', (string) $pageNumber,
                '-r', (string) max(150, (int) config('ai_helper.knowledge_ocr_dpi', 300)),
                '-png',
                '-singlefile',
                $absolutePath,
                $prefix,
            ]);
            $imagePath = $prefix.'.png';
            if ($rendered === null || ! File::exists($imagePath)) {
                return '';
            }

            return $this->normalizeText($this->run([
                (string) config('ai_helper.knowledge_tesseract_binary', 'tesseract'),
                $imagePath,
                'stdout',
                '-l', (string) config('ai_helper.knowledge_ocr_languages', 'eng+msa'),
            ]) ?? '');
        } finally {
            File::deleteDirectory($temporaryDirectory);
        }
    }

    private function run(array $command): ?string
    {
        try {
            $process = new Process($command);
            $process->setTimeout(max(10, (int) config('ai_helper.knowledge_ocr_timeout_seconds', 120)));
            $process->run();

            return $process->isSuccessful() ? $process->getOutput() : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function mergePageText(string $nativeText, string $ocrText): string
    {
        if ($nativeText === '') {
            return $ocrText;
        }
        if ($ocrText === '' || str_contains(Str::lower($nativeText), Str::lower(Str::limit($ocrText, 80, '')))) {
            return $nativeText;
        }

        return trim("{$nativeText}\n\n{$ocrText}");
    }

    private function normalizeText(string $text): string
    {
        $text = preg_replace("/[ \t]+/", ' ', $text) ?? '';
        $text = preg_replace("/\R{3,}/", "\n\n", $text) ?? '';

        return trim($text);
    }

    /** @param array<int, mixed> $pages */
    private function countParsedImages(array $pages): array
    {
        $imageCount = 0;
        $pagesWithImages = 0;

        foreach ($pages as $page) {
            if (! method_exists($page, 'getXObjects')) {
                continue;
            }

            $pageImages = [];
            foreach ($page->getXObjects() as $xobject) {
                if ($xobject instanceof Image) {
                    $pageImages[spl_object_id($xobject)] = true;
                }
            }
            if ($pageImages !== []) {
                $pagesWithImages++;
                $imageCount += count($pageImages);
            }
        }

        return [$imageCount, $pagesWithImages];
    }

    private function countRawImageMarkers(string $absolutePath): int
    {
        $raw = @file_get_contents($absolutePath);
        if (! is_string($raw) || $raw === '') {
            return 0;
        }

        $xobjectImages = preg_match_all('/\/Subtype\s*\/Image\b/i', $raw) ?: 0;
        $inlineImages = preg_match_all('/\bBI\b[\s\S]{0,2000}?\bID\b[\s\S]{0,60000}?\bEI\b/', $raw) ?: 0;

        return max(0, (int) $xobjectImages + (int) $inlineImages);
    }

    private function wordCount(string $text): int
    {
        $words = preg_split('/[^\pL\pN]+/u', $text) ?: [];

        return count(array_filter($words, static fn (string $word) => $word !== ''));
    }

    private function imageCoverageEstimate(int $pageCount, int $pagesWithImages, int $imageCount): int
    {
        if ($pageCount <= 0 || $imageCount <= 0) {
            return 0;
        }

        return min(100, max(1, (int) round(($pagesWithImages / $pageCount) * 100)));
    }
}
