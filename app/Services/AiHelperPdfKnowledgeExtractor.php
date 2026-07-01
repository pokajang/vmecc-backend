<?php

namespace App\Services;

use Illuminate\Support\Str;
use Smalot\PdfParser\Parser;
use Smalot\PdfParser\XObject\Image;
use Throwable;

class AiHelperPdfKnowledgeExtractor
{
    public function __construct(private readonly Parser $parser)
    {
    }

    /**
     * @return array{
     *     text: string,
     *     page_count: int,
     *     image_count: int,
     *     pages_with_images: int,
     *     readable_text_characters: int,
     *     readable_word_count: int,
     *     image_coverage_estimate: int,
     *     warnings: array<int, string>
     * }
     */
    public function extract(string $absolutePath, int $maxCharacters): array
    {
        try {
            $pdf = $this->parser->parseFile($absolutePath);
            $pages = $pdf->getPages();
            $text = (string) $pdf->getText();
        } catch (Throwable) {
            return $this->emptyResult();
        }

        $pageCount = count($pages);
        [$imageCount, $pagesWithImages] = $this->countParsedImages($pages);
        $fallbackImageCount = $this->countRawImageMarkers($absolutePath);

        if ($fallbackImageCount > $imageCount) {
            $imageCount = $fallbackImageCount;
            $pagesWithImages = $pageCount > 0
                ? max($pagesWithImages, min($pageCount, $fallbackImageCount))
                : $pagesWithImages;
        }

        $text = preg_replace("/[ \t]+/", ' ', $text) ?? '';
        $text = preg_replace("/\R{3,}/", "\n\n", $text) ?? '';
        $text = trim($text);
        $readableCharacters = Str::length($text);
        $readableWords = $this->wordCount($text);
        $imageCoverage = $this->imageCoverageEstimate($pageCount, $pagesWithImages, $imageCount);
        $warnings = $imageCount > 0
            ? ['This PDF contains images. Ask AI used only the readable text.']
            : [];

        return [
            'text' => $text === '' ? '' : Str::limit($text, max(1, $maxCharacters), ''),
            'page_count' => $pageCount,
            'image_count' => $imageCount,
            'pages_with_images' => $pagesWithImages,
            'readable_text_characters' => $readableCharacters,
            'readable_word_count' => $readableWords,
            'image_coverage_estimate' => $imageCoverage,
            'warnings' => $warnings,
        ];
    }

    /**
     * @param array<int, mixed> $pages
     *
     * @return array{0: int, 1: int}
     */
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
                if (! $xobject instanceof Image) {
                    continue;
                }

                $pageImages[spl_object_id($xobject)] = true;
            }

            $count = count($pageImages);
            if ($count > 0) {
                $pagesWithImages++;
                $imageCount += $count;
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
        $inlineImages = preg_match_all('/\bBI\b[\s\S]{0,2000}?\bID\b[\s\S]{0,200000}?\bEI\b/', $raw) ?: 0;

        return max(0, (int) $xobjectImages + (int) $inlineImages);
    }

    private function wordCount(string $text): int
    {
        if ($text === '') {
            return 0;
        }

        $words = preg_split('/[^\pL\pN]+/u', $text) ?: [];

        return count(array_filter($words, fn (string $word) => $word !== ''));
    }

    private function imageCoverageEstimate(int $pageCount, int $pagesWithImages, int $imageCount): int
    {
        if ($pageCount <= 0 || $imageCount <= 0) {
            return 0;
        }

        if ($pagesWithImages >= $pageCount) {
            return 100;
        }

        return min(100, max(1, (int) round(($pagesWithImages / $pageCount) * 100)));
    }

    /**
     * @return array{
     *     text: string,
     *     page_count: int,
     *     image_count: int,
     *     pages_with_images: int,
     *     readable_text_characters: int,
     *     readable_word_count: int,
     *     image_coverage_estimate: int,
     *     warnings: array<int, string>
     * }
     */
    private function emptyResult(): array
    {
        return [
            'text' => '',
            'page_count' => 0,
            'image_count' => 0,
            'pages_with_images' => 0,
            'readable_text_characters' => 0,
            'readable_word_count' => 0,
            'image_coverage_estimate' => 0,
            'warnings' => [],
        ];
    }
}
