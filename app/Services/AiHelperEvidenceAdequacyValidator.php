<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class AiHelperEvidenceAdequacyValidator
{
    public function __construct(private readonly AiHelperQuestionFacetAnalyzer $facets) {}

    /**
     * @param  Collection<int, array<string, mixed>>  $candidates
     * @return array{status: string, reason: ?string, candidates: Collection<int, array<string, mixed>>, matched_entities: array<int, string>, requested_facets: array<int, string>}
     */
    public function assessCandidates(Collection $candidates, array $analysis): array
    {
        $entities = array_values((array) ($analysis['resolved_entities'] ?? []));
        $requestedFacets = array_values((array) ($analysis['requested_facets'] ?? []));
        $dynamicAliases = collect((array) ($analysis['corpus_entities'] ?? []))
            ->mapWithKeys(fn (array $entity) => [
                (string) ($entity['normalized_name'] ?? '') => collect([
                    $entity['canonical_name'] ?? null,
                    ...(array) ($entity['all_aliases'] ?? []),
                    ...(array) ($entity['matched_aliases'] ?? []),
                ])->filter()->unique()->values()->all(),
            ])
            ->all();
        if ($candidates->isEmpty()) {
            return [
                'status' => 'recover',
                'reason' => 'no_candidates',
                'candidates' => $candidates,
                'matched_entities' => [],
                'requested_facets' => $requestedFacets,
            ];
        }

        $scored = $candidates
            ->map(function (array $candidate) use ($entities, $requestedFacets, $dynamicAliases): array {
                $entityScore = $this->entityScore($candidate, $entities, $dynamicAliases);
                $facetScore = $this->facetScore($candidate, $requestedFacets);
                $candidate['entity_adequacy_score'] = $entityScore;
                $candidate['facet_adequacy_score'] = $facetScore;
                $candidate['adequacy_score'] = $entityScore + $facetScore;
                $candidate['score'] = (float) ($candidate['score'] ?? 0)
                    + ($entityScore * 180)
                    + ($facetScore * 70);

                return $candidate;
            })
            ->sortByDesc('score')
            ->values();

        if ($entities !== []) {
            $entityMatches = $scored->filter(fn (array $candidate) => ($candidate['entity_adequacy_score'] ?? 0) > 0);
            if ($entityMatches->isEmpty()) {
                return [
                    'status' => 'recover',
                    'reason' => 'requested_entity_missing',
                    'candidates' => collect(),
                    'matched_entities' => [],
                    'requested_facets' => $requestedFacets,
                ];
            }
            $scored = $entityMatches->values();
        }

        $matchedEntities = collect($entities)
            ->filter(fn (string $entity) => $scored->contains(
                fn (array $candidate) => $this->candidateContainsEntity($candidate, $entity, $dynamicAliases),
            ))
            ->values()
            ->all();

        return [
            'status' => 'adequate',
            'reason' => null,
            'candidates' => $scored,
            'matched_entities' => $matchedEntities,
            'requested_facets' => $requestedFacets,
        ];
    }

    /** @param array<string, mixed> $candidate @param array<int, string> $entities */
    private function entityScore(array $candidate, array $entities, array $dynamicAliases = []): int
    {
        if ($entities === []) {
            return 0;
        }
        $content = $this->normalize((string) ($candidate['chunk']->content ?? ''));
        $headings = $this->normalize(collect($candidate['chunk']->heading_path ?? [])->join(' '));

        return collect($entities)->max(function (string $entity) use ($content, $headings, $dynamicAliases): int {
            $score = 0;
            $aliases = $dynamicAliases[$entity] ?? $this->facets->aliasesForEntity($entity);
            $exactAliases = collect($dynamicAliases[$entity] ?? $this->exactAliasesForEntity($entity))
                ->map(fn (string $alias) => $this->normalize($alias));
            foreach ($aliases as $alias) {
                $alias = $this->normalize($alias);
                $isExactAlias = $exactAliases->contains($alias);
                $isPhrase = Str::contains($alias, ' ');
                if ($this->containsPhrase($headings, $alias)) {
                    $score = max($score, $isExactAlias ? ($isPhrase ? 11 : 7) : ($isPhrase ? 5 : 3));
                }
                if ($this->containsPhrase($content, $alias)) {
                    $score = max($score, $isExactAlias ? ($isPhrase ? 12 : 8) : ($isPhrase ? 6 : 3));
                }
            }

            return $score;
        }) ?? 0;
    }

    /** @param array<string, mixed> $candidate @param array<int, string> $facets */
    private function facetScore(array $candidate, array $facets): int
    {
        if ($facets === []) {
            return 0;
        }
        $content = $this->normalize((string) ($candidate['chunk']->content ?? ''));
        $headings = $this->normalize(collect($candidate['chunk']->heading_path ?? [])->join(' '));

        return collect($facets)->sum(function (string $facet) use ($content, $headings): int {
            $terms = match ($facet) {
                'role_responsibilities' => ['role', 'responsibilities', 'duties', 'personnel to', 'carry out', 'perform', 'assist', 'follow'],
                'qualifications' => ['qualification', 'qualified', 'competent', 'certificate', 'experience'],
                'procedure_steps' => ['procedure', 'steps', 'process', 'workflow'],
                'requirements' => ['requirements', 'required', 'must', 'shall', 'criteria'],
                'numbers_capacity' => [
                    'number', 'capacity', 'quantity', 'qty', 'total', 'minimum', 'maximum',
                    'per shift', 'number of shifts', 'number of groups',
                ],
                'contacts' => ['contact', 'telephone', 'phone', 'email'],
                'scope_coverage' => ['scope', 'coverage', 'area', 'perimeter'],
                'timing_frequency' => [
                    'frequency', 'daily', 'weekly', 'monthly', 'yearly', 'duration',
                    'schedule', 'operating', 'hours', 'working hr', 'shift', '24/7',
                ],
                'exceptions' => ['exception', 'except', 'unless'],
                'comparison' => ['compare', 'comparison', 'difference'],
                default => [],
            };
            $headingMatches = collect($terms)->filter(fn (string $term) => str_contains($headings, $term))->count();
            $contentMatches = collect($terms)->filter(fn (string $term) => str_contains($content, $term))->count();

            return min(4, ($headingMatches * 2) + $contentMatches);
        });
    }

    /** @param array<string, mixed> $candidate */
    private function candidateContainsEntity(array $candidate, string $entity, array $dynamicAliases = []): bool
    {
        return $this->entityScore($candidate, [$entity], $dynamicAliases) > 0;
    }

    private function normalize(string $value): string
    {
        $value = Str::lower(str_replace(['_', '-'], ' ', $value));

        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    private function containsPhrase(string $haystack, string $phrase): bool
    {
        if ($phrase === '') {
            return false;
        }

        return preg_match('/(?<![\pL\pN])'.preg_quote($phrase, '/').'(?![\pL\pN])/u', $haystack) === 1;
    }

    /** @return array<int, string> */
    private function exactAliasesForEntity(string $entity): array
    {
        return match ($entity) {
            'tactical_response_team_member' => ['tactical response team member', 'trt member'],
            'emergency_response_team_member' => ['emergency response team member', 'ertm'],
            'back_up_emergency_response_team_member' => [
                'back-up emergency response team member', 'backup emergency response team member', 'bert',
            ],
            'on_scene_commander' => ['on scene commander', 'on-scene commander', 'osc'],
            'incident_controller' => ['incident controller', 'ic'],
            'tactical_response_team' => ['tactical response team', 'trt'],
            default => [str_replace('_', ' ', $entity)],
        };
    }
}
