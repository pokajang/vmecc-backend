<?php

namespace App\Console\Commands;

use App\Models\AiHelperKnowledgeEntry;
use App\Models\User;
use App\Services\AiHelperKnowledgeService;
use App\Services\AiHelperOpenAiService;
use App\Services\AiHelperResponsePipeline;
use App\Support\AiHelperKnowledgeEvaluationCases;
use App\Support\AiHelperSystemGuideEvaluationCases;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;

class EvaluateAiHelperKnowledge extends Command
{
    protected $signature = 'ai-helper:evaluate-knowledge
        {--live : Ask the configured response model and grade its answers}
        {--suite=core : Evaluation suite: core, coverage, all, system-guide-core, system-guide-coverage, or system-guide-global}
        {--case=* : Run only the specified case IDs}
        {--actor-map= : Server-only JSON file mapping system-guide personas to active user IDs}
        {--json : Emit machine-readable JSON}';

    protected $description = 'Evaluate source retrieval and optional live answer grounding against the private Markdown corpus.';

    public function __construct(
        private readonly AiHelperKnowledgeService $knowledge,
        private readonly AiHelperOpenAiService $openAi,
        private readonly AiHelperResponsePipeline $responsePipeline,
        private readonly AiHelperSystemGuideEvaluationCases $systemGuideCases,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $live = (bool) $this->option('live');
        if ($live && ! $this->openAi->isAvailable()) {
            throw new RuntimeException('The live evaluator requires a configured AI helper provider.');
        }
        $selectedIds = collect($this->option('case'))->filter()->values();
        $suite = strtolower(trim((string) $this->option('suite')));
        if (! in_array($suite, ['core', 'coverage', 'all', 'system-guide-core', 'system-guide-coverage', 'system-guide-global'], true)) {
            throw new RuntimeException('Evaluation suite must be core, coverage, all, system-guide-core, system-guide-coverage, or system-guide-global.');
        }
        $systemGuideSuite = str_starts_with($suite, 'system-guide-');
        $cases = collect(match ($suite) {
            'system-guide-core' => $this->systemGuideCases->core(),
            'system-guide-coverage' => $this->systemGuideCases->coverage(),
            'system-guide-global' => $this->systemGuideCases->global(),
            default => AiHelperKnowledgeEvaluationCases::all(),
        })
            ->when(! $systemGuideSuite && $suite !== 'all', fn ($items) => $items->filter(
                fn (array $case) => ($case['suite'] ?? 'core') === $suite
            ))
            ->when($selectedIds->isNotEmpty(), fn ($items) => $items->whereIn('id', $selectedIds))
            ->values();
        if ($cases->isEmpty()) {
            throw new RuntimeException('No matching evaluation cases were selected.');
        }

        $actorMap = $systemGuideSuite ? $this->loadActorMap() : [];
        $results = $cases->map(fn (array $case) => $this->evaluate($case, $live, $actorMap))->all();
        $retrievalPassed = collect($results)->where('retrieval_passed', true)->count();
        $livePassed = $live ? collect($results)->where('answer_passed', true)->count() : null;
        $documentRecallValues = collect($results)->pluck('document_recall')->filter(fn ($value) => $value !== null);
        $payload = [
            'mode' => $live ? 'retrieval_and_live_answer' : 'retrieval',
            'cases' => count($results),
            'retrieval_passed' => $retrievalPassed,
            'answer_passed' => $livePassed,
            'document_recall' => $documentRecallValues->isEmpty()
                ? null
                : round((float) $documentRecallValues->average(), 4),
            'passed' => $retrievalPassed === count($results) && (! $live || $livePassed === count($results)),
            'results' => $results,
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->table(
                $live
                    ? ['Case', 'Retrieval', 'Answer', 'Mode', 'Sources', 'Missing']
                    : ['Case', 'Retrieval', 'Mode', 'Sources', 'Missing'],
                collect($results)->map(function (array $result) use ($live) {
                    $row = [
                        $result['id'],
                        $result['retrieval_passed'] ? 'PASS' : 'FAIL',
                    ];
                    if ($live) {
                        $row[] = ($result['answer_skipped'] ?? false)
                            ? 'SKIP'
                            : ($result['answer_passed'] ? 'PASS' : 'FAIL');
                    }

                    return array_merge($row, [
                        $result['retrieval_mode'],
                        $result['guidance_count'],
                        implode(', ', $result['missing']),
                    ]);
                })->all(),
            );
        }

        return $payload['passed'] ? self::SUCCESS : self::FAILURE;
    }

    /** @return array<string, mixed> */
    private function evaluate(array $case, bool $live, array $actorMap = []): array
    {
        $user = $this->resolveActor($case, $actorMap);
        $previousDisabledGate = config('ai_helper.evaluation_disabled_module_gate');
        config(['ai_helper.evaluation_disabled_module_gate' => $case['disabled_module_gate'] ?? null]);
        try {
            $context = $this->knowledge->buildContext(
                [
                    'path' => $case['path'] ?? '/dashboard',
                    ...(isset($case['route_key']) ? ['route_key' => $case['route_key']] : []),
                    ...(isset($case['module_key']) ? ['module_key' => $case['module_key']] : []),
                ],
                $user,
                $case['question'],
                $case['previous_user_messages'] ?? [],
            );
        } finally {
            config(['ai_helper.evaluation_disabled_module_gate' => $previousDisabledGate]);
        }
        $guidance = collect($context['guidance'] ?? []);
        $deterministicResponse = $this->knowledge->deterministicResponseFor(
            $context,
            $case['response_language'] ?? 'en',
        );
        $evidence = $guidance->pluck('content')->join("\n");
        $titles = $guidance->pluck('title')->unique()->values()->all();
        $topTitle = (string) ($guidance->first()['title'] ?? '');
        $expectedTitles = collect($case['titles'] ?? [])
            ->merge($case['exact_document_titles'] ?? [])
            ->unique()
            ->values();
        $missing = [];

        if (isset($case['catalogue_total']) && (int) ($context['catalogue']['total'] ?? -1) !== (int) $case['catalogue_total']) {
            $missing[] = 'catalogue_total:'.$case['catalogue_total'];
        }
        foreach ($case['titles'] ?? [] as $title) {
            if (! collect($titles)->contains(fn (string $actual) => Str::contains(Str::lower($actual), Str::lower($title)))) {
                $missing[] = 'title:'.$title;
            }
        }
        if (isset($case['document_title_count']) && count($titles) !== (int) $case['document_title_count']) {
            $missing[] = 'document_title_count:'.$case['document_title_count'];
        }
        foreach ($case['exact_document_titles'] ?? [] as $title) {
            if (! collect($titles)->contains(fn (string $actual) => $actual === $title)) {
                $missing[] = 'exact_title:'.$title;
            }
        }
        if (isset($case['top_title']) && $topTitle !== (string) $case['top_title']) {
            $missing[] = 'top_title:'.$case['top_title'];
        }
        if (isset($case['expected_pipeline_version'])
            && (int) ($context['retrieval']['pipeline_version'] ?? 0) !== (int) $case['expected_pipeline_version']) {
            $missing[] = 'pipeline_version:'.$case['expected_pipeline_version'];
        }
        if (isset($case['expected_context_dependency'])
            && ($context['retrieval']['query_plan']['context_dependency'] ?? null) !== $case['expected_context_dependency']) {
            $missing[] = 'context_dependency:'.$case['expected_context_dependency'];
        }
        if (isset($case['expected_topic_key'])
            && ! in_array($case['expected_topic_key'], $context['retrieval']['query_plan']['topic_keys'] ?? [], true)) {
            $missing[] = 'topic_key:'.$case['expected_topic_key'];
        }
        if (isset($case['expected_source_type']) && ! $guidance->contains(
            fn (array $item) => ($item['source_type'] ?? null) === $case['expected_source_type']
        )) {
            $missing[] = 'source_type:'.$case['expected_source_type'];
        }
        foreach ($case['forbidden_titles'] ?? [] as $title) {
            if (collect($titles)->contains(fn (string $actual) => $actual === $title)) {
                $missing[] = 'forbidden_title';
            }
        }
        if (($case['expect_no_system_guidance'] ?? false) && $guidance->contains(
            fn (array $item) => ($item['source_type'] ?? null) === AiHelperKnowledgeEntry::KNOWLEDGE_SYSTEM_GUIDE
        )) {
            $missing[] = 'unexpected_system_guidance';
        }
        foreach ($case['evidence_tokens'] ?? [] as $token) {
            if (! Str::contains(Str::lower($evidence), Str::lower($token))) {
                $missing[] = 'evidence:'.$token;
            }
        }
        if (($case['visual_reference'] ?? false) && ! $guidance->contains(fn (array $item) => ($item['content_type'] ?? null) === 'visual_reference')) {
            $missing[] = 'visual_reference';
        }
        if (($case['expect_no_guidance'] ?? false) && $guidance->isNotEmpty()) {
            $missing[] = 'unexpected_guidance';
        }
        $matchedExpectedTitles = $expectedTitles->filter(fn (string $expected) => collect($titles)->contains(
            fn (string $actual) => Str::contains(Str::lower($actual), Str::lower($expected))
        ))->count();
        $documentRecall = $expectedTitles->isEmpty()
            ? null
            : round($matchedExpectedTitles / $expectedTitles->count(), 4);

        $answer = null;
        $answerMissing = [];
        $citationValidation = null;
        $pipelineVerification = null;
        $liveCase = $live && ! ($case['retrieval_only'] ?? false);
        if ($liveCase) {
            $answer = $deterministicResponse;
            if ($answer === null) {
                $history = collect($case['previous_user_messages'] ?? [])
                    ->map(fn (string $message) => ['role' => 'user', 'content' => $message])
                    ->push(['role' => 'user', 'content' => $case['question']])
                    ->values()
                    ->all();
                $pipelineResult = $this->responsePipeline->respond(
                    $case['question'],
                    $this->knowledge->instructionsFor($context, $case['response_language'] ?? 'en'),
                    $history,
                    $guidance->all(),
                    $this->knowledge->citationsForGuidance($guidance->all()),
                    null,
                    $case['response_language'] ?? 'en',
                    fn () => null,
                    fn () => null,
                );
                $answer = (string) $pipelineResult['content'];
                $pipelineVerification = $pipelineResult['verification'] ?? null;
            }
            foreach ($case['answer_tokens'] ?? [] as $token) {
                if (! Str::contains($this->comparableAnswer($answer), Str::lower($token))) {
                    $answerMissing[] = 'answer:'.$token;
                }
            }
            foreach ($case['answer_any_tokens'] ?? [] as $alternatives) {
                if (! collect($alternatives)->contains(
                    fn (string $token) => Str::contains($this->comparableAnswer($answer), Str::lower($token))
                )) {
                    $answerMissing[] = 'answer:any_of:'.implode('|', $alternatives);
                }
            }
            if (isset($case['answer_patterns']) && ! collect($case['answer_patterns'])->contains(
                fn (string $pattern) => Str::contains($this->comparableAnswer($answer), Str::lower($pattern))
            )) {
                $answerMissing[] = 'answer:required_pattern';
            }
            foreach ($case['answer_forbidden_patterns'] ?? [] as $pattern) {
                if (Str::contains($this->comparableAnswer($answer), Str::lower($pattern))) {
                    $answerMissing[] = 'answer:forbidden:'.$pattern;
                }
            }
            $citationValidation = $deterministicResponse === null
                ? ($pipelineVerification['citation_validation'] ?? null)
                : ['valid' => true, 'status' => 'not_required', 'reason' => null];
            if (! ($citationValidation['valid'] ?? false)) {
                $answerMissing[] = 'answer:citation_validation:'.($citationValidation['reason'] ?? 'missing_result');
            }
            if ($deterministicResponse === null && ($pipelineVerification['status'] ?? null) === 'rejected') {
                $answerMissing[] = 'answer:pipeline_rejected';
            }
            if (($pipelineVerification['grounding_verification']['would_pass'] ?? true) === false) {
                $answerMissing[] = 'answer:grounding_would_fail_enforcement';
            }
        }

        return [
            'id' => $case['id'],
            'question' => $case['question'],
            'retrieval_passed' => $missing === [],
            'answer_passed' => $live ? ($liveCase ? $answerMissing === [] : true) : null,
            'answer_skipped' => $live && ! $liveCase,
            'retrieval_mode' => $context['retrieval']['mode'] ?? 'unknown',
            'pipeline_version' => $context['retrieval']['pipeline_version'] ?? null,
            'context_dependency' => $context['retrieval']['query_plan']['context_dependency'] ?? null,
            'topic_keys' => $context['retrieval']['query_plan']['topic_keys'] ?? [],
            'guidance_count' => $guidance->count(),
            'document_titles' => ($case['expect_no_system_guidance'] ?? false) ? [] : $titles,
            'document_recall' => $documentRecall,
            'subqueries_requested' => $context['retrieval']['subqueries_requested'] ?? null,
            'subqueries_covered' => $context['retrieval']['subqueries_covered'] ?? null,
            'missing' => array_merge($missing, $answerMissing),
            'citation_validation' => $live ? ($citationValidation ?? null) : null,
            'pipeline_verification' => $live ? $pipelineVerification : null,
            'answer_preview' => $live ? Str::limit(trim((string) $answer), 500, '') : null,
        ];
    }

    private function comparableAnswer(string $answer): string
    {
        return Str::lower((string) preg_replace('/[`*_~]+/u', '', $answer));
    }

    /** @return array<string, int> */
    private function loadActorMap(): array
    {
        $path = trim((string) $this->option('actor-map'));
        if ($path === '' || ! is_file($path)) {
            throw new RuntimeException('System-guide evaluation requires a readable server-only --actor-map JSON file.');
        }

        try {
            $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('The system-guide actor map is invalid JSON.', previous: $exception);
        }
        if (! is_array($decoded)) {
            throw new RuntimeException('The system-guide actor map must be a JSON object.');
        }

        return collect($decoded)
            ->mapWithKeys(function ($id, $persona) {
                if (! is_string($persona) || ! is_int($id) || $id < 1) {
                    throw new RuntimeException('Every system-guide actor-map value must be a positive integer user ID.');
                }

                return [$persona => $id];
            })
            ->all();
    }

    private function resolveActor(array $case, array $actorMap): ?User
    {
        $persona = (string) ($case['persona'] ?? 'unauthenticated');
        if ($persona === 'unauthenticated') {
            return null;
        }
        $userId = $actorMap[$persona] ?? null;
        if (! is_int($userId)) {
            throw new RuntimeException("The actor map has no user ID for persona {$persona}.");
        }
        $user = User::query()
            ->whereKey($userId)
            ->whereNull('deleted_at')
            ->whereRaw('LOWER(status) = ?', ['active'])
            ->first();
        if (! $user) {
            throw new RuntimeException("The actor mapped for persona {$persona} is not active.");
        }

        return $user;
    }
}
