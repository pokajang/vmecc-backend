<?php

namespace App\Console\Commands;

use App\Models\AiHelperKnowledgeEntity;
use App\Models\AiHelperKnowledgeEntityAlias;
use App\Models\AiHelperKnowledgeEntry;
use App\Models\AiHelperMessage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AuditAiHelperEntities extends Command
{
    protected $signature = 'ai-helper:entities-audit {--json : Emit machine-readable JSON}';

    protected $description = 'Audit corpus-derived Ask AI entities, aliases, ambiguity, and retrieval feedback.';

    public function handle(): int
    {
        $ambiguousAliases = AiHelperKnowledgeEntityAlias::query()
            ->join('ai_helper_knowledge_entities as entities', 'entities.id', '=', 'ai_helper_knowledge_entity_aliases.entity_id')
            ->where('entities.active', true)
            ->groupBy('ai_helper_knowledge_entity_aliases.normalized_alias')
            ->havingRaw('COUNT(DISTINCT entities.normalized_name) > 1')
            ->pluck('ai_helper_knowledge_entity_aliases.normalized_alias')
            ->values()
            ->all();
        $entriesWithoutEntities = AiHelperKnowledgeEntry::query()
            ->where('active', true)
            ->whereHas('chunks', fn ($query) => $query->where('active', true))
            ->whereDoesntHave('entities', fn ($query) => $query->where('active', true))
            ->pluck('id')
            ->all();
        $feedback = AiHelperMessage::query()
            ->where('role', AiHelperMessage::ROLE_ASSISTANT)
            ->whereNotNull('retrieval_metadata')
            ->latest('id')
            ->limit(1000)
            ->get(['retrieval_metadata'])
            ->reduce(function (array $counts, AiHelperMessage $message): array {
                $metadata = (array) $message->retrieval_metadata;
                $counts['probe_attempted'] += (bool) ($metadata['probe_attempted'] ?? false) ? 1 : 0;
                $counts['probe_promoted'] += (bool) ($metadata['probe_promoted'] ?? false) ? 1 : 0;
                $counts['recovery_attempted'] += (bool) ($metadata['recovery_attempted'] ?? false) ? 1 : 0;

                return $counts;
            }, ['probe_attempted' => 0, 'probe_promoted' => 0, 'recovery_attempted' => 0]);

        $activeEntities = AiHelperKnowledgeEntity::query()->where('active', true)->count();
        $activeAliases = AiHelperKnowledgeEntityAlias::query()
            ->whereHas('entity', fn ($query) => $query->where('active', true))
            ->count();
        $payload = [
            'ready' => $activeEntities > 0 && $activeAliases > 0,
            'active_entities' => $activeEntities,
            'active_aliases' => $activeAliases,
            'entity_types' => AiHelperKnowledgeEntity::query()
                ->where('active', true)
                ->select('entity_type', DB::raw('COUNT(*) as aggregate'))
                ->groupBy('entity_type')
                ->pluck('aggregate', 'entity_type')
                ->all(),
            'ambiguous_aliases' => $ambiguousAliases,
            'entries_without_entities' => $entriesWithoutEntities,
            'orphan_entities' => AiHelperKnowledgeEntity::query()
                ->whereDoesntHave('knowledgeEntry')
                ->count(),
            'retrieval_feedback' => $feedback,
        ];

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES));
        } else {
            $this->components->twoColumnDetail('Entity index ready', $payload['ready'] ? '<fg=green>yes</>' : '<fg=red>no</>');
            $this->components->twoColumnDetail('Active entities', (string) $payload['active_entities']);
            $this->components->twoColumnDetail('Active aliases', (string) $payload['active_aliases']);
            $this->components->twoColumnDetail('Ambiguous aliases', (string) count($ambiguousAliases));
            $this->components->twoColumnDetail('Entries without entities', (string) count($entriesWithoutEntities));
        }

        return $payload['ready'] ? self::SUCCESS : self::FAILURE;
    }
}
