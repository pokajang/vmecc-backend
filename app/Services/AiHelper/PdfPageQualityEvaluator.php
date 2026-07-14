<?php

namespace App\Services\AiHelper;

use Illuminate\Support\Str;

final class PdfPageQualityEvaluator
{
    public function needsOcr(string $nativeText): bool
    {
        return (bool) config('ai_helper.knowledge_ocr_enabled', true)
            && ($this->wordCount($nativeText) < max(1, (int) config('ai_helper.knowledge_ocr_min_native_words', 12))
                || Str::length($nativeText) < max(1, (int) config('ai_helper.knowledge_ocr_min_native_characters', 100)));
    }

    /**
     * @param array{
     *     attempted: bool,
     *     text: string,
     *     error: ?string,
     *     has_visual_content?: ?bool,
     *     visual_content_ratio?: ?float
     * } $ocr
     */
    public function evaluate(int $pageNumber, string $nativeText, array $ocr, int $imageCount): PdfPageExtractionResult
    {
        $nativeText = trim($nativeText);
        $ocrText = trim((string) ($ocr['text'] ?? ''));
        $ocrAttempted = (bool) ($ocr['attempted'] ?? false);
        $ocrError = trim((string) ($ocr['error'] ?? ''));
        $hasVisualContent = $ocr['has_visual_content'] ?? null;
        $visualContentRatio = isset($ocr['visual_content_ratio'])
            ? (float) $ocr['visual_content_ratio']
            : null;
        $findings = [];

        if ($ocrText !== '') {
            $text = $this->mergeText($nativeText, $ocrText);
            $outcome = $nativeText === ''
                ? PdfPageExtractionResult::OUTCOME_OCR
                : PdfPageExtractionResult::OUTCOME_NATIVE_AND_OCR;
            $findings[] = $this->finding('notice', 'OCR_APPLIED', $pageNumber, "OCR supplemented page {$pageNumber} because native text was sparse.");
            if ($this->requiresVisualSemanticsReview($ocrText, $visualContentRatio)) {
                $findings[] = $this->finding(
                    'warning',
                    'VISUAL_SEMANTICS_REVIEW',
                    $pageNumber,
                    "Page {$pageNumber} is visually dense. OCR indexed its labels but may not capture diagram or map relationships; add companion text before activation.",
                );
            }
        } elseif ($nativeText !== '') {
            $text = $nativeText;
            if ($ocrError !== '') {
                $outcome = PdfPageExtractionResult::OUTCOME_UNREADABLE;
                $code = $ocrError === 'document_timeout' ? 'OCR_TIMEOUT' : 'OCR_FAILED';
                $message = $ocrError === 'document_timeout'
                    ? "OCR exceeded the document time budget while verifying page {$pageNumber}."
                    : "OCR could not verify sparse native text on page {$pageNumber}.";
                $findings[] = $this->finding('warning', $code, $pageNumber, $message);
            } else {
                $outcome = PdfPageExtractionResult::OUTCOME_NATIVE;
            }
        } elseif ($ocrError !== '') {
            $text = '';
            $outcome = PdfPageExtractionResult::OUTCOME_ERROR;
            $code = $ocrError === 'document_timeout' ? 'OCR_TIMEOUT' : 'OCR_FAILED';
            $message = $ocrError === 'document_timeout'
                ? "OCR exceeded the document time budget on page {$pageNumber}."
                : "Page {$pageNumber} could not be processed by OCR.";
            $findings[] = $this->finding('error', $code, $pageNumber, $message);
        } elseif ($imageCount > 0 || $hasVisualContent === true) {
            $text = '';
            $outcome = PdfPageExtractionResult::OUTCOME_VISUAL_ONLY;
            $findings[] = $this->finding('warning', 'VISUAL_ONLY_PAGE', $pageNumber, "Page {$pageNumber} contains visual content but no readable text after OCR.");
        } elseif ($hasVisualContent === false) {
            $text = '';
            $outcome = PdfPageExtractionResult::OUTCOME_BLANK;
            $findings[] = $this->finding('notice', 'BLANK_PAGE', $pageNumber, "Page {$pageNumber} appears to be blank.");
        } else {
            $text = '';
            $outcome = PdfPageExtractionResult::OUTCOME_UNREADABLE;
            $findings[] = $this->finding('warning', 'PAGE_CONTENT_UNDETERMINED', $pageNumber, "Page {$pageNumber} has no readable text and could not be confirmed as blank.");
        }

        return new PdfPageExtractionResult(
            number: $pageNumber,
            text: $text,
            outcome: $outcome,
            nativeCharacters: Str::length($nativeText),
            nativeWords: $this->wordCount($nativeText),
            ocrCharacters: Str::length($ocrText),
            ocrWords: $this->wordCount($ocrText),
            imageCount: max(0, $imageCount),
            ocrAttempted: $ocrAttempted,
            ocrSucceeded: $ocrText !== '',
            findings: $findings,
        );
    }

    private function mergeText(string $nativeText, string $ocrText): string
    {
        if ($nativeText === '') {
            return $ocrText;
        }
        if ($ocrText === '' || str_contains(Str::lower($nativeText), Str::lower(Str::limit($ocrText, 80, '')))) {
            return $nativeText;
        }

        return trim("{$nativeText}\n\n{$ocrText}");
    }

    private function wordCount(string $text): int
    {
        $words = preg_split('/[^\pL\pN]+/u', $text) ?: [];

        return count(array_filter($words, static fn (string $word) => $word !== ''));
    }

    private function requiresVisualSemanticsReview(string $ocrText, ?float $visualContentRatio): bool
    {
        if ($visualContentRatio === null) {
            return false;
        }

        $minimumRatio = max(0.001, min(0.9, (float) config(
            'ai_helper.knowledge_visual_review_minimum_ratio',
            0.05,
        )));
        $maximumWords = max(1, (int) config(
            'ai_helper.knowledge_visual_review_maximum_ocr_words',
            120,
        ));

        return $visualContentRatio >= $minimumRatio
            && $this->wordCount($ocrText) <= $maximumWords;
    }

    private function finding(string $severity, string $code, int $page, string $message): array
    {
        return compact('severity', 'code', 'page', 'message');
    }
}
