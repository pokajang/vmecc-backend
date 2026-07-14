<?php

namespace Tests\Unit;

use App\Services\AiHelper\PdfKnowledgeExtractionResult;
use App\Services\AiHelper\PdfPageExtractionResult;
use App\Services\AiHelper\PdfPageQualityEvaluator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AiHelperPdfPageQualityTest extends TestCase
{
    #[Test]
    public function images_do_not_create_a_warning_when_native_text_is_readable(): void
    {
        $page = app(PdfPageQualityEvaluator::class)->evaluate(
            2,
            str_repeat('Readable emergency response guidance. ', 8),
            ['attempted' => false, 'text' => '', 'error' => null],
            3,
        );

        $this->assertSame(PdfPageExtractionResult::OUTCOME_NATIVE, $page->outcome);
        $this->assertSame([], $page->findings);

        $document = (new PdfKnowledgeExtractionResult($page->text, [$page], 1, 3, 1, 100))->toArray();
        $this->assertSame('ready', $document['quality_status']);
        $this->assertSame([], $document['warnings']);
        $this->assertTrue($document['extraction_complete']);
    }

    #[Test]
    public function successful_ocr_is_a_notice_and_keeps_the_document_complete(): void
    {
        $page = app(PdfPageQualityEvaluator::class)->evaluate(
            1,
            '',
            ['attempted' => true, 'text' => 'Evacuate through the nearest safe exit.', 'error' => null],
            1,
        );
        $document = (new PdfKnowledgeExtractionResult($page->text, [$page], 1, 1, 1, 100))->toArray();

        $this->assertSame(PdfPageExtractionResult::OUTCOME_OCR, $page->outcome);
        $this->assertSame('ready_with_notices', $document['quality_status']);
        $this->assertSame([], $document['warnings']);
        $this->assertSame(1, $document['pages_ocr']);
        $this->assertTrue($document['extraction_complete']);
    }

    #[Test]
    public function a_blank_page_is_informational_but_a_visual_only_page_requires_review(): void
    {
        $evaluator = app(PdfPageQualityEvaluator::class);
        $textPage = $evaluator->evaluate(
            1,
            str_repeat('Readable operational guidance. ', 8),
            ['attempted' => false, 'text' => '', 'error' => null],
            0,
        );
        $blankPage = $evaluator->evaluate(
            2,
            '',
            ['attempted' => true, 'text' => '', 'error' => null, 'has_visual_content' => false],
            0,
        );
        $visualPage = $evaluator->evaluate(
            3,
            '',
            ['attempted' => true, 'text' => '', 'error' => null, 'has_visual_content' => true],
            0,
        );

        $blankDocument = (new PdfKnowledgeExtractionResult(
            $textPage->text,
            [$textPage, $blankPage],
            2,
            0,
            0,
            0,
        ))->toArray();
        $visualDocument = (new PdfKnowledgeExtractionResult(
            $textPage->text,
            [$textPage, $visualPage],
            2,
            2,
            1,
            50,
        ))->toArray();

        $this->assertSame(PdfPageExtractionResult::OUTCOME_BLANK, $blankPage->outcome);
        $this->assertTrue($blankDocument['extraction_complete']);
        $this->assertSame('ready_with_notices', $blankDocument['quality_status']);
        $this->assertSame(PdfPageExtractionResult::OUTCOME_VISUAL_ONLY, $visualPage->outcome);
        $this->assertFalse($visualDocument['extraction_complete']);
        $this->assertSame('review_required', $visualDocument['quality_status']);
        $this->assertSame('VISUAL_ONLY_PAGE', $visualDocument['findings'][0]['code']);
    }

    #[Test]
    public function failed_ocr_on_sparse_native_text_requires_review_without_discarding_the_text(): void
    {
        $page = app(PdfPageQualityEvaluator::class)->evaluate(
            1,
            'Sparse label',
            ['attempted' => true, 'text' => '', 'error' => 'ocr_failed'],
            1,
        );
        $document = (new PdfKnowledgeExtractionResult($page->text, [$page], 1, 1, 1, 100))->toArray();

        $this->assertSame(PdfPageExtractionResult::OUTCOME_UNREADABLE, $page->outcome);
        $this->assertSame('Sparse label', $page->text);
        $this->assertSame('OCR_FAILED', $page->findings[0]['code']);
        $this->assertFalse($document['extraction_complete']);
        $this->assertSame('review_required', $document['quality_status']);
    }

    #[Test]
    public function visually_dense_ocr_labels_do_not_claim_complete_diagram_semantics(): void
    {
        $page = app(PdfPageQualityEvaluator::class)->evaluate(
            2,
            'Figure 3.3',
            [
                'attempted' => true,
                'text' => 'Plant boundary project boundary roads settlements north scale.',
                'error' => null,
                'has_visual_content' => true,
                'visual_content_ratio' => 0.10,
            ],
            0,
        );
        $document = (new PdfKnowledgeExtractionResult($page->text, [$page], 1, 0, 0, 0))->toArray();

        $this->assertSame(PdfPageExtractionResult::OUTCOME_NATIVE_AND_OCR, $page->outcome);
        $this->assertSame('VISUAL_SEMANTICS_REVIEW', $page->findings[1]['code']);
        $this->assertFalse($document['extraction_complete']);
        $this->assertSame('review_required', $document['quality_status']);
    }
}
