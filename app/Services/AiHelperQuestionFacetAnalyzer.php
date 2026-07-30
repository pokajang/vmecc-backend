<?php

namespace App\Services;

use Illuminate\Support\Str;

final class AiHelperQuestionFacetAnalyzer
{
    /** @return array<int, string> */
    public function facets(string $value): array
    {
        $value = $this->normalize($value);
        $patterns = [
            'role_responsibilities' => '/\b(?:roles?|responsibilit(?:y|ies)|dut(?:y|ies)|what does|what do|tasks?|function|peranan|tanggungjawab|tugas|fungsi)\b/u',
            'qualifications' => '/\b(?:qualifications?|qualified|competenc(?:e|y|ies)|certificates?|experience|training required|kelayakan|kompetensi|sijil|pengalaman)\b/u',
            'procedure_steps' => '/\b(?:procedure|steps?|process|workflow|how (?:do|does|to)|prosedur|langkah|proses|cara)\b/u',
            'requirements' => '/\b(?:requirements?|required|must|shall|criteria|keperluan|diperlukan|mesti|kriteria)\b/u',
            'numbers_capacity' => '/\b(?:how many|numbers?|capacity|quantit(?:y|ies)|qty|total|minimum|maximum|berapa|bilangan|kapasiti|kuantiti|jumlah|minimum|maksimum)\b/u',
            'contacts' => '/\b(?:contact|telephone|phone|email|call|hubungi|telefon|e-mel|panggil)\b/u',
            'scope_coverage' => '/\b(?:scope|coverage|cover|area|perimeter|skop|liputan|kawasan|perimeter)\b/u',
            'timing_frequency' => '/\b(?:when|frequency|frequent|daily|weekly|monthly|yearly|duration|schedule|operating|hours?|shifts?|24\s*\/\s*7|bila|kekerapan|harian|mingguan|bulanan|tahunan|tempoh|jadual|waktu|syif)\b/u',
            'exceptions' => '/\b(?:exception|except|unless|not apply|pengecualian|kecuali|melainkan|tidak terpakai)\b/u',
            'comparison' => '/\b(?:compare|comparison|difference|different|versus|vs|banding|perbandingan|perbezaan|berbeza)\b/u',
        ];

        return collect($patterns)
            ->filter(fn (string $pattern): bool => preg_match($pattern, $value) === 1)
            ->keys()
            ->values()
            ->all();
    }

    /** @return array<int, string> */
    public function entities(string $value): array
    {
        $value = $this->normalize($value);
        $patterns = [
            'tactical_response_team_member' => [
                'trt member', 'tactical response team member', 'tactical responce team member',
                'anggota pasukan tindak balas', 'anggota tindak balas kecemasan',
            ],
            'emergency_response_team_member' => [
                'ertm', 'emergency response team member',
            ],
            'back_up_emergency_response_team_member' => [
                'bert', 'back up emergency response team member', 'backup emergency response team member',
            ],
            'on_scene_commander' => [
                'osc', 'on scene commander',
            ],
            'incident_controller' => [
                'ic', 'incident controller',
            ],
            'tactical_response_team' => [
                'trt', 'tactical response team', 'tactical responce team',
            ],
        ];

        return collect($patterns)
            ->filter(fn (array $aliases): bool => collect($aliases)->contains(
                fn (string $alias): bool => $this->containsPhrase($value, $alias),
            ))
            ->keys()
            ->values()
            ->all();
    }

    /** @return array<int, string> */
    public function expansionTerms(array $entities, array $facets): array
    {
        $entityTerms = collect($entities)->flatMap(fn (string $entity): array => match ($entity) {
            'tactical_response_team_member' => ['trt', 'tactical', 'response', 'team', 'member', 'ertm', 'emergency'],
            'emergency_response_team_member' => ['ertm', 'emergency', 'response', 'team', 'member', 'trt'],
            'back_up_emergency_response_team_member' => ['bert', 'backup', 'emergency', 'response', 'team', 'member'],
            'on_scene_commander' => ['osc', 'on', 'scene', 'commander'],
            'incident_controller' => ['ic', 'incident', 'controller'],
            'tactical_response_team' => ['trt', 'tactical', 'response', 'team'],
            default => preg_split('/[^\pL\pN]+/u', str_replace('_', ' ', $entity)) ?: [],
        });
        $facetTerms = collect($facets)->flatMap(fn (string $facet): array => match ($facet) {
            'role_responsibilities' => ['role', 'responsibilities', 'responsibility', 'duties', 'duty', 'tasks'],
            'qualifications' => ['qualification', 'qualifications', 'competency', 'certificate', 'experience'],
            'procedure_steps' => ['procedure', 'steps', 'process'],
            'requirements' => ['requirements', 'required', 'must', 'shall', 'criteria'],
            'numbers_capacity' => ['number', 'capacity', 'quantity', 'qty', 'total', 'minimum', 'maximum', 'per shift'],
            'contacts' => ['contact', 'telephone', 'phone', 'email'],
            'scope_coverage' => ['scope', 'coverage', 'area', 'perimeter'],
            'timing_frequency' => ['frequency', 'daily', 'weekly', 'monthly', 'yearly', 'duration', 'schedule', 'operating', 'hours', 'shift', '24/7'],
            'exceptions' => ['exception', 'except', 'unless'],
            'comparison' => ['compare', 'comparison', 'difference'],
            default => [],
        });

        return $entityTerms
            ->merge($facetTerms)
            ->map(fn ($term) => Str::lower(trim((string) $term)))
            ->filter(fn (string $term) => Str::length($term) >= 2)
            ->unique()
            ->values()
            ->all();
    }

    /** @return array<int, string> */
    public function aliasesForEntity(string $entity): array
    {
        return match ($entity) {
            'tactical_response_team_member' => [
                'tactical response team member', 'trt member', 'emergency response team member', 'ertm',
            ],
            'emergency_response_team_member' => [
                'emergency response team member', 'ertm', 'tactical response team member', 'trt member',
            ],
            'back_up_emergency_response_team_member' => [
                'back-up emergency response team member', 'backup emergency response team member', 'bert',
            ],
            'on_scene_commander' => ['on scene commander', 'on-scene commander', 'osc'],
            'incident_controller' => ['incident controller', 'ic'],
            'tactical_response_team' => ['tactical response team', 'trt'],
            default => [str_replace('_', ' ', $entity)],
        };
    }

    /** @return array<int, string> */
    public function unknownAcronyms(string $value, array $resolvedEntities): array
    {
        preg_match_all('/(?<![\pL\pN])([A-Z]{2,6})(?![\pL\pN])/u', $value, $matches);
        $ignored = [
            'what', 'who', 'when', 'where', 'which', 'how', 'can', 'could', 'does', 'did',
            'the', 'and', 'for', 'with', 'our', 'your', 'this', 'that', 'role', 'member',
            'apa', 'yang', 'dan', 'untuk', 'dalam', 'boleh',
        ];
        $known = $this->knownEntityAcronyms($resolvedEntities);

        return collect($matches[1] ?? [])
            ->map(fn (string $term) => Str::lower($term))
            ->reject(fn (string $term) => in_array($term, $ignored, true) || in_array($term, $known, true))
            ->unique()
            ->values()
            ->all();
    }

    /** @return array<int, string> */
    private function knownEntityAcronyms(array $entities): array
    {
        return collect($entities)->flatMap(fn (string $entity): array => match ($entity) {
            'tactical_response_team', 'tactical_response_team_member' => ['trt'],
            'emergency_response_team_member' => ['ertm'],
            'back_up_emergency_response_team_member' => ['bert'],
            'on_scene_commander' => ['osc'],
            'incident_controller' => ['ic'],
            default => [],
        })->unique()->values()->all();
    }

    private function normalize(string $value): string
    {
        $value = Str::lower(str_replace(['_', '-'], ' ', $value));

        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    private function containsPhrase(string $haystack, string $phrase): bool
    {
        return preg_match('/(?<![\pL\pN])'.preg_quote($phrase, '/').'(?![\pL\pN])/u', $haystack) === 1;
    }
}
