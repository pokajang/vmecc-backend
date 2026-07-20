<?php

namespace App\Services;

use Illuminate\Support\Str;

class AiHelperCitationValidator
{
    /**
     * @param  array<int, array<string, mixed>>  $sources
     * @return array{valid: bool, status: string, reason: ?string, cited_source_ids: array<int, string>, uncited_blocks: array<int, int>, unknown_source_ids: array<int, string>}
     */
    public function validate(string $content, array $sources): array
    {
        if (! (bool) config('ai_helper.citation_validation_enabled', true)) {
            return $this->result(true, 'disabled');
        }
        if (trim($content) === '') {
            return $this->result(false, 'rejected', 'empty_response');
        }

        $allowed = collect($sources)
            ->pluck('source_id')
            ->filter(fn ($sourceId) => is_string($sourceId) && preg_match('/^S\d+$/', $sourceId))
            ->unique()
            ->values();
        $cited = collect($this->citationIds($content));
        if ($allowed->isEmpty()) {
            if ($cited->isNotEmpty()) {
                return $this->result(false, 'rejected', 'unknown_source', $cited->all(), [], $cited->all());
            }

            return $this->result(true, 'not_required');
        }

        $unknown = $cited->diff($allowed)->values();
        if ($unknown->isNotEmpty()) {
            return $this->result(false, 'rejected', 'unknown_source', $cited->all(), [], $unknown->all());
        }

        $blocks = collect(preg_split('/\R{2,}/u', trim($content)) ?: [])
            ->map(fn (string $block) => trim($block))
            ->filter()
            ->values();
        foreach ($blocks as $index => $block) {
            if ($this->hasMisattributedRevision($block, $sources)) {
                return $this->result(false, 'rejected', 'misattributed_revision', $cited->all(), [$index]);
            }
        }
        $uncited = [];
        foreach ($blocks as $index => $block) {
            if (! $this->isMaterial($block) || $this->hasCitation($block)) {
                continue;
            }
            $previous = $index > 0 ? (string) $blocks[$index - 1] : '';
            $next = $index + 1 < $blocks->count() ? (string) $blocks[$index + 1] : '';
            $isList = preg_match('/^(?:[-*+] |\d+[.)] )/m', $block) === 1;
            $introducesCitedGroup = str_ends_with(rtrim($block), ':') && $this->hasCitation($next);
            $belongsToCitedGroup = $isList && $this->hasCitation($previous);
            if (! $introducesCitedGroup && ! $belongsToCitedGroup) {
                $uncited[] = $index;
            }
        }

        if ($uncited !== []) {
            return $this->result(false, 'rejected', 'uncited_operational_content', $cited->all(), $uncited);
        }
        if ($cited->isEmpty() && ! $blocks->every(fn (string $block) => ! $this->isMaterial($block))) {
            return $this->result(false, 'rejected', 'missing_citation');
        }

        return $this->result(true, 'validated', null, $cited->all());
    }

    /**
     * @param  array<int, array<string, mixed>>  $sources
     * @return array{content: string, sources: array<int, array<string, mixed>>, rejected: bool, validation: array<string, mixed>}
     */
    public function enforce(string $content, array $sources, string $responseLanguage = 'en'): array
    {
        $validation = $this->validate($content, $sources);
        if ($validation['valid']) {
            return [
                'content' => $content,
                'sources' => $sources,
                'rejected' => false,
                'validation' => $validation,
            ];
        }

        return [
            'content' => $this->rejectionMessage($responseLanguage),
            'sources' => [],
            'rejected' => true,
            'validation' => $validation,
        ];
    }

    private function isMaterial(string $block): bool
    {
        $plain = trim((string) preg_replace([
            '/\[S\d+]/',
            '/[`*_>#]/',
            '/\[(.*?)]\([^)]*\)/',
        ], ['$1', '', '$1'], $block));
        if ($plain === '' || Str::length($plain) < 12 || preg_match('/^#{1,6}\s/u', $block)) {
            return false;
        }

        return preg_match(
            '/^(?:if you (?:want|need)|let me know|would you like|please (?:clarify|ask)|'
            .'i could not verify a complete answer|additional verification could not be completed|'
            .'here(?: is|\'s|’s) (?:a |an )?(?:(?:brief|concise|focused|side-by-side) )?(?:comparison|summary|overview)|'
            .'below is (?:a |an )?(?:comparison|summary|overview)|'
            .'(?:no\.? )?the available (?:knowledge|guidance|sources?) does not (?:establish|identify|state|show)|'
            .'jika anda|sekiranya anda|sila (?:jelaskan|tanya)|saya boleh bantu|'
            .'saya tidak dapat mengesahkan jawapan yang lengkap|pengesahan tambahan tidak dapat diselesaikan|'
            .'(?:the )?(?:answer|information|detail) (?:was )?(?:not found|not available)|'
            .'i (?:could not|couldn\'t|cannot|can\'t) (?:find|determine|provide)|'
            .'maklumat (?:tidak|tiada)|jawapan (?:tidak|tiada))/iu',
            $plain,
        ) !== 1;
    }

    private function hasCitation(string $block): bool
    {
        return $this->citationIds($block) !== [];
    }

    /** @return array<int, string> */
    private function citationIds(string $content): array
    {
        preg_match_all('/\[([^]]+)]/', $content, $brackets);

        return collect($brackets[1] ?? [])
            ->filter(fn (string $value) => preg_match('/^S\d+(?:\s*,\s*S\d+)*$/', trim($value)) === 1)
            ->flatMap(function (string $value) {
                preg_match_all('/S(\d+)/', $value, $matches);

                return array_map(fn ($number) => 'S'.(int) $number, $matches[1] ?? []);
            })
            ->unique()
            ->values()
            ->all();
    }

    /** @param array<int, array<string, mixed>> $sources */
    private function hasMisattributedRevision(string $block, array $sources): bool
    {
        $citedIds = $this->citationIds($block);
        if ($citedIds === []) {
            return false;
        }
        $citedTitles = collect($sources)
            ->filter(fn (array $source) => in_array($source['source_id'] ?? null, $citedIds, true))
            ->pluck('title')
            ->map(fn ($title) => Str::lower((string) $title));
        preg_match_all('/\brev(?:ision)?[.\s:-]*0*(\d{1,4})\b/i', $block, $revisionMatches);
        foreach (array_unique($revisionMatches[1] ?? []) as $revision) {
            if (! $citedTitles->contains(
                fn (string $title) => preg_match('/\brev(?:ision)?[.\s:-]*0*'.preg_quote((string) (int) $revision, '/').'\b/i', $title) === 1
            )) {
                return true;
            }
        }
        if (preg_match('/\b(?:revision not stated|no revision stated|revision is not stated)\b/i', $block)
            && ! $citedTitles->contains(fn (string $title) => preg_match('/\brev(?:ision)?[.\s:-]*\d+\b/i', $title) !== 1)) {
            return true;
        }

        return false;
    }

    private function rejectionMessage(string $responseLanguage): string
    {
        if ($responseLanguage === 'bm') {
            return 'Saya tidak dapat memberikan jawapan yang mempunyai rujukan mencukupi daripada panduan VMECC yang tersedia. Nyatakan halaman, urusan, prosedur atau dokumen yang dimaksudkan dan cuba lagi.';
        }

        return 'I could not provide a sufficiently sourced answer from the available VMECC guidance. Please name the page, task, procedure, or document you mean and try again.';
    }

    /** @return array{valid: bool, status: string, reason: ?string, cited_source_ids: array<int, string>, uncited_blocks: array<int, int>, unknown_source_ids: array<int, string>} */
    private function result(
        bool $valid,
        string $status,
        ?string $reason = null,
        array $citedSourceIds = [],
        array $uncitedBlocks = [],
        array $unknownSourceIds = [],
    ): array {
        return [
            'valid' => $valid,
            'status' => $status,
            'reason' => $reason,
            'cited_source_ids' => $citedSourceIds,
            'uncited_blocks' => $uncitedBlocks,
            'unknown_source_ids' => $unknownSourceIds,
        ];
    }
}
