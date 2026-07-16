<?php

namespace App\Services;

class AiHelperResponsePipeline
{
    public function __construct(
        private readonly AiHelperOpenAiService $openAi,
        private readonly AiHelperCitationValidator $citationValidator,
        private readonly AiHelperCriticalFactValidator $criticalFactValidator,
        private readonly AiHelperGroundingVerifier $groundingVerifier,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $guidance
     * @param  array<int, array<string, mixed>>  $sources
     * @param  callable(string): void  $onStatus
     * @param  callable(string): void  $onProviderDelta
     * @return array<string, mixed>
     */
    public function respond(
        string $question,
        string $instructions,
        array $history,
        array $guidance,
        array $sources,
        ?string $deterministicContent,
        string $responseLanguage,
        callable $onStatus,
        callable $onProviderDelta,
    ): array {
        $pipelineStartedAt = microtime(true);
        if ($deterministicContent !== null) {
            return [
                'content' => $deterministicContent,
                'sources' => [],
                'response_id' => null,
                'provider_response_ids' => [],
                'timings_ms' => ['generation' => 0, 'verification' => 0, 'total' => 0],
                'verification' => [
                    'status' => 'deterministic',
                    'attempts' => 0,
                    'citation_validation' => ['valid' => true, 'status' => 'not_required'],
                    'critical_fact_validation' => ['valid' => true, 'status' => 'not_required', 'failures' => []],
                    'grounding_verification' => ['valid' => true, 'status' => 'not_required', 'failures' => []],
                ],
            ];
        }

        $maximumAttempts = min(2, max(1, (int) config('ai_helper.verification_max_attempts', 2)));
        $attempt = 0;
        $draft = '';
        $responseIds = [];
        $validation = [];
        $generationInstructions = $instructions;
        $generationDuration = 0;
        $verificationDuration = 0;

        while ($attempt < $maximumAttempts) {
            $attempt++;
            $onStatus($attempt === 1 ? 'generating' : 'repairing');
            $draft = '';
            $generationStartedAt = microtime(true);
            $result = $this->openAi->streamResponse(
                $generationInstructions,
                $history,
                function (string $delta) use (&$draft, $onProviderDelta): void {
                    $draft .= $delta;
                    $onProviderDelta($delta);
                },
            );
            $generationDuration += (int) ((microtime(true) - $generationStartedAt) * 1000);
            if (! empty($result['response_id'])) {
                $responseIds[] = (string) $result['response_id'];
            }

            $onStatus('verifying');
            $verificationStartedAt = microtime(true);
            $validation = $this->validateDraft($question, $draft, $guidance, $sources);
            $revisionStatusNote = $this->revisionStatusNote($validation, $guidance);
            if ($revisionStatusNote !== null) {
                $draft = rtrim($draft)."\n\nRevision status:\n\n- {$revisionStatusNote}";
                $validation = $this->validateDraft($question, $draft, $guidance, $sources);
            }
            $verificationDuration += (int) ((microtime(true) - $verificationStartedAt) * 1000);
            if ($validation['valid']) {
                return [
                    'content' => $draft,
                    'sources' => $sources,
                    'response_id' => $responseIds === [] ? null : end($responseIds),
                    'provider_response_ids' => $responseIds,
                    'timings_ms' => [
                        'generation' => $generationDuration,
                        'verification' => $verificationDuration,
                        'total' => (int) ((microtime(true) - $pipelineStartedAt) * 1000),
                    ],
                    'verification' => [
                        'status' => 'verified',
                        'attempts' => $attempt,
                        ...$validation,
                    ],
                ];
            }

            if ($attempt < $maximumAttempts) {
                $generationInstructions = $this->repairInstructions($instructions, $draft, $validation);
            }
        }

        $shadowGrounding = $validation['grounding_verification'] ?? [];
        if (($shadowGrounding['mode'] ?? null) === 'shadow'
            && (bool) ($validation['citation_validation']['valid'] ?? false)
            && (bool) ($validation['critical_fact_validation']['valid'] ?? false)
            && (bool) ($shadowGrounding['valid'] ?? false)) {
            return [
                'content' => $draft,
                'sources' => $sources,
                'response_id' => $responseIds === [] ? null : end($responseIds),
                'provider_response_ids' => $responseIds,
                'timings_ms' => [
                    'generation' => $generationDuration,
                    'verification' => $verificationDuration,
                    'total' => (int) ((microtime(true) - $pipelineStartedAt) * 1000),
                ],
                'verification' => [
                    'status' => 'shadow_failed',
                    'attempts' => $attempt,
                    ...$validation,
                ],
            ];
        }

        $rejection = $this->citationValidator->enforce('', $sources, $responseLanguage);

        return [
            'content' => $rejection['content'],
            'sources' => [],
            'response_id' => $responseIds === [] ? null : end($responseIds),
            'provider_response_ids' => $responseIds,
            'timings_ms' => [
                'generation' => $generationDuration,
                'verification' => $verificationDuration,
                'total' => (int) ((microtime(true) - $pipelineStartedAt) * 1000),
            ],
            'verification' => [
                'status' => 'rejected',
                'attempts' => $attempt,
                ...$validation,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function validateDraft(string $question, string $draft, array $guidance, array $sources): array
    {
        $citation = $this->citationValidator->validate($draft, $sources);
        $critical = $citation['valid']
            ? $this->criticalFactValidator->validate($draft, $guidance, $question)
            : ['valid' => false, 'status' => 'skipped', 'failures' => []];
        $grounding = $citation['valid'] && $critical['valid']
            ? $this->groundingVerifier->verify($question, $draft, $guidance)
            : ['valid' => false, 'status' => 'skipped', 'failures' => []];
        $groundingPasses = (bool) ($grounding['would_pass'] ?? $grounding['valid']);

        return [
            'valid' => (bool) $citation['valid'] && (bool) $critical['valid'] && $groundingPasses,
            'citation_validation' => $citation,
            'critical_fact_validation' => $critical,
            'grounding_verification' => $grounding,
        ];
    }

    private function repairInstructions(string $instructions, string $draft, array $validation): string
    {
        $issues = [
            'citation' => $validation['citation_validation']['reason'] ?? null,
            'critical_facts' => $validation['critical_fact_validation']['failures'] ?? [],
            'grounding' => $validation['grounding_verification']['failures'] ?? [],
            'missing_requested_facts' => $validation['grounding_verification']['missing_requested_facts'] ?? [],
        ];
        $encodedIssues = json_encode($issues, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $safeDraft = str_replace(['<', '>'], ['&lt;', '&gt;'], $draft);

        return $instructions."\n\n"
            .'The previous draft failed source validation. Produce one corrected replacement answer. '
            .'Remove any claim that cannot be supported by the supplied SOURCE blocks, correct citation placement, '
            .'preserve exact critical values and required revision-status labels, and answer every supported part of the question. '
            .'Do not discuss this repair instruction.'
            ."\n\nValidation failures:\n".$encodedIssues
            ."\n\nPrevious draft:\n<PREVIOUS_DRAFT>\n".$safeDraft."\n</PREVIOUS_DRAFT>";
    }

    /**
     * @param  array<string, mixed>  $validation
     * @param  array<int, array<string, mixed>>  $guidance
     */
    private function revisionStatusNote(array $validation, array $guidance): ?string
    {
        $criticalFailures = collect($validation['critical_fact_validation']['failures'] ?? []);
        if ($criticalFailures->count() !== 1
            || $criticalFailures->first()['type'] !== 'missing_revision_status_label') {
            return null;
        }

        $source = collect($guidance)->first(function (array $item) {
            $title = trim((string) ($item['title'] ?? ''));

            return $title !== ''
                && preg_match('/\brev(?:ision)?[\s._-]*\d+\b/iu', $title) !== 1
                && trim((string) ($item['source_id'] ?? '')) !== '';
        });
        if (! is_array($source)) {
            return null;
        }

        $title = str_replace('`', '\\`', trim((string) $source['title']));

        return sprintf('`%s` — revision not stated [%s]', $title, $source['source_id']);
    }
}
