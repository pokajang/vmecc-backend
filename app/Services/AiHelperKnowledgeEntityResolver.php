<?php

namespace App\Services;

use App\Models\AiHelperKnowledgeEntityAlias;
use Illuminate\Support\Str;

final class AiHelperKnowledgeEntityResolver
{
    private const AMBIGUOUS_WORD_ALIASES = [
        'aid', 'all', 'and', 'are', 'can', 'for', 'may', 'new', 'not', 'our', 'the', 'who',
    ];

    /**
     * @param  array<int, int>  $authorizedEntryIds
     * @return array{matches: array<int, array<string, mixed>>, ambiguous_aliases: array<int, string>}
     */
    public function resolve(string $query, array $authorizedEntryIds): array
    {
        if ($authorizedEntryIds === []) {
            return ['matches' => [], 'ambiguous_aliases' => []];
        }
        $normalizedQuery = $this->normalize($query);
        if ($normalizedQuery === '') {
            return ['matches' => [], 'ambiguous_aliases' => []];
        }

        $aliases = AiHelperKnowledgeEntityAlias::query()
            ->whereHas('entity', fn ($builder) => $builder
                ->where('active', true)
                ->whereIn('knowledge_entry_id', $authorizedEntryIds))
            ->with(['entity:id,knowledge_entry_id,canonical_name,normalized_name,entity_type,confidence', 'entity.aliases'])
            ->get()
            ->filter(fn (AiHelperKnowledgeEntityAlias $alias): bool => $this->containsPhrase(
                $query,
                $normalizedQuery,
                (string) $alias->alias,
                (string) $alias->normalized_alias,
            ));

        $matches = $aliases
            ->groupBy('entity.normalized_name')
            ->map(function ($entityAliases): array {
                $first = $entityAliases->first();
                $entity = $first->entity;

                return [
                    'id' => (int) $entity->id,
                    'knowledge_entry_id' => (int) $entity->knowledge_entry_id,
                    'canonical_name' => (string) $entity->canonical_name,
                    'normalized_name' => (string) $entity->normalized_name,
                    'entity_type' => (string) $entity->entity_type,
                    'confidence' => (float) $entity->confidence,
                    'matched_aliases' => $entityAliases->pluck('alias')->unique()->values()->all(),
                    'all_aliases' => $entity->aliases->pluck('alias')->unique()->values()->all(),
                ];
            })
            ->sortByDesc(fn (array $match) => Str::length(collect($match['matched_aliases'])->sortByDesc(
                fn (string $alias) => Str::length($alias),
            )->first() ?? ''))
            ->values()
            ->all();

        $ambiguous = $aliases
            ->groupBy('normalized_alias')
            ->filter(fn ($matches) => $matches->pluck('entity.normalized_name')->unique()->count() > 1)
            ->keys()
            ->values()
            ->all();

        return ['matches' => $matches, 'ambiguous_aliases' => $ambiguous];
    }

    private function normalize(string $value): string
    {
        $value = Str::lower(str_replace(['_', '-', '–', '—'], ' ', $value));

        return trim((string) preg_replace('/[^\pL\pN]+/u', ' ', $value));
    }

    private function containsPhrase(
        string $originalQuery,
        string $normalizedQuery,
        string $originalAlias,
        string $normalizedAlias,
    ): bool {
        if ($normalizedAlias === '' || Str::length($normalizedAlias) < 2) {
            return false;
        }

        // Two-character corpus aliases (for example "IS") commonly collide
        // with ordinary words. Require users to preserve acronym casing for
        // these very short dynamic aliases.
        if (Str::length($normalizedAlias) === 2
            || in_array($normalizedAlias, self::AMBIGUOUS_WORD_ALIASES, true)) {
            $uppercaseAlias = Str::upper($originalAlias);

            return $originalAlias === $uppercaseAlias
                && preg_match('/(?<![\pL\pN])'.preg_quote($uppercaseAlias, '/').'(?![\pL\pN])/u', $originalQuery) === 1;
        }

        return preg_match('/(?<![\pL\pN])'.preg_quote($normalizedAlias, '/').'(?![\pL\pN])/u', $normalizedQuery) === 1;
    }
}
