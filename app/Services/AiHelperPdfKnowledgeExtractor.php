<?php

namespace App\Services;

use App\Services\AiHelper\PdfKnowledgeExtractionResult;
use App\Services\AiHelper\PdfOcrService;
use App\Services\AiHelper\PdfPageQualityEvaluator;
use Illuminate\Support\Str;
use RuntimeException;
use Smalot\PdfParser\Parser;
use Smalot\PdfParser\XObject\Image;
use Symfony\Component\Process\Process;
use Throwable;

class AiHelperPdfKnowledgeExtractor
{
    public function __construct(
        private readonly Parser $parser,
        private readonly PdfPageQualityEvaluator $qualityEvaluator,
        private readonly PdfOcrService $ocrService,
    ) {}

    /** @return PdfKnowledgeExtractionResult */
    public function extract(string $absolutePath, int $maxCharacters = 0)
    {
        [$parserText, $pages, $imageCount, $pagesWithImages, $pageImageCounts] = $this->parseWithFallback($absolutePath);
        $pageCount = count($pages) ?: $this->pageCountFromPdfInfo($absolutePath);
        $maximumPages = max(1, (int) config('ai_helper.knowledge_max_pdf_pages', 250));
        if ($pageCount > $maximumPages) {
            throw new RuntimeException("PDF contains {$pageCount} pages; the configured maximum is {$maximumPages}.");
        }
        $nativePages = $this->extractNativePages($absolutePath, $pageCount);

        if ($nativePages === []) {
            $nativePages = $pageCount > 0
                ? $this->fallbackPages($parserText, $pageCount)
                : [];
        }

        $ocrPageCount = collect($nativePages)
            ->filter(fn (string $nativeText) => $this->qualityEvaluator->needsOcr($this->normalizeText($nativeText)))
            ->count();
        $maximumOcrPages = max(0, (int) config('ai_helper.knowledge_max_ocr_pages_per_document', 20));
        if ($maximumOcrPages > 0 && $ocrPageCount > $maximumOcrPages) {
            throw new RuntimeException(
                "PDF requires OCR on {$ocrPageCount} pages; the configured maximum is {$maximumOcrPages}."
            );
        }

        $ocrDeadline = microtime(true) + max(30, (int) config(
            'ai_helper.knowledge_ocr_document_timeout_seconds',
            600,
        ));
        $resultPages = [];
        foreach ($nativePages as $pageNumber => $nativeText) {
            $nativeText = $this->normalizeText($nativeText);
            $ocr = $this->qualityEvaluator->needsOcr($nativeText)
                ? $this->ocrService->extractPage($absolutePath, $pageNumber, $ocrDeadline)
                : [
                    'attempted' => false,
                    'text' => '',
                    'error' => null,
                    'has_visual_content' => null,
                    'visual_content_ratio' => null,
                ];
            $resultPages[] = $this->qualityEvaluator->evaluate(
                $pageNumber,
                $nativeText,
                $ocr,
                (int) ($pageImageCounts[$pageNumber] ?? 0),
            );
        }

        $text = trim(implode("\n\n", array_map(
            static fn ($page) => $page->text,
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
        }

        return new PdfKnowledgeExtractionResult(
            text: $text,
            pages: $resultPages,
            pageCount: $pageCount,
            imageCount: $imageCount,
            pagesWithImages: $pagesWithImages,
            imageCoverageEstimate: $this->imageCoverageEstimate($pageCount, $pagesWithImages, $imageCount),
        );
    }

    /** @return array{0: string, 1: array<int, mixed>, 2: int, 3: int, 4: array<int, int>} */
    private function parseWithFallback(string $absolutePath): array
    {
        try {
            $pdf = $this->parser->parseFile($absolutePath);
            $pages = $pdf->getPages();
            [$imageCount, $pagesWithImages, $pageImageCounts] = $this->countParsedImages($pages);

            return [(string) $pdf->getText(), $pages, $imageCount, $pagesWithImages, $pageImageCounts];
        } catch (Throwable) {
            return ['', [], 0, 0, []];
        }
    }

    /** @return array<int, string> */
    private function extractNativePages(string $absolutePath, int $pageCount): array
    {
        if ($pageCount < 1) {
            return [];
        }

        $documentText = $this->run([
            (string) config('ai_helper.knowledge_pdftotext_binary', 'pdftotext'),
            '-layout',
            $absolutePath,
            '-',
        ]);
        if ($documentText !== null) {
            $segments = preg_split('/\f/u', $documentText) ?: [];
            while (count($segments) > $pageCount && trim((string) end($segments)) === '') {
                array_pop($segments);
            }
            if (count($segments) >= $pageCount) {
                $pages = [];
                for ($page = 1; $page <= $pageCount; $page++) {
                    $pages[$page] = (string) ($segments[$page - 1] ?? '');
                }

                return $pages;
            }
        }

        return $this->extractNativePagesIndividually($absolutePath, $pageCount);
    }

    /** @return array<int, string> */
    private function extractNativePagesIndividually(string $absolutePath, int $pageCount): array
    {
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
        $pageImageCounts = [];

        foreach (array_values($pages) as $index => $page) {
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
                $pageImageCounts[$index + 1] = count($pageImages);
            }
        }

        return [$imageCount, $pagesWithImages, $pageImageCounts];
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

    private function imageCoverageEstimate(int $pageCount, int $pagesWithImages, int $imageCount): int
    {
        if ($pageCount <= 0 || $imageCount <= 0) {
            return 0;
        }

        return min(100, max(1, (int) round(($pagesWithImages / $pageCount) * 100)));
    }
}
