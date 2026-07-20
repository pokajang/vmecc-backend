<?php

namespace App\Jobs;

use App\Models\AiHelperKnowledgeEntry;
use App\Services\AiHelperEmbeddingService;
use App\Services\AiHelperKnowledgeProcessingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Throwable;

class EmbedAiHelperKnowledgeEntry implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [30, 120];

    public int $timeout;

    public bool $failOnTimeout = true;

    public function __construct(
        private readonly int $knowledgeEntryId,
        private readonly ?int $ingestionVersion = null,
        private readonly ?string $ingestionRunId = null,
    ) {
        $this->timeout = max(60, (int) config('ai_helper.embedding_job_timeout_seconds', 300));
    }

    public function handle(
        AiHelperEmbeddingService $embeddings,
        AiHelperKnowledgeProcessingService $processing,
    ): void {
        $query = AiHelperKnowledgeEntry::query()->where('source_mime', 'text/markdown');
        if ($this->ingestionVersion !== null) {
            $query->where('status', AiHelperKnowledgeEntry::STATUS_PROCESSING)
                ->where('ingestion_version', $this->ingestionVersion);
            if ($this->ingestionRunId !== null) {
                $query->where('ingestion_run_id', $this->ingestionRunId);
            }
        } else {
            $query->where('active', true)
                ->where('status', AiHelperKnowledgeEntry::STATUS_ACTIVE);
        }
        $entry = $query->find($this->knowledgeEntryId);
        if (! $entry) {
            return;
        }

        if (! $embeddings->isAvailable()) {
            if ($this->ingestionVersion !== null) {
                $processing->markFailedForRun(
                    $entry->id,
                    $this->ingestionRunId,
                    'Semantic indexing is unavailable; the staged index was not activated.',
                );
            }

            return;
        }

        $embeddings->embedEntry($entry, $this->ingestionVersion, $this->ingestionRunId);
    }

    public function failed(Throwable $exception): void
    {
        if ($this->ingestionVersion !== null) {
            app(AiHelperKnowledgeProcessingService::class)->markFailedForRun(
                $this->knowledgeEntryId,
                $this->ingestionRunId,
                'Semantic indexing failed after retrying: '.$exception->getMessage(),
            );

            return;
        }

        AiHelperKnowledgeEntry::query()
            ->whereKey($this->knowledgeEntryId)
            ->where('embedding_status', '!=', 'ready')
            ->update([
                'embedding_status' => 'failed',
                'embedding_error' => Str::limit(
                    'Embedding job failed after retrying: '.$exception->getMessage(),
                    1000,
                    '',
                ),
                'updated_at' => now(),
            ]);
    }
}
