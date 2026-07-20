<?php

namespace App\Console\Commands;

use App\Jobs\EmbedAiHelperKnowledgeEntry;
use App\Models\AiHelperKnowledgeEntry;
use App\Services\AiHelperKnowledgeProcessingService;
use Illuminate\Console\Command;

class ReconcileAiHelperStuckEmbeddings extends Command
{
    protected $signature = 'ai-helper:reconcile-stuck-embeddings
        {--minutes= : Age after which an embedding job is considered stuck}
        {--retry : Requeue eligible Markdown entries instead of marking them failed}
        {--dry-run : Report stuck entries without changing or queueing them}';

    protected $description = 'Fail or safely requeue Ask AI entries left in the embedding processing state.';

    public function handle(AiHelperKnowledgeProcessingService $processing): int
    {
        $defaultMinutes = max(1, (int) config('ai_helper.stale_embedding_minutes', 30));
        $minutes = $this->positiveIntegerOption('minutes', $defaultMinutes);
        if ($minutes === null) {
            return self::INVALID;
        }

        $retry = (bool) $this->option('retry');
        $dryRun = (bool) $this->option('dry-run');
        $cutoff = now()->subMinutes($minutes);
        $matched = 0;
        $retried = 0;
        $failed = 0;

        AiHelperKnowledgeEntry::query()
            ->where('embedding_status', 'processing')
            ->where('updated_at', '<=', $cutoff)
            ->orderBy('id')
            ->chunkById(100, function ($entries) use ($processing, $retry, $dryRun, $cutoff, &$matched, &$retried, &$failed): void {
                foreach ($entries as $entry) {
                    $matched++;
                    $hasStagedVersion = $entry->status === AiHelperKnowledgeEntry::STATUS_PROCESSING
                        && $entry->chunks()
                            ->where('ingestion_version', $entry->ingestion_version)
                            ->where('active', false)
                            ->exists();
                    $eligibleForRetry = $retry
                        && ($entry->active || $hasStagedVersion)
                        && $entry->source_mime === 'text/markdown';

                    if ($dryRun) {
                        $eligibleForRetry ? $retried++ : $failed++;

                        continue;
                    }

                    $updated = AiHelperKnowledgeEntry::query()
                        ->whereKey($entry->id)
                        ->where('embedding_status', 'processing')
                        ->where('updated_at', '<=', $cutoff)
                        ->update($eligibleForRetry ? [
                            'embedding_status' => 'pending',
                            'embedding_error' => null,
                            'updated_at' => now(),
                        ] : [
                            'embedding_status' => 'failed',
                            'embedding_error' => 'Embedding processing exceeded the allowed runtime and was reconciled.',
                            'updated_at' => now(),
                        ]);

                    if ($updated !== 1) {
                        continue;
                    }

                    if ($eligibleForRetry) {
                        EmbedAiHelperKnowledgeEntry::dispatch(
                            $entry->id,
                            $hasStagedVersion ? (int) $entry->ingestion_version : null,
                            $hasStagedVersion ? $entry->ingestion_run_id : null,
                        );
                        $retried++;
                    } else {
                        if ($hasStagedVersion) {
                            $processing->markFailedForRun(
                                $entry->id,
                                $entry->ingestion_run_id,
                                'Embedding processing exceeded the allowed runtime and was reconciled.',
                            );
                        }
                        $failed++;
                    }
                }
            });

        $verb = $dryRun ? 'Matched' : 'Reconciled';
        $this->info("{$verb} {$matched} stuck embedding entr".($matched === 1 ? 'y' : 'ies')."; {$retried} retry, {$failed} failed.");

        return self::SUCCESS;
    }

    private function positiveIntegerOption(string $name, int $default): ?int
    {
        $value = $this->option($name);
        if ($value === null || $value === '') {
            return max(1, $default);
        }
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 1) {
            $this->error("The --{$name} option must be a positive integer.");

            return null;
        }

        return (int) $value;
    }
}
