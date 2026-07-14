<?php

namespace App\Console\Commands;

use App\Models\AiHelperKnowledgeEntry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
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
        $deletingCutoff = now()->subMinutes(5);
        $pruned = 0;

        AiHelperKnowledgeEntry::withTrashed()
            ->whereNotNull('source_path')
            ->where('source_path', 'not like', 'seed:%')
            ->where(function ($query) use ($deletedCutoff, $failedCutoff, $deletingCutoff) {
                $query
                    ->whereNotNull('deleted_at')
                    ->where('deleted_at', '<=', $deletedCutoff)
                    ->orWhere(function ($failed) use ($failedCutoff) {
                        $failed
                            ->whereNull('deleted_at')
                            ->where('status', AiHelperKnowledgeEntry::STATUS_FAILED)
                            ->where('updated_at', '<=', $failedCutoff);
                    })
                    ->orWhere(function ($deleting) use ($deletingCutoff) {
                        $deleting
                            ->whereNull('deleted_at')
                            ->where('status', AiHelperKnowledgeEntry::STATUS_DELETING)
                            ->where('updated_at', '<=', $deletingCutoff);
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

        $temporaryPruned = 0;
        $temporaryCutoff = now()->subHours(max(1, (int) config(
            'ai_helper.knowledge_ocr_temporary_retention_hours',
            24,
        )))->getTimestamp();
        $temporaryRoot = storage_path('app/ai-helper/knowledge-ocr');
        if (File::isDirectory($temporaryRoot)) {
            foreach (File::directories($temporaryRoot) as $directory) {
                $modifiedAt = File::lastModified($directory);
                if ($modifiedAt > $temporaryCutoff) {
                    continue;
                }

                $this->line(sprintf(
                    '%s stale OCR temporary directory: %s',
                    $dryRun ? 'Would prune' : 'Pruning',
                    basename($directory),
                ));
                if (! $dryRun) {
                    File::deleteDirectory($directory);
                }
                $temporaryPruned++;
            }
        }

        $this->info(($dryRun ? 'Matched' : 'Pruned').' '.$pruned.' Ask AI knowledge file(s).');
        $this->info(($dryRun ? 'Matched' : 'Pruned').' '.$temporaryPruned.' stale OCR temporary item(s).');

        return self::SUCCESS;
    }
}
