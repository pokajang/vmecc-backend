<?php

namespace App\Services;

use Illuminate\Support\Str;

class AiHelperKnowledgeQueryAnalyzer
{
    /** @return array<string, mixed> */
    public function analyze(string $message, array $previousUserMessages = []): array
    {
        $message = trim($message);
        $normalized = $this->normalize($message);
        $previous = collect($previousUserMessages)
            ->filter(fn ($value) => is_string($value) && trim($value) !== '')
            ->map(fn (string $value) => trim($value))
            ->take(-3)
            ->values()
            ->all();
        $followUp = $this->looksLikeFollowUp($normalized);
        $retrievalQuery = $followUp && $previous !== []
            ? implode("\n", array_merge($previous, [$message]))
            : $message;

        preg_match_all('/\b(?:annex(?:e)?|lampiran)\s*0*(\d{1,3})\b/i', $retrievalQuery, $annexMatches);
        preg_match_all('/\b(?:rev(?:ision)?[.\s:-]*)0*(\d{1,4})\b/i', $retrievalQuery, $revisionMatches);
        preg_match_all('/\b(?:[A-Z]{2,}(?:-[A-Z0-9]+){2,}|PRO-\d{4,})\b/i', $retrievalQuery, $codeMatches);

        return [
            'intent' => $this->intent($normalized),
            'message' => $message,
            'query' => trim($retrievalQuery),
            'normalized_query' => $this->normalize($retrievalQuery),
            'terms' => $this->terms($retrievalQuery),
            'subqueries' => $this->subqueries($retrievalQuery),
            'annex_numbers' => collect($annexMatches[1] ?? [])->map(fn ($value) => (int) $value)->unique()->values()->all(),
            'revisions' => collect($revisionMatches[1] ?? [])->map(fn ($value) => ltrim((string) $value, '0') ?: '0')->unique()->values()->all(),
            'document_codes' => collect($codeMatches[0] ?? [])->map(fn ($value) => Str::upper($value))->unique()->values()->all(),
            'follow_up' => $followUp,
            'requires_multiple_documents' => $this->requiresMultipleDocuments($normalized),
            'sensitive_request' => $this->isSensitiveRequest($normalized),
        ];
    }

    public function isCatalogueIntent(string $message): bool
    {
        $message = $this->normalize($message);
        $phrases = [
            'list all', 'list annex', 'list anex', 'show all', 'show annex', 'available annexes',
            'all files', 'all documents', 'knowledge documents', 'knowledge sources',
            'source documents', 'what documents', 'which documents', 'how many documents',
            'uploaded files', 'uploaded documents', 'uploaded guidance', 'guidance has been uploaded',
            'senarai', 'semua fail', 'semua dokumen', 'semua lampiran',
            'senarai lampiran', 'dokumen pengetahuan', 'dokumen rujukan', 'dokumen yang dimuat naik',
        ];

        return collect($phrases)->contains(fn (string $phrase) => str_contains($message, $phrase))
            || preg_match('/\bberapa\s+(?:dokumen|fail|lampiran|sumber)\b/u', $message) === 1;
    }

    private function intent(string $message): string
    {
        if ($this->isCatalogueIntent($message)) {
            return 'catalogue';
        }
        if (preg_match('/^(?:hi|hello|hey|thanks|thank you|terima kasih|hai)[.!?\s]*$/u', $message) === 1) {
            return 'casual';
        }
        if (preg_match('/\b(?:what can i do here|help (?:me )?(?:with|on) this page|how do i use this page)\b/u', $message) === 1) {
            return 'general_help';
        }

        return 'knowledge_question';
    }

    /** @return array<int, string> */
    public function terms(string $value): array
    {
        return collect(preg_split('/[^\pL\pN]+/u', $this->normalize($value)) ?: [])
            ->filter(fn (string $term) => Str::length($term) >= 2)
            ->reject(fn (string $term) => in_array($term, [
                'the', 'and', 'for', 'with', 'what', 'when', 'where', 'which', 'how', 'is',
                'are', 'was', 'were', 'a', 'an', 'of', 'to', 'in', 'on', 'does', 'do', 'did',
                'according', 'please', 'tell', 'explain',
                'yang', 'dan', 'untuk', 'dengan', 'apa', 'bila', 'mana', 'adalah',
            ], true))
            ->unique()
            ->take(32)
            ->values()
            ->all();
    }

    private function normalize(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', Str::lower($value)));
    }

    private function looksLikeFollowUp(string $message): bool
    {
        if (Str::length($message) <= 45 && preg_match('/\b(it|that|those|them|this|next|previous|above|itu|tersebut|seterusnya|tadi)\b/u', $message)) {
            return true;
        }

        return preg_match('/^(and|also|then|what about|how about|dan|juga|kemudian|bagaimana pula)\b/u', $message) === 1;
    }

    private function requiresMultipleDocuments(string $message): bool
    {
        return preg_match('/\b(compare|comparison|across|all annex|differences?|banding|perbezaan|semua lampiran)\b/u', $message) === 1;
    }

    private function isSensitiveRequest(string $message): bool
    {
        return preg_match('/\b(password|passcode|passphrase|secret|api[ -]?key|access[ -]?token|private[ -]?key|kata laluan|kunci api|token akses)\b/u', $message) === 1;
    }

    /** @return array<int, string> */
    private function subqueries(string $query): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $parts = preg_split(
            '/\s*(?:;|\?|,(?=\s*(?:what|who|which|where|when|how|apa|siapa|mana|bila|bagaimana)\b)|'.
            '\b(?:and|dan)\s+(?=(?:what|who|which|where|when|how|apa|siapa|mana|bila|bagaimana|'.
            'the\s+(?:number|capacity|role|time)|nombor|kapasiti|peranan|masa)\b))\s*/iu',
            $query,
        ) ?: [];
        $parts = collect($parts)
            ->map(fn (string $part) => trim($part, " \t\n\r\0\x0B,.?"))
            ->filter(fn (string $part) => Str::length($part) >= 8)
            ->unique()
            ->take(6)
            ->values();

        return $parts->count() > 1 ? $parts->all() : [$query];
    }
}
