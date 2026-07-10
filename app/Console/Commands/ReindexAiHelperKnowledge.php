<?php

namespace App\Console\Commands;

use App\Jobs\ProcessAiHelperKnowledgeEntry;
use App\Models\AiHelperKnowledgeEntry;
use App\Services\AiHelperKnowledgeLifecycleService;
use Illuminate\Console\Command;

class ReindexAiHelperKnowledge extends Command
{
    protected $signature = 'ai-helper:reindex-knowledge {--sync : Process documents in this command instead of queueing them}';

    protected $description = 'Rebuild the complete AI knowledge index from all retained source documents.';

    public function handle(AiHelperKnowledgeLifecycleService $lifecycle): int
    {
        $processed = 0;
        AiHelperKnowledgeEntry::query()
            ->whereNotNull('source_path')
            ->where('status', '!=', AiHelperKnowledgeEntry::STATUS_DELETING)
            ->orderBy('id')
            ->chunkById(50, function ($entries) use ($lifecycle, &$processed) {
                foreach ($entries as $entry) {
                    $runId = $lifecycle->beginIngestion($entry);
                    if ($this->option('sync')) {
                        app(\App\Services\AiHelperKnowledgeProcessingService::class)->process($entry->id, $runId);
                    } else {
                        ProcessAiHelperKnowledgeEntry::dispatch($entry->id, $runId);
                    }
                    $processed++;
                }
            });

        $this->info("Queued or processed {$processed} knowledge documents.");

        return self::SUCCESS;
    }
}
