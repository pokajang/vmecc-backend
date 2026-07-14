<?php

namespace Tests\Unit;

use App\Jobs\ProcessAiHelperKnowledgeEntry;
use App\Services\AiHelper\PdfKnowledgeExtractionResult;
use App\Services\AiHelper\PdfPageExtractionResult;
use App\Services\AiHelper\PdfPageQualityEvaluator;
use App\Services\AiHelperKnowledgeRuntimeService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AiHelperKnowledgeRuntimeHardeningTest extends TestCase
{
    #[Test]
    public function production_runtime_rejects_a_queue_retry_window_shorter_than_the_job_timeout(): void
    {
        config([
            'app.env' => 'production',
            'queue.default' => 'database',
            'queue.connections.database.retry_after' => 90,
            'ai_helper.knowledge_job_timeout_seconds' => 900,
            'ai_helper.knowledge_ocr_languages' => 'eng',
        ]);

        $diagnostics = app(AiHelperKnowledgeRuntimeService::class)->diagnostics();

        $this->assertFalse($diagnostics['queue_ready']);
        $this->assertFalse($diagnostics['ready']);
        $this->assertSame(90, $diagnostics['queue_retry_after']);
        $this->assertSame(900, $diagnostics['job_timeout']);
    }

    #[Test]
    public function knowledge_job_uses_the_configured_timeout_and_fails_on_timeout(): void
    {
        config(['ai_helper.knowledge_job_timeout_seconds' => 750]);

        $job = new ProcessAiHelperKnowledgeEntry(123, 'run-id');

        $this->assertSame(750, $job->timeout);
        $this->assertTrue($job->failOnTimeout);
    }

    #[Test]
    public function an_ocr_empty_page_is_not_assumed_blank_when_visual_detection_is_unavailable(): void
    {
        $page = app(PdfPageQualityEvaluator::class)->evaluate(
            1,
            '',
            ['attempted' => true, 'text' => '', 'error' => null, 'has_visual_content' => null],
            0,
        );
        $document = (new PdfKnowledgeExtractionResult('', [$page], 1, 0, 0, 0))->toArray();

        $this->assertSame(PdfPageExtractionResult::OUTCOME_UNREADABLE, $page->outcome);
        $this->assertSame('PAGE_CONTENT_UNDETERMINED', $page->findings[0]['code']);
        $this->assertFalse($document['extraction_complete']);
        $this->assertSame('failed', $document['quality_status']);
    }
}
