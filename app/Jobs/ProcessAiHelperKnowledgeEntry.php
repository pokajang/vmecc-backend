<?php

namespace App\Jobs;

use App\Services\AiHelperKnowledgeProcessingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessAiHelperKnowledgeEntry implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(private readonly int $knowledgeEntryId)
    {
    }

    public function handle(AiHelperKnowledgeProcessingService $processor): void
    {
        $processor->process($this->knowledgeEntryId);
    }
}
