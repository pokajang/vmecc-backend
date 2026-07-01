<?php

namespace App\Console\Commands;

use App\Models\AiHelperKnowledgeEntry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PruneAiHelperKnowledgeFiles extends Command
{
    protected $signature = 'ai-helper:prune-knowledge-files {--dry-run : Show what would be pruned without deleting files or records}';

    protected $description = 'Prune old failed or deleted Ask AI knowledge source files after retention windows.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $deletedCutoff = now()->subDays(max(1, (int) config('ai_helper.knowledge_deleted_retention_days', 30)));
        $failedCutoff = now()->subDays(max(1, (int) config('ai_helper.knowledge_failed_retention_days', 14)));
        $pruned = 0;

        AiHelperKnowledgeEntry::withTrashed()
            ->whereNotNull('source_path')
            ->where('source_path', 'not like', 'seed:%')
            ->where(function ($query) use ($deletedCutoff, $failedCutoff) {
                $query
                    ->whereNotNull('deleted_at')
                    ->where('deleted_at', '<=', $deletedCutoff)
                    ->orWhere(function ($failed) use ($failedCutoff) {
                        $failed
                            ->whereNull('deleted_at')
                            ->where('status', AiHelperKnowledgeEntry::STATUS_FAILED)
                            ->where('updated_at', '<=', $failedCutoff);
                    });
            })
            ->orderBy('id')
            ->chunkById(100, function ($entries) use ($dryRun, &$pruned) {
                foreach ($entries as $entry) {
                    $this->line(sprintf(
                        '%s knowledge #%d: %s',
                        $dryRun ? 'Would prune' : 'Pruning',
                        $entry->id,
                        $entry->source_path
                    ));

                    if (! $dryRun) {
                        Storage::disk('local')->delete($entry->source_path);
                        $entry->forceDelete();
                    }

                    $pruned++;
                }
            });

        $this->info(($dryRun ? 'Matched' : 'Pruned').' '.$pruned.' Ask AI knowledge file(s).');

        return self::SUCCESS;
    }
}
