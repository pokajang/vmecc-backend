<?php

namespace App\Console\Commands;

use App\Jobs\EmbedAiHelperKnowledgeEntry;
use App\Jobs\ProcessAiHelperKnowledgeEntry;
use App\Models\AiHelperKnowledgeEntry;
use App\Services\AiHelperEmbeddingService;
use App\Services\AiHelperKnowledgeLifecycleService;
use App\Services\AiHelperKnowledgeProcessingService;
use Illuminate\Console\Command;

class ReindexAiHelperKnowledge extends Command
{
    protected $signature = 'ai-helper:reindex-knowledge
        {--sync : Process documents in this command instead of queueing them}
        {--semantic : Build semantic embeddings after chunking}
        {--only-missing : With --semantic, embed only entries not already ready}
        {--entry= : Restrict the operation to one knowledge entry ID}';

    protected $description = 'Rebuild the complete AI knowledge index from all retained source documents.';

    public function handle(
        AiHelperKnowledgeLifecycleService $lifecycle,
    ): int {
        $processed = 0;
        $failed = 0;
        $query = AiHelperKnowledgeEntry::query()
            ->where('source_mime', 'text/markdown')
            ->whereNotNull('source_path')
            ->where('status', '!=', AiHelperKnowledgeEntry::STATUS_DELETING);
        if ($this->option('entry')) {
            $query->whereKey((int) $this->option('entry'));
        }
        if ($this->option('semantic') && $this->option('only-missing')) {
            $query->where('embedding_status', '!=', 'ready');
        }

        $query->orderBy('id')
            ->chunkById(50, function ($entries) use ($lifecycle, &$processed, &$failed) {
                foreach ($entries as $entry) {
                    if ($this->option('semantic') && $this->option('only-missing')) {
                        $this->dispatchEmbedding($entry->id);
                        $processed++;

                        continue;
                    }
                    $runId = $lifecycle->beginIngestion($entry);
                    if ($this->option('sync')) {
                        app(AiHelperKnowledgeProcessingService::class)->process($entry->id, $runId);
                        $result = $entry->fresh();
                        if (! $result
                            || $result->status !== AiHelperKnowledgeEntry::STATUS_ACTIVE
                            || ! $result->extraction_complete
                            || trim((string) $result->error) !== '') {
                            $failed++;
                            $this->error(sprintf(
                                'Knowledge #%d failed: %s',
                                $entry->id,
                                trim((string) ($result?->error ?: 'incomplete extraction')),
                            ));
                        }
                        if ($this->option('semantic') && $result?->status === AiHelperKnowledgeEntry::STATUS_ACTIVE) {
                            app(AiHelperEmbeddingService::class)->embedEntry($result);
                        }
                    } else {
                        ProcessAiHelperKnowledgeEntry::dispatch($entry->id, $runId);
                    }
                    $processed++;
                }
            });

        if ($this->option('sync')) {
            $this->info("Processed {$processed} knowledge documents; {$failed} failed.");

            return $failed === 0 ? self::SUCCESS : self::FAILURE;
        }

        $this->info("Queued {$processed} knowledge documents.");

        return self::SUCCESS;
    }

    private function dispatchEmbedding(int $entryId): void
    {
        if ($this->option('sync')) {
            $entry = AiHelperKnowledgeEntry::query()->find($entryId);
            if ($entry) {
                app(AiHelperEmbeddingService::class)->embedEntry($entry);
            }

            return;
        }

        EmbedAiHelperKnowledgeEntry::dispatch($entryId);
    }
}
