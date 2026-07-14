<?php

namespace App\Console\Commands;

use App\Jobs\ProcessAiHelperKnowledgeEntry;
use App\Models\AiHelperKnowledgeEntry;
use App\Services\AiHelperKnowledgeLifecycleService;
use App\Services\AiHelperKnowledgeProcessingService;
use App\Services\AiHelperKnowledgeRuntimeService;
use Illuminate\Console\Command;

class ReindexAiHelperKnowledge extends Command
{
    protected $signature = 'ai-helper:reindex-knowledge {--sync : Process documents in this command instead of queueing them}';

    protected $description = 'Rebuild the complete AI knowledge index from all retained source documents.';

    public function handle(
        AiHelperKnowledgeLifecycleService $lifecycle,
        AiHelperKnowledgeRuntimeService $runtime,
    ): int {
        if (AiHelperKnowledgeEntry::query()
            ->where('source_mime', 'application/pdf')
            ->whereNotNull('source_path')
            ->exists()) {
            $runtime->assertPdfIngestionReady();
        }

        $processed = 0;
        $failed = 0;
        AiHelperKnowledgeEntry::query()
            ->whereNotNull('source_path')
            ->where('status', '!=', AiHelperKnowledgeEntry::STATUS_DELETING)
            ->orderBy('id')
            ->chunkById(50, function ($entries) use ($lifecycle, &$processed, &$failed) {
                foreach ($entries as $entry) {
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
}
