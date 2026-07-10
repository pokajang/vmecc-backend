<?php

namespace App\Jobs;

use App\Services\AiHelperKnowledgeProcessingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ProcessAiHelperKnowledgeEntry implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [30, 120];

    public function __construct(
        private readonly int $knowledgeEntryId,
        private readonly ?string $ingestionRunId = null,
    )
    {
    }

    public function handle(AiHelperKnowledgeProcessingService $processor): void
    {
        $processor->process($this->knowledgeEntryId, $this->ingestionRunId);
    }

    public function failed(Throwable $exception): void
    {
        app(AiHelperKnowledgeProcessingService::class)->markFailedForRun(
            $this->knowledgeEntryId,
            $this->ingestionRunId,
            'Knowledge ingestion failed after retrying: '.$exception->getMessage(),
        );
    }
}
