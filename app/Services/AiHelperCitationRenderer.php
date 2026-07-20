<?php

namespace App\Services;

final class AiHelperCitationRenderer
{
    /**
     * Citation-only repair is intentionally limited to one source. With more
     * than one source, assigning a missing marker would be evidence inference.
     *
     * @param  array<int, array<string, mixed>>  $sources
     */
    public function repairSingleSource(string $content, array $sources): ?string
    {
        $sourceIds = collect($sources)
            ->pluck('source_id')
            ->filter(fn ($id) => is_string($id) && preg_match('/^S\d+$/', $id) === 1)
            ->unique()
            ->values();
        if ($sourceIds->count() !== 1 || trim($content) === '') {
            return null;
        }

        $sourceId = (string) $sourceIds->first();
        $blocks = preg_split('/\R{2,}/u', trim($content)) ?: [];
        $repaired = collect($blocks)->map(function (string $block) use ($sourceId): string {
            $block = trim($block);
            if ($block === '' || preg_match('/\[S\d+(?:\s*,\s*S\d+)*]/', $block) === 1) {
                return $block;
            }
            if (preg_match('/^#{1,6}\s/u', $block) === 1) {
                return $block;
            }

            return rtrim($block)." [{$sourceId}]";
        })->filter()->join("\n\n");

        return $repaired !== trim($content) ? $repaired : null;
    }
}
