<?php

namespace App\Services;

use Illuminate\Support\Str;

class AiHelperKnowledgeQueryAnalyzer
{
    private readonly AiHelperTopicAliasRegistry $topics;

    public function __construct(?AiHelperTopicAliasRegistry $topics = null)
    {
        $this->topics = $topics ?? new AiHelperTopicAliasRegistry;
    }

    /** @return array<string, mixed> */
    public function analyze(string $message, array $previousUserMessages = []): array
    {
        $message = trim($message);
        $normalized = $this->normalize($message);
        $currentTopics = $this->topics->topicKeys($normalized);
        // An explicit new topic always wins over conversational history. Only
        // genuinely elliptical follow-ups inherit the latest user question.
        $followUp = $currentTopics === [] && $this->looksLikeFollowUp($normalized);
        $previous = collect($previousUserMessages)
            ->filter(fn ($value) => is_string($value) && trim($value) !== '')
            ->map(fn (string $value) => trim($value))
            ->last();
        $retrievalQuery = $followUp && is_string($previous)
            ? $previous."\n".$message
            : $message;
        $normalizedQuery = $this->normalize($retrievalQuery);
        $topicKeys = $this->topics->topicKeys($normalizedQuery);
        $contextDependency = $this->contextDependency($normalized, $currentTopics);

        preg_match_all('/\b(?:annex(?:e)?|lampiran)\s*0*(\d{1,3})\b/i', $retrievalQuery, $annexMatches);
        preg_match_all('/\b(?:rev(?:ision)?[.\s:-]*)0*(\d{1,4})\b/i', $retrievalQuery, $revisionMatches);
        preg_match_all('/\b(?:[A-Z]{2,}(?:-[A-Z0-9]+){2,}|PRO-\d{4,})\b/i', $retrievalQuery, $codeMatches);

        $plan = new AiHelperQueryPlan(
            intent: $this->intent($normalized),
            sourceMode: $this->sourceMode($normalized),
            contextDependency: $contextDependency,
            language: $this->language($normalized),
            message: $message,
            query: trim($retrievalQuery),
            normalizedQuery: $normalizedQuery,
            terms: $this->terms($retrievalQuery),
            expandedTerms: $this->topics->expandedTerms($topicKeys),
            topicKeys: $topicKeys,
            subqueries: $this->subqueries($retrievalQuery),
            annexNumbers: collect($annexMatches[1] ?? [])->map(fn ($value) => (int) $value)->unique()->values()->all(),
            revisions: collect($revisionMatches[1] ?? [])->map(fn ($value) => ltrim((string) $value, '0') ?: '0')->unique()->values()->all(),
            documentCodes: collect($codeMatches[0] ?? [])->map(fn ($value) => Str::upper($value))->unique()->values()->all(),
            followUp: $followUp && is_string($previous),
            requiresMultipleDocuments: $this->requiresMultipleDocuments($normalized) || count($currentTopics) > 1,
            sensitiveRequest: $this->isSensitiveRequest($normalized),
        );

        return $plan->toArray();
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
        if (preg_match('/\b(?:what can i do (?:here|on (?:this|the) (?:\w+ )?page)|help (?:me )?(?:with|on) this page|how do i use this page|apa (?:yang )?boleh (?:saya )?buat (?:di sini|pada halaman ini)|cara guna halaman ini)\b/u', $message) === 1) {
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
                'can', 'could', 'would', 'should', 'this', 'that', 'page', 'here', 'my', 'your',
                'according', 'please', 'tell', 'explain',
                'yang', 'dan', 'untuk', 'dengan', 'apa', 'bila', 'mana', 'adalah', 'boleh',
                'saya', 'awak', 'anda', 'ini', 'itu', 'di', 'ke', 'dari', 'pada', 'macam',
                'bagaimana', 'nak', 'tu', 'ni', 'ya', 'sini', 'halaman', 'tolong',
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
        if (Str::length($message) <= 55 && preg_match('/\b(it|that|those|them|this|next|previous|above|itu|tersebut|seterusnya|tadi)\b/u', $message)) {
            return true;
        }

        return preg_match('/^(and|also|then|what about|how about|dan|juga|kemudian|bagaimana pula)\b/u', $message) === 1;
    }

    private function contextDependency(string $message, array $currentTopics): string
    {
        $deictic = preg_match('/\b(?:here|this page|current page|this screen|on this|di sini|halaman ini|paparan ini|muka surat ini)\b/u', $message) === 1;
        if ($deictic && $currentTopics !== []) {
            return 'mixed';
        }
        if ($deictic) {
            return 'page_deictic';
        }

        return $currentTopics !== [] ? 'explicit_topic' : 'neutral';
    }

    private function sourceMode(string $message): string
    {
        $system = preg_match('/\b(?:how (?:do|can|should) i|where (?:do|can) i|which button|what status|navigate|screen|page|form|field|apply|submit|save|edit|delete|cancel|cara|macam mana|bagaimana|butang|status|halaman|borang|medan|mohon|hantar|simpan|kemas kini|padam|batal)\b/u', $message) === 1;
        $reference = preg_match('/\b(?:emergency procedure|policy|telephone|phone number|procedure|annex|erp|prosedur kecemasan|polisi|nombor telefon|lampiran)\b/u', $message) === 1;

        if ($system && $reference) {
            return 'mixed';
        }
        if ($system) {
            return 'system';
        }
        if ($reference) {
            return 'reference';
        }

        return 'any';
    }

    private function language(string $message): string
    {
        $malay = preg_match_all('/\b(?:apa|bagaimana|macam|mana|nak|boleh|saya|anda|untuk|dengan|yang|dan|mohon|cuti|gaji|pemeriksaan|halaman|butang|simpan|hantar|padam|batal)\b/u', $message) ?: 0;
        $english = preg_match_all('/\b(?:what|how|where|which|can|should|apply|leave|salary|inspection|page|button|save|submit|delete|cancel)\b/u', $message) ?: 0;

        if ($malay > 0 && $english > 0) {
            return 'mixed';
        }

        return $malay > 0 ? 'ms' : 'en';
    }

    private function requiresMultipleDocuments(string $message): bool
    {
        return preg_match('/\b(compare|comparison|across|all annex|differences?|banding|perbezaan|semua lampiran)\b/u', $message) === 1;
    }

    private function isSensitiveRequest(string $message): bool
    {
        $credential = '(?:password|passcode|passphrase|secret|api[ -]?key|access[ -]?token|private[ -]?key|kata laluan|kunci api|token akses)';
        $workflow = preg_match('/\b(?:change|reset|update|recover|forgot|create|set|rotate|revoke|tukar|set semula|kemas kini|lupa|cipta|tetapkan|batalkan)\b.{0,35}\b'.$credential.'\b/u', $message) === 1;
        if ($workflow) {
            return false;
        }

        return preg_match('/\b(?:what is|show|tell|give|reveal|display|share|export|list|apa|tunjuk|beritahu|beri|dedahkan|papar|kongsi)\b.{0,45}\b'.$credential.'\b/u', $message) === 1
            || preg_match('/\b'.$credential.'\b.{0,35}\b(?:for|of|belonging to|untuk|bagi|milik)\b/u', $message) === 1;
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
