<?php

namespace App\Services\AiHelper;

final class PdfKnowledgeExtractionResult
{
    /** @param array<int, PdfPageExtractionResult> $pages */
    public function __construct(
        public readonly string $text,
        public readonly array $pages,
        public readonly int $pageCount,
        public readonly int $imageCount,
        public readonly int $pagesWithImages,
        public readonly int $imageCoverageEstimate,
    ) {}

    public function toArray(): array
    {
        $findings = collect($this->pages)
            ->flatMap(fn (PdfPageExtractionResult $page) => $page->findings)
            ->values()
            ->all();
        $hasOcr = collect($this->pages)->contains(
            fn (PdfPageExtractionResult $page) => in_array($page->outcome, [
                PdfPageExtractionResult::OUTCOME_OCR,
                PdfPageExtractionResult::OUTCOME_NATIVE_AND_OCR,
            ], true)
        );
        $hasNativeAndOcr = collect($this->pages)->contains(
            fn (PdfPageExtractionResult $page) => $page->outcome === PdfPageExtractionResult::OUTCOME_NATIVE_AND_OCR
        );
        $hasNative = collect($this->pages)->contains(
            fn (PdfPageExtractionResult $page) => in_array($page->outcome, [
                PdfPageExtractionResult::OUTCOME_NATIVE,
                PdfPageExtractionResult::OUTCOME_NATIVE_AND_OCR,
            ], true)
        );
        $hasContentGap = collect($this->pages)->contains(
            fn (PdfPageExtractionResult $page) => $page->hasContentGap()
        );
        $hasNotices = collect($findings)->contains(fn (array $finding) => $finding['severity'] === 'notice');
        $extractionComplete = $this->pageCount > 0
            && count($this->pages) === $this->pageCount
            && trim($this->text) !== ''
            && ! $hasContentGap;
        $qualityStatus = trim($this->text) === ''
            ? 'failed'
            : ($hasContentGap ? 'review_required' : ($hasNotices ? 'ready_with_notices' : 'ready'));

        $outcomes = collect($this->pages)->countBy(fn (PdfPageExtractionResult $page) => $page->outcome);

        return [
            'text' => $this->text,
            'pages' => array_map(fn (PdfPageExtractionResult $page) => $page->toArray(), $this->pages),
            'extraction_mode' => $hasOcr ? (($hasNativeAndOcr || $hasNative) ? 'native_and_ocr' : 'ocr') : 'native',
            'extraction_complete' => $extractionComplete,
            'quality_status' => $qualityStatus,
            'page_count' => $this->pageCount,
            'image_count' => $this->imageCount,
            'pages_with_images' => $this->pagesWithImages,
            'readable_text_characters' => mb_strlen($this->text),
            'readable_word_count' => $this->wordCount($this->text),
            'image_coverage_estimate' => $this->imageCoverageEstimate,
            'findings' => $findings,
            'warnings' => collect($findings)
                ->filter(fn (array $finding) => in_array($finding['severity'], ['warning', 'error'], true))
                ->pluck('message')
                ->unique()
                ->values()
                ->all(),
            'pages_indexed' => collect($this->pages)->filter->isIndexed()->count(),
            'pages_native' => (int) ($outcomes[PdfPageExtractionResult::OUTCOME_NATIVE] ?? 0),
            'pages_ocr' => (int) ($outcomes[PdfPageExtractionResult::OUTCOME_OCR] ?? 0)
                + (int) ($outcomes[PdfPageExtractionResult::OUTCOME_NATIVE_AND_OCR] ?? 0),
            'pages_blank' => (int) ($outcomes[PdfPageExtractionResult::OUTCOME_BLANK] ?? 0),
            'pages_visual_only' => (int) ($outcomes[PdfPageExtractionResult::OUTCOME_VISUAL_ONLY] ?? 0),
            'pages_unreadable' => (int) ($outcomes[PdfPageExtractionResult::OUTCOME_UNREADABLE] ?? 0)
                + (int) ($outcomes[PdfPageExtractionResult::OUTCOME_ERROR] ?? 0),
        ];
    }

    private function wordCount(string $text): int
    {
        $words = preg_split('/[^\pL\pN]+/u', $text) ?: [];

        return count(array_filter($words, static fn (string $word) => $word !== ''));
    }
}
