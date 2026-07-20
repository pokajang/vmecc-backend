<?php

namespace App\Services;

use Illuminate\Support\Str;

class AiHelperCriticalFactValidator
{
    /**
     * @param  array<int, array<string, mixed>>  $guidance
     * @return array{valid: bool, status: string, failures: array<int, array<string, mixed>>}
     */
    public function validate(string $answer, array $guidance, string $question = ''): array
    {
        if (! (bool) config('ai_helper.critical_fact_validation_enabled', true)) {
            return ['valid' => true, 'status' => 'disabled', 'failures' => []];
        }

        $evidence = collect($guidance)
            ->groupBy(fn (array $item) => (string) ($item['source_id'] ?? ''))
            ->map(fn ($items) => Str::lower($items->map(fn (array $item) => trim(
                (string) ($item['title'] ?? '')."\n".(string) ($item['content'] ?? '')
            ))->join("\n")));
        $blocks = collect(preg_split('/\R{2,}/u', trim($answer)) ?: [])
            ->map(fn (string $block) => trim($block))
            ->filter()
            ->values();
        $failures = [];
        $previousSourceIds = [];

        foreach ($blocks as $index => $block) {
            $sourceIds = $this->citationIds($block);
            if ($sourceIds === [] && preg_match('/^(?:[-*+] |\d+[.)] )/m', $block) === 1) {
                $sourceIds = $previousSourceIds;
            }
            if ($sourceIds === []) {
                continue;
            }
            $previousSourceIds = $sourceIds;
            $sourceEvidence = collect($sourceIds)
                ->map(fn (string $sourceId) => (string) ($evidence[$sourceId] ?? ''))
                ->join("\n");

            foreach ($this->criticalTokens($block) as $token) {
                if (! $this->evidenceContains($sourceEvidence, $token)) {
                    $failures[] = [
                        'type' => 'unsupported_critical_token',
                        'block' => $index,
                        'token' => $token,
                        'source_ids' => $sourceIds,
                    ];
                }
            }
        }

        if ($this->requiresUnstatedRevisionLabel($question, $guidance)
            && preg_match('/\b(?:revision (?:is )?not stated|no revision (?:is )?stated)\b/iu', $answer) !== 1) {
            $failures[] = [
                'type' => 'missing_revision_status_label',
                'required' => 'Label the source without a revision marker as "revision not stated".',
            ];
        }

        return [
            'valid' => $failures === [],
            'status' => $failures === [] ? 'validated' : 'rejected',
            'failures' => $failures,
        ];
    }

    /** @return array<int, string> */
    private function citationIds(string $content): array
    {
        preg_match_all('/\bS(\d+)\b/', $content, $matches);

        return collect($matches[1] ?? [])
            ->map(fn ($number) => 'S'.(int) $number)
            ->unique()
            ->values()
            ->all();
    }

    /** @return array<int, string> */
    private function criticalTokens(string $block): array
    {
        $plain = preg_replace([
            '/\[S\d+(?:\s*,\s*S\d+)*]/i',
            '/^\s*(?:[-*+] |\d+[.)]\s+)/m',
        ], '', $block) ?? $block;
        preg_match_all(
            '/\b(?:[A-Z]{2,}(?:-[A-Z0-9]+){2,}|PRO-\d{4,})\b|'.
            '\bRM\s*\d+(?:,\d{3})*(?:\.\d{1,2})?\b|'.
            '\b\d{1,2}[\/-]\d{1,2}[\/-]\d{2,4}\b|'.
            '\b\d{1,2}:\d{2}(?:\s*(?:am|pm|pagi|petang|malam))?\b|'.
            '\b\d+(?:\.\d+)?\s*(?:seconds?|secs?|minutes?|mins?|hours?|hrs?|days?|'.
            'casualties?|persons?|people|members?|teams?|metres?|meters?|kilometres?|kilometers?|km|'.
            'saat|minit|jam|hari|mangsa|orang|ahli|pasukan|meter|kilometer|peratus|percent)\b|'.
            '\b\d+(?:\.\d+)?\s*%|'.
            '\b\d{3,}(?:[\s-]\d{2,})*\b/iu',
            $plain,
            $matches,
        );

        return collect($matches[0] ?? [])
            ->map(fn (string $token) => trim($token))
            ->filter()
            ->unique(fn (string $token) => Str::lower($token))
            ->values()
            ->all();
    }

    private function evidenceContains(string $evidence, string $token): bool
    {
        $normalize = function (string $value): string {
            $value = Str::lower($value);
            $value = (string) preg_replace('/(?<=\d),(?=\d{3}\b)/u', '', $value);
            $value = (string) preg_replace('/\brm\s+(?=\d)/u', 'rm', $value);
            $unitFamilies = [
                '/\b(?:seconds?|secs?|saat)\b/u' => 'second',
                '/\b(?:minutes?|mins?|minit)\b/u' => 'minute',
                '/\b(?:hours?|hrs?|jam)\b/u' => 'hour',
                '/\b(?:days?|hari)\b/u' => 'day',
                '/\b(?:casualties?|mangsa)\b/u' => 'casualty',
                '/\b(?:persons?|people|orang)\b/u' => 'person',
                '/\b(?:members?|ahli)\b/u' => 'member',
                '/\b(?:teams?|pasukan)\b/u' => 'team',
                '/\b(?:metres?|meters?|meter)\b/u' => 'meter',
                '/\b(?:kilometres?|kilometers?|kilometer|km)\b/u' => 'kilometer',
                '/\b(?:percent|peratus)\b|%/u' => 'percent',
            ];
            foreach ($unitFamilies as $pattern => $replacement) {
                $value = (string) preg_replace($pattern, $replacement, $value);
            }

            return trim((string) preg_replace('/\s+/u', ' ', $value));
        };
        $normalizedEvidence = $normalize($evidence);
        $normalizedToken = $normalize($token);
        if (str_contains($normalizedEvidence, $normalizedToken)) {
            return true;
        }

        $digits = preg_replace('/\D+/', '', $token) ?? '';

        return strlen($digits) >= 3
            && str_contains((string) preg_replace('/\D+/', '', $evidence), $digits);
    }

    /** @param array<int, array<string, mixed>> $guidance */
    private function requiresUnstatedRevisionLabel(string $question, array $guidance): bool
    {
        if (preg_match('/\b(?:revision|revisions|authoritative|authority|supersed(?:e|es|ed))\b/iu', $question) !== 1) {
            return false;
        }

        $titles = collect($guidance)
            ->pluck('title')
            ->map(fn ($title) => trim((string) $title))
            ->filter()
            ->unique()
            ->values();
        if ($titles->count() < 2) {
            return false;
        }

        $hasRevisionedTitle = $titles->contains(
            fn (string $title) => preg_match('/\brev(?:ision)?[\s._-]*\d+\b/iu', $title) === 1,
        );
        $hasUnrevisionedTitle = $titles->contains(
            fn (string $title) => preg_match('/\brev(?:ision)?[\s._-]*\d+\b/iu', $title) !== 1,
        );

        return $hasRevisionedTitle && $hasUnrevisionedTitle;
    }
}
