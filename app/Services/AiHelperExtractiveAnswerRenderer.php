<?php

namespace App\Services;

use Illuminate\Support\Str;

final class AiHelperExtractiveAnswerRenderer
{
    /**
     * Return a bounded verbatim extract when generated output cannot safely be
     * delivered. The renderer never combines source prose into a new claim.
     *
     * @param  array<int, array<string, mixed>>  $guidance
     * @param  array<int, array<string, mixed>>  $sources
     * @return array{content: string, sources: array<int, array<string, mixed>>}|null
     */
    public function render(array $guidance, array $sources, string $responseLanguage, string $reason): ?array
    {
        $sourceMap = collect($sources)
            ->filter(fn (array $source) => preg_match('/^S\d+$/', (string) ($source['source_id'] ?? '')) === 1)
            ->keyBy('source_id');
        if ($sourceMap->isEmpty()) {
            return null;
        }

        $extracts = collect($guidance)
            ->filter(fn (array $item) => $sourceMap->has((string) ($item['source_id'] ?? '')))
            ->map(function (array $item): ?array {
                $content = $this->boundedExtract((string) ($item['content'] ?? ''));
                if ($content === '') {
                    return null;
                }

                return [
                    'source_id' => (string) $item['source_id'],
                    'content' => $content,
                ];
            })
            ->filter()
            ->unique('source_id')
            ->take(2)
            ->values();
        if ($extracts->isEmpty()) {
            return null;
        }

        $lead = $this->lead($responseLanguage, $reason);
        $content = $lead."\n\n".$extracts
            ->map(fn (array $extract) => '> '.str_replace("\n", "\n> ", $extract['content']).' ['.$extract['source_id'].']')
            ->join("\n\n");
        $usedIds = $extracts->pluck('source_id');

        return [
            'content' => $content,
            'sources' => $sourceMap->only($usedIds->all())->values()->all(),
        ];
    }

    private function boundedExtract(string $content): string
    {
        $content = trim((string) preg_replace('/\A---\R.*?\R---\R/su', '', $content));
        $lines = preg_split('/\R/u', $content) ?: [];
        $content = collect($lines)
            ->map(fn (string $line) => rtrim($line))
            ->reject(fn (string $line) => preg_match('/^\s*#{1,6}\s+/u', $line) === 1)
            ->reject(fn (string $line) => preg_match('/^\s*\|?\s*:?-{3,}:?\s*(?:\|\s*:?-{3,}:?\s*)+\|?\s*$/u', $line) === 1)
            ->reject(fn (string $line) => substr_count($line, '|') >= 2)
            ->join("\n");
        $content = strip_tags($content);
        $content = (string) preg_replace('/\[([^]]+)]\([^)]*\)/u', '$1', $content);
        $content = (string) preg_replace('/\[S\d+(?:\s*,\s*S\d+)*]/iu', '', $content);
        $content = trim((string) preg_replace('/[ \t]+/u', ' ', $content));
        $content = trim((string) preg_replace('/\R{3,}/u', "\n\n", $content));
        if ($content === '') {
            return '';
        }
        if (Str::length($content) <= 900) {
            return $content;
        }

        $bounded = Str::limit($content, 900, '');
        $sentenceEnd = max(
            (int) mb_strrpos($bounded, '. '),
            (int) mb_strrpos($bounded, '! '),
            (int) mb_strrpos($bounded, '? '),
        );
        $lineEnd = (int) mb_strrpos($bounded, "\n");
        $safeEnd = max($sentenceEnd >= 160 ? $sentenceEnd + 1 : 0, $lineEnd >= 160 ? $lineEnd : 0);

        return trim($safeEnd > 0 ? mb_substr($bounded, 0, $safeEnd) : $bounded).'…';
    }

    private function lead(string $language, string $reason): string
    {
        if ($language === 'bm') {
            return match ($reason) {
                'evidence_incomplete' => 'Saya tidak dapat mengesahkan jawapan yang lengkap. Berikut ialah petikan terus daripada panduan diluluskan yang ditemui:',
                'validation_failed' => 'Saya menemui panduan diluluskan yang berkaitan, tetapi ringkasan yang dijana tidak dapat disahkan dengan selamat. Berikut ialah panduan sokongan secara terus:',
                default => 'Pengesahan tambahan tidak dapat diselesaikan. Berikut ialah petikan terus daripada panduan diluluskan yang ditemui:',
            };
        }

        return match ($reason) {
            'evidence_incomplete' => 'I could not verify a complete answer. Here is a direct extract from the approved guidance that was found:',
            'validation_failed' => 'I found relevant approved guidance, but could not safely deliver the generated summary. Here is the supporting guidance directly:',
            default => 'Additional verification could not be completed. Here is a direct extract from the approved guidance that was found:',
        };
    }
}
