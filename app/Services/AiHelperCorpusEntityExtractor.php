<?php

namespace App\Services;

use App\Models\AiHelperKnowledgeChunk;
use Illuminate\Support\Str;

final class AiHelperCorpusEntityExtractor
{
    /**
     * @return array<int, array{canonical_name: string, normalized_name: string, entity_type: string, confidence: float, aliases: array<int, array{alias: string, alias_type: string, language: ?string}>}>
     */
    public function extract(AiHelperKnowledgeChunk $chunk): array
    {
        $heading = collect($chunk->heading_path ?? [])->filter()->join("\n");
        $content = (string) $chunk->content;
        $text = $heading."\n".$content;
        $entities = collect();

        preg_match_all(
            '/(?<![\pL\pN])([A-Z][A-Za-z&\/-]*(?:[ \t]+[A-Z][A-Za-z&\/-]*){1,11})[ \t]*\(([A-Z][A-Z0-9]{1,9})\)/u',
            $text,
            $pairs,
            PREG_SET_ORDER,
        );
        foreach ($pairs as $pair) {
            $canonical = $this->cleanName((string) $pair[1]);
            $acronym = trim((string) $pair[2]);
            if (! $this->credibleName($canonical)) {
                continue;
            }
            $entities->push($this->entity(
                $canonical,
                $this->typeFor($canonical),
                1.0,
                [
                    ['alias' => $canonical, 'alias_type' => 'canonical', 'language' => null],
                    ['alias' => $acronym, 'alias_type' => 'acronym', 'language' => null],
                ],
            ));
        }

        preg_match_all(
            '/(?<![\pL\pN])([A-Z][A-Z0-9]{1,9})[ \t]*(?:-|–|—|:)[ \t]*([A-Z][A-Za-z&\/-]*(?:[ \t]+[A-Z][A-Za-z&\/-]*){1,11})/u',
            $text,
            $reversePairs,
            PREG_SET_ORDER,
        );
        foreach ($reversePairs as $pair) {
            $canonical = $this->cleanName((string) $pair[2]);
            if (! $this->credibleName($canonical)) {
                continue;
            }
            $entities->push($this->entity(
                $canonical,
                $this->typeFor($canonical),
                0.98,
                [
                    ['alias' => $canonical, 'alias_type' => 'canonical', 'language' => null],
                    ['alias' => trim((string) $pair[1]), 'alias_type' => 'acronym', 'language' => null],
                ],
            ));
        }

        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $candidate = null;
            if (preg_match('/^\s*\|\s*([^|]{3,120})\s*\|/u', $line, $match) === 1) {
                $candidate = trim((string) $match[1]);
            } elseif (preg_match('/^\s*(?:#{1,6}\s*)?([A-Z][A-Z0-9&\/(). -]{3,120})\s*$/u', $line, $match) === 1) {
                $candidate = trim((string) $match[1]);
            }
            if ($candidate === null || ! $this->looksLikeStructuredEntity($candidate)) {
                continue;
            }
            $canonical = $this->cleanName((string) preg_replace('/\s*\([A-Z][A-Z0-9]{1,9}\)\s*$/u', '', $candidate));
            if (! $this->credibleName($canonical)) {
                continue;
            }
            $entities->push($this->entity(
                Str::title(Str::lower($canonical)),
                $this->typeFor($canonical),
                0.9,
                [
                    ['alias' => $canonical, 'alias_type' => 'structured', 'language' => null],
                    ...$this->derivedRoleAliases($canonical),
                ],
            ));
        }

        preg_match_all('/(?<![\pL\pN])([A-Z]{2,}(?:-[A-Z0-9]+){2,}|PRO-\d{4,})(?![\pL\pN])/u', $text, $codes);
        foreach ($codes[1] ?? [] as $code) {
            $entities->push($this->entity(
                (string) $code,
                'document_code',
                1.0,
                [['alias' => (string) $code, 'alias_type' => 'document_code', 'language' => null]],
            ));
        }

        return $entities
            ->filter(fn (array $entity) => $entity['normalized_name'] !== '')
            ->groupBy(fn (array $entity) => $entity['entity_type'].'|'.$entity['normalized_name'])
            ->map(function ($matches): array {
                $best = $matches->sortByDesc('confidence')->first();
                $best['aliases'] = $matches
                    ->flatMap(fn (array $match) => $match['aliases'])
                    ->unique(fn (array $alias) => $this->normalize($alias['alias']))
                    ->values()
                    ->all();

                return $best;
            })
            ->values()
            ->all();
    }

    private function looksLikeStructuredEntity(string $value): bool
    {
        return preg_match(
            '/\b(?:TEAM|MEMBER|COMMANDER|CONTROLLER|COORDINATOR|LEADER|OFFICER|MANAGER|SUPERVISOR|COMMITTEE|PROCEDURE|PLAN|SYSTEM|EQUIPMENT|VEHICLE|REPORT|FORM|PASUKAN|ANGGOTA|KOMANDER|PENGAWAL|PENYELARAS|KETUA|PEGAWAI|PENGURUS|PENYELIA|PROSEDUR|PELAN|SISTEM|LAPORAN|BORANG)\b/u',
            Str::upper($value),
        ) === 1;
    }

    private function typeFor(string $value): string
    {
        $value = Str::lower($value);
        if (preg_match('/\b(?:member|commander|controller|coordinator|leader|officer|manager|supervisor|anggota|komander|pengawal|penyelaras|ketua|pegawai|pengurus|penyelia)\b/u', $value) === 1) {
            return 'role';
        }
        if (preg_match('/\b(?:team|committee|pasukan|jawatankuasa)\b/u', $value) === 1) {
            return 'team';
        }
        if (preg_match('/\b(?:procedure|plan|prosedur|pelan)\b/u', $value) === 1) {
            return 'procedure';
        }
        if (preg_match('/\b(?:equipment|vehicle|truck|extinguisher|peralatan|kenderaan|pemadam)\b/u', $value) === 1) {
            return 'equipment';
        }
        if (preg_match('/\b(?:report|form|record|laporan|borang|rekod)\b/u', $value) === 1) {
            return 'form_or_record';
        }

        return 'organization';
    }

    private function entity(string $canonical, string $type, float $confidence, array $aliases): array
    {
        $canonical = $this->cleanName($canonical);

        return [
            'canonical_name' => $canonical,
            'normalized_name' => $this->normalize($canonical),
            'entity_type' => $type,
            'confidence' => $confidence,
            'aliases' => $aliases,
        ];
    }

    /** @return array<int, array{alias: string, alias_type: string, language: ?string}> */
    private function derivedRoleAliases(string $canonical): array
    {
        $words = collect(preg_split('/\s+/u', Str::upper(trim($canonical))) ?: [])
            ->filter(fn (string $word) => preg_match('/^[A-Z][A-Z0-9]*$/', $word) === 1)
            ->values();
        if ($words->count() < 3 || ! in_array($words->last(), ['MEMBER', 'ANGGOTA'], true)) {
            return [];
        }
        $acronym = $words
            ->slice(0, -1)
            ->map(fn (string $word) => Str::substr($word, 0, 1))
            ->join('');
        if (Str::length($acronym) < 2 || Str::length($acronym) > 8) {
            return [];
        }

        return [
            ['alias' => $acronym, 'alias_type' => 'derived_acronym', 'language' => null],
            ['alias' => $acronym.' member', 'alias_type' => 'derived_role_alias', 'language' => 'en'],
        ];
    }

    private function cleanName(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', trim($value, " \t\n\r\0\x0B|:;,.–—-")));
    }

    private function credibleName(string $value): bool
    {
        $words = preg_split('/\s+/u', trim($value)) ?: [];

        return count($words) >= 2
            && count($words) <= 12
            && Str::length($value) >= 5
            && Str::length($value) <= 180
            && preg_match('/^(?:activate|mobilize|notify|call|prepare|contact|advise|ensure|conduct|review|set|always|assist|perform|carry|follow|as per)\b/iu', $value) !== 1;
    }

    private function normalize(string $value): string
    {
        $value = Str::lower(str_replace(['_', '-', '–', '—'], ' ', $value));

        return trim((string) preg_replace('/[^\pL\pN]+/u', ' ', $value));
    }
}
