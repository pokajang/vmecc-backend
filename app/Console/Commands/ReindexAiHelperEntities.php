<?php

namespace App\Console\Commands;

use App\Models\AiHelperKnowledgeEntry;
use App\Services\AiHelperKnowledgeEntityIndexer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReindexAiHelperEntities extends Command
{
    protected $signature = 'ai-helper:entities-reindex
        {--entry= : Reindex one knowledge entry ID}
        {--dry-run : Report the entries that would be reindexed without writing}';

    protected $description = 'Rebuild deterministic entity and alias metadata for active Ask AI knowledge.';

    public function handle(AiHelperKnowledgeEntityIndexer $indexer): int
    {
        $query = AiHelperKnowledgeEntry::query()
            ->where('active', true)
            ->whereHas('chunks', fn ($builder) => $builder->where('active', true))
            ->orderBy('id');
        if ($this->option('entry') !== null) {
            $query->whereKey((int) $this->option('entry'));
        }
        $entries = $query->get();
        if ((bool) $this->option('dry-run')) {
            $this->line((string) json_encode([
                'dry_run' => true,
                'entries' => $entries->pluck('id')->all(),
                'entry_count' => $entries->count(),
            ], JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $entityCount = 0;
        foreach ($entries as $entry) {
            $entityCount += DB::transaction(fn () => $indexer->reindexActiveEntry($entry));
        }
        $this->info("Reindexed {$entityCount} entities across {$entries->count()} knowledge entries.");

        return self::SUCCESS;
    }
}
