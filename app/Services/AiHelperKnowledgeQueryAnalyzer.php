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
        $previous = collect($previousUserMessages)
            ->filter(fn ($value) => is_string($value) && trim($value) !== '')
            ->map(fn (string $value) => trim($value))
            ->last();
        $previousTopics = is_string($previous)
            ? $this->topics->topicKeys($this->normalize($previous))
            : [];
        $followUp = is_string($previous)
            && $this->looksLikeFollowUp($normalized)
            && $this->topicsAreCompatible($currentTopics, $previousTopics, $normalized);
        $followUpConfidence = $this->followUpConfidence($normalized, $currentTopics, $previousTopics, $followUp);
        $retrievalQuery = $followUp && is_string($previous)
            ? $previous."\n".$message
            : $message;
        $normalizedQuery = $this->normalize($retrievalQuery);
        $topicKeys = $this->topics->topicKeys($normalizedQuery);
        $operationKeys = $this->operationKeys($normalizedQuery);
        $intent = $this->intent($normalized);
        $taskKeys = $this->taskKeys($normalizedQuery, $topicKeys, $operationKeys, $intent);
        $sourceMode = $this->sourceMode($followUp ? $normalizedQuery : $normalized);
        if ($sourceMode === 'system'
            && array_intersect($topicKeys, ['extinguisher', 'height_rescue']) !== []
            && in_array('maintain', $operationKeys, true)) {
            $sourceMode = 'mixed';
        }
        $contextDependency = $this->contextDependency($normalized, $currentTopics);
        $queryScope = $this->queryScope(
            $normalizedQuery,
            $currentTopics,
            $intent,
            $contextDependency,
            $taskKeys,
        );
        $subqueries = $this->subqueries($retrievalQuery);
        $scopeAdjustmentTopics = $followUp ? $currentTopics : $topicKeys;

        preg_match_all('/\b(?:annex(?:e)?|lampiran)\s*0*(\d{1,3})\b/i', $retrievalQuery, $annexMatches);
        preg_match_all('/\b(?:rev(?:ision)?[.\s:-]*)0*(\d{1,4})\b/i', $retrievalQuery, $revisionMatches);
        preg_match_all('/\b(?:[A-Z]{2,}(?:-[A-Z0-9]+){2,}|PRO-\d{4,})\b/i', $retrievalQuery, $codeMatches);

        $plan = new AiHelperQueryPlan(
            intent: $intent,
            sourceMode: $sourceMode,
            contextDependency: $contextDependency,
            queryScope: $queryScope,
            language: $this->language($normalized),
            message: $message,
            query: trim($retrievalQuery),
            normalizedQuery: $normalizedQuery,
            terms: $this->terms($retrievalQuery),
            expandedTerms: $this->topics->expandedTerms($topicKeys),
            topicKeys: $topicKeys,
            operationKeys: $operationKeys,
            taskKeys: $taskKeys,
            subqueries: $subqueries,
            annexNumbers: collect($annexMatches[1] ?? [])->map(fn ($value) => (int) $value)->unique()->values()->all(),
            revisions: collect($revisionMatches[1] ?? [])->map(fn ($value) => ltrim((string) $value, '0') ?: '0')->unique()->values()->all(),
            documentCodes: collect($codeMatches[0] ?? [])->map(fn ($value) => Str::upper($value))->unique()->values()->all(),
            followUp: $followUp && is_string($previous),
            followUpConfidence: $followUpConfidence,
            requiresMultipleDocuments: $this->requiresMultipleDocuments($normalized) || count($subqueries) > 1,
            intentScope: $this->intentScope($normalized, $topicKeys, $intent, $contextDependency, $queryScope),
            crossModuleRequired: $this->crossModuleRequired($topicKeys, $contextDependency, $retrievalQuery),
            entitiesExplicit: $this->entitiesExplicit($topicKeys, $operationKeys, $taskKeys, $retrievalQuery),
            requiresGlobalContext: $this->requiresGlobalContext(
                $queryScope,
                $contextDependency,
                $topicKeys,
                $intent,
                $normalized,
            ),
            scopeAdjustmentHint: $this->scopeAdjustmentHint($normalized, $scopeAdjustmentTopics, $contextDependency, $currentTopics),
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
            'how many guides', 'how many guidance documents', 'available guides',
            'senarai', 'semua fail', 'semua dokumen', 'semua lampiran',
            'senarai lampiran', 'dokumen pengetahuan', 'dokumen rujukan', 'dokumen yang dimuat naik',
            'panduan yang dimuat naik', 'panduan dimuat naik', 'panduan dimuatnaik',
        ];

        return collect($phrases)->contains(fn (string $phrase) => str_contains($message, $phrase))
            || preg_match('/\bberapa\s+(?:dokumen|fail|lampiran|sumber|panduan)\b/u', $message) === 1;
    }

    private function intent(string $message): string
    {
        if ($this->isInspectionCapabilityCatalogueIntent($message)) {
            return 'capability_catalogue';
        }
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
        $normalized = Str::lower($value);
        $normalized = str_replace(
            ['fire extiguisher', 'fire extinguishr', 'fire extenguisher', 'high angle rescue'],
            ['fire extinguisher', 'fire extinguisher', 'fire extinguisher', 'high-angle rescue'],
            $normalized,
        );
        $normalized = (string) preg_replace('/\bnk\b/u', 'nak', $normalized);

        return trim((string) preg_replace('/\s+/u', ' ', $normalized));
    }

    private function looksLikeFollowUp(string $message): bool
    {
        if (Str::length($message) <= 64 && preg_match('/\b(it|that|those|them|this|next|previous|above|itu|tersebut|seterusnya|tadi)\b/u', $message)) {
            return true;
        }

        return preg_match('/^(and|also|then|but|what about|how about|dan|juga|kemudian|tetapi|tapi|bagaimana pula)\b/u', $message) === 1;
    }

    /** @param array<int, string> $currentTopics @param array<int, string> $previousTopics */
    private function topicsAreCompatible(array $currentTopics, array $previousTopics, string $message): bool
    {
        if ($currentTopics === [] || $previousTopics === []) {
            return $this->containsFollowUpPronoun($message);
        }

        if (array_intersect($currentTopics, $previousTopics) !== []) {
            return true;
        }

        $inspectionFamily = ['inspection', 'inspection_issue', 'inspection_verification', 'extinguisher'];

        return array_intersect($currentTopics, $inspectionFamily) !== []
            && array_intersect($previousTopics, $inspectionFamily) !== [];
    }

    private function containsFollowUpPronoun(string $message): bool
    {
        return preg_match('/\b(it|that|those|them|this|next|previous|above|here|there|itu|tersebut|seterusnya|tadi)\b/u', $message) === 1;
    }

    private function followUpConfidence(
        string $message,
        array $currentTopics,
        array $previousTopics,
        bool $followUp,
    ): string {
        if (! $followUp || $previousTopics === []) {
            return 'none';
        }
        if ($currentTopics === []) {
            return $this->containsFollowUpPronoun($message) ? 'medium' : 'low';
        }
        if (array_intersect($currentTopics, $previousTopics) !== []) {
            return 'high';
        }
        if (count($previousTopics) > 1) {
            return 'medium';
        }

        return 'low';
    }

    private function scopeAdjustmentHint(
        string $message,
        array $topicKeys,
        string $contextDependency,
        array $currentTopics,
    ): string {
        if ($contextDependency === 'page_deictic') {
            return 'none';
        }
        if (count($currentTopics) >= 2) {
            return 'global';
        }
        if ($this->containsCrossModuleTopic($topicKeys)) {
            return 'cross_module_candidate';
        }
        if ($contextDependency === 'explicit_topic'
            && preg_match('/\b(?:overview|module|modules|roles|permissions|all)\b/u', $message) === 1) {
            return 'global';
        }

        return 'none';
    }

    private function containsCrossModuleTopic(array $topicKeys): bool
    {
        $crossModuleKeys = [
            'salary_claim', 'salary_assignment', 'payroll', 'payment', 'staff', 'team',
            'role_permission', 'module_activation', 'user_administration', 'workflow_setting', 'workflow_rule',
            'roster', 'dashboard', 'holiday',
        ];

        return collect($topicKeys)->intersect($crossModuleKeys)->isNotEmpty();
    }

    private function isInspectionCapabilityCatalogueIntent(string $message): bool
    {
        $mentionsInspection = preg_match('/\b(?:inspection|inspections|pemeriksaan)\b/u', $message) === 1;
        $asksForTypes = preg_match('/\b(?:types?|kinds?)\s+of\s+(?:inspection|inspections)\b/u', $message) === 1
            || preg_match('/\b(?:inspection|inspections)\s+(?:types?|kinds?)\b/u', $message) === 1
            || preg_match('/\bjenis\s+pemeriksaan\b/u', $message) === 1
            || preg_match('/\b(?:what|which|list|apa|senarai)\b.{0,35}\b(?:inspections?|pemeriksaan)\b.{0,20}\b(?:available|provided|tersedia|disediakan)\b/u', $message) === 1;

        return $mentionsInspection && $asksForTypes;
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

    private function queryScope(
        string $normalizedQuery,
        array $currentTopics,
        string $intent,
        string $contextDependency,
        array $taskKeys,
    ): string {
        if ($contextDependency === 'page_deictic') {
            return 'page';
        }

        if ($intent === 'catalogue' || $intent === 'capability_catalogue') {
            return 'global';
        }

        if ($this->isSystemOverviewQuery($normalizedQuery)) {
            return 'global';
        }

        if (count(array_unique($currentTopics)) >= 2 || $contextDependency === 'mixed') {
            return 'global';
        }

        if (count($currentTopics) === 1 && in_array('system_overview', $currentTopics, true)) {
            return 'global';
        }

        if (! empty($taskKeys) && in_array('inspection.types.list', $taskKeys, true)) {
            return 'global';
        }

        return 'local';
    }

    private function intentScope(
        string $normalizedQuery,
        array $topicKeys,
        string $intent,
        string $contextDependency,
        string $queryScope,
    ): string {
        if ($intent === 'catalogue' || $intent === 'capability_catalogue') {
            return 'global';
        }
        if ($contextDependency === 'page_deictic' && count($topicKeys) <= 1) {
            return 'page';
        }
        if ($queryScope === 'global' || count($topicKeys) >= 2 || $contextDependency === 'mixed') {
            return 'global';
        }
        if (str_contains($normalizedQuery, 'overview') || $contextDependency === 'explicit_topic') {
            return $this->isSystemOverviewQuery($normalizedQuery) ? 'global' : 'local';
        }

        return 'local';
    }

    private function crossModuleRequired(array $topicKeys, string $contextDependency, string $message): bool
    {
        if ($contextDependency === 'page_deictic') {
            return false;
        }
        if (count($topicKeys) >= 2) {
            return true;
        }
        if (count($topicKeys) === 1) {
            return $this->containsCrossModuleTopic($topicKeys) && ! str_contains($message, 'this page');
        }

        return $this->containsCrossModuleTopic($topicKeys);
    }

    private function entitiesExplicit(array $topicKeys, array $operationKeys, array $taskKeys, string $query): bool
    {
        if ($topicKeys !== [] || $operationKeys !== [] || $taskKeys !== []) {
            return true;
        }

        return preg_match('/\b(?:leave|salary|payroll|attendance|teams?|overtime|staff|roster|inspection|annex|policy|procedure|module)\b/u', $query) === 1;
    }

    private function requiresGlobalContext(
        string $queryScope,
        string $contextDependency,
        array $topicKeys,
        string $intent,
        string $normalizedQuery,
    ): bool {
        if ($intent === 'catalogue' || $intent === 'capability_catalogue') {
            return true;
        }
        if ($queryScope === 'global' || $contextDependency === 'mixed') {
            return true;
        }
        if ($contextDependency === 'page_deictic') {
            return false;
        }

        return count($topicKeys) >= 2
            || $this->containsCrossModuleTopic($topicKeys)
            || preg_match('/\b(?:overview|global|across|all modules|all module|entire system)\b/u', $normalizedQuery) === 1;
    }

    private function isSystemOverviewQuery(string $normalizedQuery): bool
    {
        return preg_match(
            '/\b(?:what (?:is|are)|apa (?:yang|yang jadi)|how (?:do|does)|bila|bilakah|where can i|what can i)\b.+\b(?:system|platform|system overview|overview|module|modules|features?|flow|workflow|menu|menus)\b|'
            .'\b(?:apakah|apa) (?:sistem|platform|fungsi|ciri-ciri|menu) .*(?:vmecc|sistem ini|sistem yang|mempunyai)\b|'
            .'\b(?:senarai|list|apa|berapa) (?:modul|menu|ciri|fungsi|features) (?:yang|yang ada)\b|'
            .'\b(?:gambaran|overview) keseluruhan\b|'
            .'\b(?:system guide|application guide|panduan sistem|panduan penggunaan)\b/u',
            $normalizedQuery,
        ) === 1;
    }

    private function sourceMode(string $message): string
    {
        $system = preg_match('/\b(?:how (?:do|can|should) i|how to|what are the steps|steps for|instructions? for|guide for|where (?:do|can) i|which button|what status|navigate|screen|page|form|field|apply|submit|save|edit|delete|cancel|ada (?:tak )?panduan|nak buat|langkah untuk|cara(?: buat)?|macam (?:mana|nak)|bagaimana|butang|status|halaman|borang|medan|mohon|hantar|simpan|kemas kini|padam|batal)\b/u', $message) === 1;
        $reference = preg_match('/\b(?:emergency procedure|physical (?:inspection|maintenance)|maintenance|servicing|maintenance procedure|servicing procedure|policy|telephone|phone number|procedure|annex|erp|rescue|prosedur kecemasan|penyelenggaraan|selenggara|prosedur penyelenggaraan|polisi|nombor telefon|lampiran|menyelamat)\b/u', $message) === 1;

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
        $malay = preg_match_all('/\b(?:apa|ada|tak|bagaimana|macam|mana|nak|boleh|saya|anda|untuk|dengan|yang|dan|mohon|cuti|gaji|pemeriksaan|periksa|panduan|langkah|halaman|butang|simpan|hantar|padam|batal|selenggara|servis)\b/u', $message) ?: 0;
        $english = preg_match_all('/\b(?:what|how|where|which|can|should|steps?|guide|instructions?|apply|leave|salary|inspection|inspect|maintenance|page|button|save|submit|delete|cancel)\b/u', $message) ?: 0;

        if ($malay > 0 && $english > 0) {
            return 'mixed';
        }

        return $malay > 0 ? 'ms' : 'en';
    }

    private function requiresMultipleDocuments(string $message): bool
    {
        return preg_match('/\b(compare|comparison|across|all annex|differences?|banding|perbezaan|semua lampiran)\b/u', $message) === 1;
    }

    /** @return array<int, string> */
    private function operationKeys(string $message): array
    {
        $patterns = [
            'view' => '/\b(?:view|find|search|read|check status|lihat|cari|semak|papar)\b/u',
            'create' => '/\b(?:create|add|new|register|record|buat|tambah|baharu|daftar|rekod)\b/u',
            'inspect' => '/\b(?:inspect|inspection|checklist|check|conduct|pemeriksaan|periksa)\b/u',
            'maintain' => '/\b(?:maintain|maintenance|service|servicing|lifecycle|selenggara|penyelenggaraan|servis)\b/u',
            'submit' => '/\b(?:submit|send|hantar)\b/u',
            'approve' => '/\b(?:approve|review|verify|reject|lulus|semak|sahkan|tolak)\b/u',
            'configure' => '/\b(?:configure|setting|settings|enable|disable|tetapan|konfigurasi|aktifkan|nyahaktif)\b/u',
            'troubleshoot' => '/\b(?:error|problem|failed|cannot|can\x{2019}t|stuck|ralat|masalah|gagal|tak boleh|tersangkut)\b/u',
            'list' => '/\b(?:list|how many|count|senarai|berapa)\b/u',
        ];

        return collect($patterns)
            ->filter(fn (string $pattern) => preg_match($pattern, $message) === 1)
            ->keys()
            ->values()
            ->all();
    }

    /**
     * Map a question to the user's concrete job, not just broad words such as
     * "inspection". These keys let retrieval keep conduct, record viewing,
     * issue handling, verification and asset maintenance separate.
     *
     * @param  array<int, string>  $topicKeys
     * @param  array<int, string>  $operationKeys
     * @return array<int, string>
     */
    private function taskKeys(string $message, array $topicKeys, array $operationKeys, string $intent): array
    {
        if ($intent === 'capability_catalogue') {
            return ['inspection.types.list'];
        }

        $inspectionDomain = array_intersect(
            $topicKeys,
            ['inspection', 'inspection_issue', 'inspection_verification', 'extinguisher'],
        ) !== [];
        if (! $inspectionDomain) {
            return [];
        }

        if (preg_match('/\b(?:verify|verification|approve|reject|pending verification|sahkan|pengesahan|lulus|tolak)\b/u', $message) === 1) {
            return ['inspection.issue.verify'];
        }

        if (preg_match('/\b(?:issue|issues|defect|defects|finding|findings|masalah|kecacatan|penemuan)\b/u', $message) === 1) {
            return ['inspection.issue.manage'];
        }

        if (in_array('extinguisher', $topicKeys, true)
            && preg_match('/\b(?:asset|register|inventory|lifecycle|replace|retire|aset|daftar|inventori|ganti)\b/u', $message) === 1) {
            return ['inspection.asset.manage'];
        }

        if (in_array('maintain', $operationKeys, true)) {
            return in_array('inspect', $operationKeys, true)
                ? ['inspection.conduct', 'inspection.physical.maintain']
                : ['inspection.physical.maintain'];
        }

        if (in_array('view', $operationKeys, true)
            && ! in_array('inspect', $operationKeys, true)
            && preg_match('/\b(?:record|report|history|status|rekod|laporan|sejarah)\b/u', $message) === 1) {
            return ['inspection.records.view'];
        }

        if (in_array('inspect', $operationKeys, true)
            || preg_match('/\b(?:onsite|on-site|new inspection|buat pemeriksaan|jalankan pemeriksaan)\b/u', $message) === 1) {
            return ['inspection.conduct'];
        }

        return [];
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
        $query = trim((string) preg_replace(
            '/^(?:(?:as per|according to|based on)\s+(?:your|the|available)?\s*(?:knowledge|guidance))\s*,?\s*/iu',
            '',
            $query,
        ));

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
