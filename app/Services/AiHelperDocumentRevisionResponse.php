<?php

namespace App\Services;

use App\Models\AiHelperKnowledgeEntry;
use Illuminate\Support\Str;

final class AiHelperDocumentRevisionResponse
{
    public function render(array $contextEnvelope, string $responseLanguage = 'auto'): ?string
    {
        $message = trim((string) data_get($contextEnvelope, 'query_analysis.message', ''));
        if (! $this->asksForAvailableRevisions($message)) {
            return null;
        }

        $sources = collect((array) ($contextEnvelope['guidance'] ?? []))
            ->filter(fn (array $item): bool => ($item['source_type'] ?? $item['knowledge_type'] ?? null)
                === AiHelperKnowledgeEntry::KNOWLEDGE_REFERENCE_DOCUMENT)
            ->filter(fn (array $item): bool => preg_match('/^S\d+$/', (string) ($item['source_id'] ?? '')) === 1)
            ->map(fn (array $item): array => [
                'source_id' => (string) $item['source_id'],
                'title' => trim((string) ($item['title'] ?? '')),
            ])
            ->filter(fn (array $item): bool => $item['title'] !== '')
            ->unique('title')
            ->values();
        if ($sources->isEmpty()) {
            return null;
        }

        $useBahasaMelayu = $responseLanguage === 'bm'
            || ($responseLanguage === 'auto'
                && preg_match('/\b(?:apa|apakah|mana|yang|tersedia|ada|disenaraikan|versi|revisi)\b/iu', $message) === 1);
        $intro = $useBahasaMelayu
            ? 'Versi sumber yang tersedia ialah:'
            : 'The available source revisions are:';
        $lines = $sources->map(function (array $source, int $index) use ($useBahasaMelayu): string {
            $title = str_replace('`', '\\`', $source['title']);
            $revision = $this->revisionLabel($source['title']);
            $status = $revision ?? ($useBahasaMelayu ? 'revisi tidak dinyatakan' : 'revision not stated');

            return sprintf(
                '%d. `%s` — **%s** [%s]',
                $index + 1,
                $title,
                $status,
                $source['source_id'],
            );
        });

        return $intro."\n\n".$lines->join("\n");
    }

    private function asksForAvailableRevisions(string $message): bool
    {
        $message = Str::lower(str_replace(['_', '-'], ' ', $message));
        $message = trim((string) preg_replace('/\s+/u', ' ', $message));

        return preg_match(
            '/(?:\b(?:which|what|list|show)\b.*\b(?:revisions?|versions?)\b.*\b(?:available|exist|listed)\b'
            .'|\bavailable\b.*\b(?:revisions?|versions?)\b'
            .'|\b(?:revisions?|versions?|revisi|versi)\b.*\b(?:apa|mana|tersedia|ada|disenaraikan)\b)/u',
            $message,
        ) === 1;
    }

    private function revisionLabel(string $title): ?string
    {
        if (preg_match('/\brev(?:ision)?[.\s:_-]*([0-9]{1,4})\b/iu', $title, $matches) !== 1) {
            return null;
        }

        return 'Rev '.$matches[1];
    }
}
