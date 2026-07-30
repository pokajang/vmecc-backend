<?php

namespace App\Services;

use App\Models\AiHelperKnowledgeChunk;
use App\Models\AiHelperKnowledgeEntity;
use App\Models\AiHelperKnowledgeEntry;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class AiHelperKnowledgeEntityIndexer
{
    public function __construct(private readonly AiHelperCorpusEntityExtractor $extractor) {}

    /**
     * Caller must wrap this operation in the same transaction as chunk staging.
     *
     * @param  Collection<int, AiHelperKnowledgeChunk>  $chunks
     */
    public function replaceVersion(
        AiHelperKnowledgeEntry $entry,
        Collection $chunks,
        int $ingestionVersion,
        bool $active,
    ): int {
        $entry->entities()->where('ingestion_version', $ingestionVersion)->delete();
        $extracted = $chunks
            ->flatMap(function (AiHelperKnowledgeChunk $chunk): array {
                return collect($this->extractor->extract($chunk))
                    ->map(fn (array $entity): array => $entity + ['source_chunk_id' => $chunk->id])
                    ->all();
            })
            ->groupBy(fn (array $entity) => $entity['entity_type'].'|'.$entity['normalized_name']);

        $created = 0;
        foreach ($extracted as $matches) {
            $best = $matches->sortByDesc('confidence')->first();
            $entity = AiHelperKnowledgeEntity::create([
                'knowledge_entry_id' => $entry->id,
                'source_chunk_id' => $best['source_chunk_id'],
                'canonical_name' => Str::limit((string) $best['canonical_name'], 255, ''),
                'normalized_name' => Str::limit((string) $best['normalized_name'], 255, ''),
                'entity_type' => (string) $best['entity_type'],
                'confidence' => (float) $best['confidence'],
                'ingestion_version' => $ingestionVersion,
                'active' => $active,
            ]);
            $aliases = $matches
                ->flatMap(fn (array $match) => $match['aliases'])
                ->push([
                    'alias' => $best['canonical_name'],
                    'alias_type' => 'canonical',
                    'language' => null,
                ])
                ->filter(fn (array $alias) => trim((string) ($alias['alias'] ?? '')) !== '')
                ->unique(fn (array $alias) => $this->normalize((string) $alias['alias']))
                ->values();
            foreach ($aliases as $alias) {
                $entity->aliases()->create([
                    'alias' => Str::limit(trim((string) $alias['alias']), 255, ''),
                    'normalized_alias' => Str::limit($this->normalize((string) $alias['alias']), 255, ''),
                    'alias_type' => Str::limit((string) ($alias['alias_type'] ?? 'extracted'), 32, ''),
                    'language' => isset($alias['language'])
                        ? Str::limit((string) $alias['language'], 8, '')
                        : null,
                ]);
            }
            $created++;
        }

        return $created;
    }

    public function reindexActiveEntry(AiHelperKnowledgeEntry $entry): int
    {
        $chunks = $entry->chunks()->where('active', true)->orderBy('chunk_index')->get();
        if ($chunks->isEmpty()) {
            return 0;
        }
        $version = max(1, (int) ($chunks->max('ingestion_version') ?: $entry->ingestion_version));

        return $this->replaceVersion($entry, $chunks, $version, true);
    }

    private function normalize(string $value): string
    {
        $value = Str::lower(str_replace(['_', '-', '–', '—'], ' ', $value));

        return trim((string) preg_replace('/[^\pL\pN]+/u', ' ', $value));
    }
}
