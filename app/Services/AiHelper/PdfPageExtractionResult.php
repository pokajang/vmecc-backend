<?php

namespace App\Services\AiHelper;

final class PdfPageExtractionResult
{
    public const OUTCOME_NATIVE = 'native';

    public const OUTCOME_OCR = 'ocr';

    public const OUTCOME_NATIVE_AND_OCR = 'native_and_ocr';

    public const OUTCOME_BLANK = 'blank';

    public const OUTCOME_VISUAL_ONLY = 'visual_only';

    public const OUTCOME_UNREADABLE = 'unreadable';

    public const OUTCOME_ERROR = 'error';

    /** @param array<int, array{severity: string, code: string, page: int, message: string}> $findings */
    public function __construct(
        public readonly int $number,
        public readonly string $text,
        public readonly string $outcome,
        public readonly int $nativeCharacters,
        public readonly int $nativeWords,
        public readonly int $ocrCharacters,
        public readonly int $ocrWords,
        public readonly int $imageCount,
        public readonly bool $ocrAttempted,
        public readonly bool $ocrSucceeded,
        public readonly array $findings = [],
    ) {}

    public function extractionMode(): string
    {
        return in_array($this->outcome, [self::OUTCOME_OCR, self::OUTCOME_NATIVE_AND_OCR], true)
            ? $this->outcome
            : self::OUTCOME_NATIVE;
    }

    public function isIndexed(): bool
    {
        return trim($this->text) !== '';
    }

    public function hasContentGap(): bool
    {
        if (in_array($this->outcome, [
            self::OUTCOME_VISUAL_ONLY,
            self::OUTCOME_UNREADABLE,
            self::OUTCOME_ERROR,
        ], true)) {
            return true;
        }

        return collect($this->findings)->contains(
            fn (array $finding) => in_array($finding['severity'] ?? null, ['warning', 'error'], true)
        );
    }

    public function toArray(): array
    {
        return [
            'number' => $this->number,
            'text' => $this->text,
            'extraction_mode' => $this->extractionMode(),
            'outcome' => $this->outcome,
            'native_character_count' => $this->nativeCharacters,
            'native_word_count' => $this->nativeWords,
            'ocr_character_count' => $this->ocrCharacters,
            'ocr_word_count' => $this->ocrWords,
            'image_count' => $this->imageCount,
            'ocr_attempted' => $this->ocrAttempted,
            'ocr_succeeded' => $this->ocrSucceeded,
            'findings' => $this->findings,
        ];
    }
}
