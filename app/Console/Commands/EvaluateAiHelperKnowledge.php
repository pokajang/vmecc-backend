<?php

namespace App\Console\Commands;

use App\Services\AiHelperKnowledgeService;
use App\Services\AiHelperOpenAiService;
use App\Services\AiHelperResponsePipeline;
use App\Support\AiHelperKnowledgeEvaluationCases;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use RuntimeException;

class EvaluateAiHelperKnowledge extends Command
{
    protected $signature = 'ai-helper:evaluate-knowledge
        {--live : Ask the configured response model and grade its answers}
        {--suite=core : Evaluation suite: core, coverage, or all}
        {--case=* : Run only the specified case IDs}
        {--json : Emit machine-readable JSON}';

    protected $description = 'Evaluate source retrieval and optional live answer grounding against the private Markdown corpus.';

    public function __construct(
        private readonly AiHelperKnowledgeService $knowledge,
        private readonly AiHelperOpenAiService $openAi,
        private readonly AiHelperResponsePipeline $responsePipeline,
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
        if (! in_array($suite, ['core', 'coverage', 'all'], true)) {
            throw new RuntimeException('Evaluation suite must be core, coverage, or all.');
        }
        $cases = collect(AiHelperKnowledgeEvaluationCases::all())
            ->when($suite !== 'all', fn ($items) => $items->filter(
                fn (array $case) => ($case['suite'] ?? 'core') === $suite
            ))
            ->when($selectedIds->isNotEmpty(), fn ($items) => $items->whereIn('id', $selectedIds))
            ->values();
        if ($cases->isEmpty()) {
            throw new RuntimeException('No matching evaluation cases were selected.');
        }

        $results = $cases->map(fn (array $case) => $this->evaluate($case, $live))->all();
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
    private function evaluate(array $case, bool $live): array
    {
        $context = $this->knowledge->buildContext(
            ['path' => '/dashboard'],
            null,
            $case['question'],
            $case['previous_user_messages'] ?? [],
        );
        $guidance = collect($context['guidance'] ?? []);
        $deterministicResponse = $this->knowledge->deterministicResponseFor(
            $context,
            $case['response_language'] ?? 'en',
        );
        $evidence = $guidance->pluck('content')->join("\n");
        $titles = $guidance->pluck('title')->unique()->values()->all();
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
            'guidance_count' => $guidance->count(),
            'document_titles' => $titles,
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
}
