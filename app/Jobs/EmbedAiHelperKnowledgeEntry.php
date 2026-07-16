<?php

namespace App\Jobs;

use App\Models\AiHelperKnowledgeEntry;
use App\Services\AiHelperEmbeddingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class EmbedAiHelperKnowledgeEntry implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [30, 120];

    public int $timeout = 300;

    public function __construct(private readonly int $knowledgeEntryId) {}

    public function handle(AiHelperEmbeddingService $embeddings): void
    {
        if (! $embeddings->isAvailable()) {
            return;
        }
        $entry = AiHelperKnowledgeEntry::query()
            ->where('source_mime', 'text/markdown')
            ->where('active', true)
            ->find($this->knowledgeEntryId);
        if ($entry) {
            $embeddings->embedEntry($entry);
        }
    }
}
