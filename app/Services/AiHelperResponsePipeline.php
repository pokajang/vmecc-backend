<?php

namespace App\Services;

class AiHelperResponsePipeline
{
    public function __construct(
        private readonly AiHelperOpenAiService $openAi,
        private readonly AiHelperResponseValidationService $validator,
        private readonly AiHelperResponseResultFactory $results
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
        ?AiHelperRequestDeadline $deadline = null,
        bool $evidenceRequired = true,
        ?string $safetyIdentifier = null,
        ?array $queryPlan = null,
        array $retrievalMetadata = []
    ): array {
        $pipelineStartedAt = microtime(true);
        $deadline ??= AiHelperRequestDeadline::fromConfig();
        $queryPlan = (array) ($queryPlan ?? []);
        $retrievalMetadata = (array) $retrievalMetadata;
        $qualitySignals = $this->baseQualitySignals($queryPlan, $retrievalMetadata);
        if ($deterministicContent !== null) {
            return $this->withPolicyMetadata(
                $this->results->deterministic($deterministicContent, $sources),
                $qualitySignals,
                $this->qualityProfile('', ['valid' => true], $sources, $queryPlan, $retrievalMetadata, 0),
                true,
                'normal',
                [],
            );
        }
        $fallbackLanguage = $this->fallbackLanguage($responseLanguage, $question);
        if ($evidenceRequired && ($guidance === [] || $sources === [])) {
            $result = $this->results->rejected(
                $sources,
                $fallbackLanguage,
                'AI_HELPER_NO_AUTHORIZED_EVIDENCE',
                'retrieve_more_evidence',
                0,
                [],
                [],
                0,
                0,
                $pipelineStartedAt,
                ['valid' => false, 'status' => 'not_run', 'reason' => 'no_authorized_evidence'],
            );

            return $this->withPolicyMetadata(
                $result,
                $qualitySignals,
                $this->qualityProfile('', ['valid' => false], $sources, $queryPlan, $retrievalMetadata, 0),
                false,
                'refuse',
                ['no_authorized_evidence'],
            );
        }

        $maximumAttempts = min(2, max(1, (int) config('ai_helper.verification_max_attempts', 2)));
        $attempt = 0;
        $draft = '';
        $responseIds = [];
        $providerRequestIds = [];
        $usage = [];
        $validation = [];
        $failureCategory = null;
        $generationInstructions = $instructions;
        $generationInput = $history;
        $generationDuration = 0;
        $verificationDuration = 0;

        while ($attempt < $maximumAttempts) {
            $attempt++;
            $onStatus($attempt === 1 ? 'generating' : 'repairing');
            $draft = '';
            $generationStartedAt = microtime(true);
            try {
                $providerResult = $this->openAi->streamResponse(
                    $generationInstructions,
                    $generationInput,
                    function (string $delta) use (&$draft, $onProviderDelta): void {
                        $draft .= $delta;
                        $onProviderDelta($delta);
                    },
                    $deadline,
                    $safetyIdentifier,
                );
            } catch (AiHelperProviderException $failure) {
                $generationDuration += $this->elapsedMilliseconds($generationStartedAt);

                return $this->withPolicyMetadata(
                    $this->results->providerFallback(
                        $failure,
                        $guidance,
                        $sources,
                        $fallbackLanguage,
                        $attempt,
                        $responseIds,
                        $providerRequestIds,
                        $usage,
                        $generationDuration,
                        $verificationDuration,
                        $pipelineStartedAt,
                    ),
                    $qualitySignals,
                    $this->qualityProfile('', ['valid' => false, 'grounding_verification' => ['status' => 'provider_unavailable']], $sources, $queryPlan, $retrievalMetadata, $attempt),
                    false,
                    'partial',
                    ['provider_unavailable'],
                );
            }
            $generationDuration += $this->elapsedMilliseconds($generationStartedAt);
            $this->captureProviderMetadata($providerResult, $responseIds, $providerRequestIds, $usage);

            if (! $evidenceRequired) {
                $quality = $this->qualityProfile(
                    $draft,
                    ['valid' => true],
                    [],
                    $queryPlan,
                    $retrievalMetadata,
                    $attempt,
                );

                return $this->withPolicyMetadata(
                    $this->results->conversational(
                        $draft,
                        $responseIds,
                        $providerRequestIds,
                        $usage,
                        $generationDuration,
                        $pipelineStartedAt,
                    ),
                    $qualitySignals,
                    $quality,
                    true,
                    'normal',
                    [],
                );
            }

            $onStatus('verifying');
            $verificationStartedAt = microtime(true);
            $validation = $this->validator->validate($question, $draft, $guidance, $sources, $deadline, $safetyIdentifier);
            $this->captureVerificationMetadata($validation, $providerRequestIds, $usage);

            $revisionStatusNote = $this->validator->revisionStatusNote($validation, $guidance);
            if ($revisionStatusNote !== null) {
                $draft = rtrim($draft)."\n\nRevision status:\n\n- {$revisionStatusNote}";
                $validation = $this->validator->validate($question, $draft, $guidance, $sources, $deadline, $safetyIdentifier);
                $this->captureVerificationMetadata($validation, $providerRequestIds, $usage);
            }

            if (! $validation['valid']) {
                $citationRepair = $this->validator->renderCitationRepair(
                    $question,
                    $draft,
                    $guidance,
                    $sources,
                    $validation,
                    $deadline,
                    $safetyIdentifier,
                );
                if ($citationRepair !== null) {
                    $draft = $citationRepair['draft'];
                    $validation = $citationRepair['validation'];
                    $this->captureVerificationMetadata($validation, $providerRequestIds, $usage);
                }
            }
            $verificationDuration += $this->elapsedMilliseconds($verificationStartedAt);

            if ($validation['valid']) {
                $quality = $this->qualityProfile(
                    $draft,
                    $validation,
                    $sources,
                    $queryPlan,
                    $retrievalMetadata,
                    $attempt,
                );
                $policy = $this->policyDecision($quality, $queryPlan, $retrievalMetadata, $failureCategory ?? 'validated');
                if ($policy['decision'] !== 'normal') {
                    return $this->withPolicyMetadata(
                        $this->lowConfidenceResponse(
                            $guidance,
                            $sources,
                            $policy,
                            $fallbackLanguage,
                            $validation,
                        ),
                        $qualitySignals,
                        $quality,
                        $this->requestedFactPresent($validation),
                        $policy['decision'],
                        $policy['evidence_gaps'],
                    );
                }

                return $this->withPolicyMetadata(
                    $this->results->verified(
                        $draft,
                        $sources,
                        $responseIds,
                        $providerRequestIds,
                        $usage,
                        $generationDuration,
                        $verificationDuration,
                        $pipelineStartedAt,
                        $attempt,
                        $validation,
                    ),
                    $qualitySignals,
                    $quality,
                    $this->requestedFactPresent($validation),
                    $policy['decision'],
                    $policy['evidence_gaps'],
                );
            }

            $failureCategory = $this->validator->failureCategory($validation);
            if ($failureCategory === 'evidence_incomplete') {
                $result = $this->results->extractiveFallback(
                    $guidance,
                    $sources,
                    $fallbackLanguage,
                    'evidence_incomplete',
                    'AI_HELPER_EVIDENCE_INCOMPLETE',
                    'retrieve_more_evidence',
                    $attempt,
                    $responseIds,
                    $providerRequestIds,
                    $usage,
                    $generationDuration,
                    $verificationDuration,
                    $pipelineStartedAt,
                    $validation,
                );

                return $this->withPolicyMetadata(
                    $result,
                    $qualitySignals,
                    $this->qualityProfile((string) ($result['content'] ?? ''), $validation, $sources, $queryPlan, $retrievalMetadata, $attempt),
                    $this->requestedFactPresent($validation),
                    'partial',
                    ['evidence_incomplete'],
                );
            }
            if ($failureCategory === 'verification_unavailable') {
                $result = $this->results->extractiveFallback(
                    $guidance,
                    $sources,
                    $fallbackLanguage,
                    'verification_unavailable',
                    'AI_HELPER_VERIFICATION_TEMPORARY',
                    'retry_provider',
                    $attempt,
                    $responseIds,
                    $providerRequestIds,
                    $usage,
                    $generationDuration,
                    $verificationDuration,
                    $pipelineStartedAt,
                    $validation,
                );

                return $this->withPolicyMetadata(
                    $result,
                    $qualitySignals,
                    $this->qualityProfile((string) ($result['content'] ?? ''), $validation, $sources, $queryPlan, $retrievalMetadata, $attempt),
                    $this->requestedFactPresent($validation),
                    'partial',
                    ['verification_unavailable'],
                );
            }

            if ($attempt >= $maximumAttempts || ! $deadline->hasTimeFor(3.0)) {
                break;
            }
            [$generationInstructions, $generationInput] = $this->validator->repairRequest(
                $instructions,
                $history,
                $draft,
                $validation,
                $failureCategory,
            );
        }

        $shadowGrounding = $validation['grounding_verification'] ?? [];
        if (($shadowGrounding['mode'] ?? null) === 'shadow'
            && (bool) ($validation['citation_validation']['valid'] ?? false)
            && (bool) ($validation['critical_fact_validation']['valid'] ?? false)
            && (bool) ($shadowGrounding['valid'] ?? false)) {
            return $this->withPolicyMetadata(
                $this->results->verified(
                    $draft,
                    $sources,
                    $responseIds,
                    $providerRequestIds,
                    $usage,
                    $generationDuration,
                    $verificationDuration,
                    $pipelineStartedAt,
                    $attempt,
                    $validation,
                    'shadow_failed',
                    'AI_HELPER_VERIFICATION_SHADOW_FAILED',
                ),
                $qualitySignals,
                $this->qualityProfile($draft, $validation, $sources, $queryPlan, $retrievalMetadata, $attempt),
                $this->requestedFactPresent($validation),
                'partial',
                ['shadow_verification_uncertain'],
            );
        }

        $result = $this->results->extractiveFallback(
            $guidance,
            $sources,
            $fallbackLanguage,
            'validation_failed',
            'AI_HELPER_VALIDATION_FAILED',
            match ($failureCategory) {
                'citation_format' => 'repair_citations',
                'incomplete_answer' => 'retrieve_more_evidence',
                default => 'remove_unsupported_claims',
            },
            $attempt,
            $responseIds,
            $providerRequestIds,
            $usage,
            $generationDuration,
            $verificationDuration,
            $pipelineStartedAt,
            $validation,
        );
        $quality = $this->qualityProfile((string) ($result['content'] ?? ''), $validation, $sources, $queryPlan, $retrievalMetadata, $attempt);
        $policy = $this->policyDecision($quality, $queryPlan, $retrievalMetadata, (string) $failureCategory);
        if ($policy['decision'] === 'refuse') {
            return $this->withPolicyMetadata(
                $this->withRefusalFallback(
                    $result,
                    $fallbackLanguage,
                    $quality,
                ),
                $qualitySignals,
                $quality,
                $this->requestedFactPresent($validation),
                $policy['decision'],
                $policy['evidence_gaps'],
            );
        }

        return $this->withPolicyMetadata(
            $result,
            $qualitySignals,
            $quality,
            $this->requestedFactPresent($validation),
            $policy['decision'],
            $policy['evidence_gaps'],
        );
    }

    private function withPolicyMetadata(
        array $result,
        array $qualitySignals,
        array $quality,
        bool $requestedFactPresent,
        string $decision,
        array $evidenceGaps
    ): array {
        $policyMode = (string) config('ai_helper.policy_mode', 'progressive');

        return array_merge($result, [
            'policy' => [
                'mode' => $policyMode,
                'decision' => $decision,
                'coverage_score' => $qualitySignals['coverage'] ?? null,
                'evidence_gaps' => array_values(array_unique($evidenceGaps)),
                'fallback_reason' => $this->fallbackReasonForDecision($decision, $evidenceGaps),
                'scope_adjusted' => (bool) ($qualitySignals['scope_adjusted'] ?? false),
                'scope_recovery_applied' => (bool) ($qualitySignals['scope_recovery'] ?? false),
            ],
            'response_quality' => [
                'retrieval_coverage_score' => $quality['retrieval_coverage'] ?? 1.0,
                'evidence_density' => $quality['evidence_density'] ?? 0.0,
                'validation_success_score' => $quality['validation_success'] ?? 0.0,
                'follow_up_confidence' => $quality['follow_up_confidence'] ?? null,
                'scope_adjusted' => (bool) ($qualitySignals['scope_adjusted'] ?? false),
            ],
            'fallback' => [
                'decision' => $decision,
                'reason' => $this->fallbackReasonForDecision($decision, $evidenceGaps),
                'evidence_gaps' => $evidenceGaps,
            ],
            'coverage_score' => $qualitySignals['coverage'] ?? null,
            'requested_fact_present' => $requestedFactPresent,
        ]);
    }

    private function baseQualitySignals(array $queryPlan, array $retrievalMetadata): array
    {
        return [
            'query_plan' => $queryPlan,
            'coverage' => $this->resolveCoverage($queryPlan, $retrievalMetadata),
            'scope_adjusted' => ($queryPlan['scope_adjustment_hint'] ?? '') === 'global_recovery'
                || ($queryPlan['query_scope'] ?? '') === 'global'
                || ((bool) (($queryPlan['cross_module_required'] ?? false) && ($queryPlan['intent_scope'] ?? '') === 'global')),
            'scope_recovery' => (bool) ($retrievalMetadata['scope_recovery'] ?? false),
        ];
    }

    /** @return array<string, mixed> */
    private function qualityProfile(
        string $draft,
        array $validation,
        array $sources,
        array $queryPlan,
        array $retrievalMetadata,
        int $attempt
    ): array {
        $sentenceFragments = preg_split('/[.!?]\s+/u', trim((string) $draft));
        $sentenceCount = max(1, count($sentenceFragments ?: []));
        preg_match_all('/\[(S\d+)\]/u', $draft, $matches);
        $citationCount = count($matches[0] ?? []);
        $evidenceDensity = min(1.0, $citationCount / max(1, $sentenceCount));
        $validationSuccess = (bool) ($validation['valid'] ?? false)
            ? 1.0
            : $this->validationFailureScore($validation);

        return [
            'attempt' => $attempt,
            'retrieval_coverage' => $this->resolveCoverage($queryPlan, $retrievalMetadata),
            'evidence_density' => round($evidenceDensity, 3),
            'validation_success' => round($validationSuccess, 3),
            'source_coverage' => min(1.0, collect($sources)->count() / 3),
            'follow_up_confidence' => $queryPlan['follow_up_confidence'] ?? 'none',
        ];
    }

    private function policyDecision(
        array $quality,
        array $queryPlan,
        array $retrievalMetadata,
        string $failureCategory
    ): array {
        $mode = strtolower((string) config('ai_helper.policy_mode', 'progressive'));
        $strictAction = strtolower((string) config('ai_helper.strict_ungrounded_action', 'refuse'));
        $minCoverage = (float) config('ai_helper.response_min_coverage', 0.55);
        $fallbackThreshold = (float) config('ai_helper.fallback_confidence_threshold', 0.35);
        $coverageScore = (
            (($quality['retrieval_coverage'] ?? 0) * 0.45)
            + (($quality['evidence_density'] ?? 0) * 0.25)
            + (($quality['validation_success'] ?? 0) * 0.3)
        );

        $answerMode = (string) ($queryPlan['answer_mode'] ?? 'operational_knowledge');
        if (in_array($answerMode, ['product_capability', 'product_navigation', 'product_workflow'], true)
            && ($quality['validation_success'] ?? 0) >= 1.0
            && collect((array) ($retrievalMetadata['missing_requested_facts'] ?? []))->filter()->isEmpty()) {
            return ['decision' => 'normal', 'evidence_gaps' => []];
        }

        $evidenceGaps = [];
        if (($quality['validation_success'] ?? 0) < 1.0) {
            $evidenceGaps[] = 'validation_limit';
        }
        if (($quality['retrieval_coverage'] ?? 0) < 0.45) {
            $evidenceGaps[] = 'limited_retrieval';
        }
        if (($retrievalMetadata['scope_recovery'] ?? false) || ($queryPlan['scope_adjustment_hint'] ?? '') === 'global_recovery') {
            $evidenceGaps[] = 'scope_recovery_applied';
        }
        if (collect((array) ($retrievalMetadata['missing_requested_facts'] ?? []))->isNotEmpty()) {
            $evidenceGaps[] = 'missing_requested_fact';
        }

        if ($coverageScore >= $minCoverage && empty($evidenceGaps)) {
            return ['decision' => 'normal', 'evidence_gaps' => []];
        }
        if ($coverageScore < $fallbackThreshold
            || in_array($failureCategory, ['unsupported_critical_fact', 'unsupported_claim', 'evidence_incomplete'], true)) {
            return ['decision' => $mode === 'strict' && $strictAction === 'ask_clarify' ? 'ask_clarify' : 'refuse', 'evidence_gaps' => $evidenceGaps];
        }

        return ['decision' => $mode === 'strict' ? 'refuse' : 'partial', 'evidence_gaps' => $evidenceGaps];
    }

    private function fallbackReasonForDecision(string $decision, array $evidenceGaps): string
    {
        return match ($decision) {
            'normal' => 'none',
            'partial' => $evidenceGaps === [] ? 'scope_not_fully_covered' : 'limited_context',
            'ask_clarify', 'refuse' => 'low_confidence',
            default => 'unknown',
        };
    }

    private function requestedFactPresent(array $validation): bool
    {
        return collect((array) data_get($validation, 'grounding_verification.missing_requested_facts', []))->filter()->isEmpty();
    }

    private function validationFailureScore(array $validation): float
    {
        if (($validation['citation_validation']['valid'] ?? false) === false) {
            return 0.1;
        }
        if (($validation['critical_fact_validation']['valid'] ?? false) === false) {
            return 0.4;
        }
        if (($validation['grounding_verification']['status'] ?? '') === 'verification_unavailable') {
            return 0.35;
        }
        if (($validation['grounding_verification']['status'] ?? '') === 'shadow_failed') {
            return 0.55;
        }

        return 0.6;
    }

    private function resolveCoverage(array $queryPlan, array $retrievalMetadata): float
    {
        if ($retrievalMetadata === []) {
            return 1.0;
        }
        $chunkCoverage = min(1.0, ((int) ($retrievalMetadata['chunks_selected'] ?? 0)) / 8);
        $lexicalCoverage = min(1.0, (float) ($retrievalMetadata['max_lexical_coverage'] ?? 0));
        $semanticSimilarity = min(1.0, (float) ($retrievalMetadata['max_semantic_similarity'] ?? 0) / 1.1);
        $requested = max(1, (int) ($retrievalMetadata['subqueries_requested'] ?? 1));
        $covered = min($requested, (int) ($retrievalMetadata['subqueries_covered'] ?? 0));
        $subqueryCoverage = min(1.0, $covered / $requested);
        $score = round(($chunkCoverage * 0.35) + ($lexicalCoverage * 0.25) + ($semanticSimilarity * 0.2) + ($subqueryCoverage * 0.2), 3);
        if (($queryPlan['query_scope'] ?? 'local') === 'local') {
            $score += 0.08;
        }

        return max(0.0, min(1.0, $score));
    }

    private function lowConfidenceResponse(
        array $guidance,
        array $sources,
        array $policy,
        string $fallbackLanguage,
        array $validation
    ): array {
        $extractive = $this->results->extractiveFallback(
            $guidance,
            $sources,
            $fallbackLanguage,
            'evidence_insufficient_for_full_answer',
            'AI_HELPER_LOW_CONFIDENCE',
            'ask_for_more_context',
            1,
            [],
            [],
            [],
            0,
            0,
            microtime(true),
            $validation,
        );

        $fallbackAction = $policy['evidence_gaps'] === [] ? 'ask_for_more_context' : 'ask_for_additional_scope';
        $extractive['content'] = $fallbackLanguage === 'bm'
            ? 'Saya belum mempunyai maklumat yang cukup untuk menjawab dengan tepat. Nyatakan tugas yang anda mahu lakukan dan nama rekod, peralatan atau dokumen jika berkaitan.'
            : 'I do not yet have enough information to answer accurately. Tell me the task you want to complete and the relevant record, equipment, or document if applicable.';
        $extractive['outcome_code'] = 'AI_HELPER_LOW_CONFIDENCE';
        $extractive['recovery_action'] = $fallbackAction;

        return $extractive;
    }

    private function withRefusalFallback(
        array $result,
        string $fallbackLanguage,
        array $quality
    ): array {
        $isBm = $fallbackLanguage === 'bm';
        if ($isBm) {
            $result['content'] = 'Saya belum dapat mengesahkan jawapan terperinci untuk permintaan ini. Nyatakan tugas atau butiran khusus yang anda perlukan.';
        } else {
            $result['content'] = 'I could not verify a detailed answer for this request. Tell me the specific task or detail you need.';
        }
        if (($quality['validation_success'] ?? 0) >= 0.55) {
            $result['fallback_action'] = 'ask_for_more_context';
        }
        $result['outcome_code'] = 'AI_HELPER_REFUSED_LOW_CONFIDENCE';
        $result['recovery_action'] = 'ask_for_more_context';
        $result['verification']['status'] = 'rejected';

        return $result;
    }

    /** @param array<int, string> $responseIds @param array<int, string> $providerRequestIds @param array<string, int> $usage */
    private function captureProviderMetadata(
        array $result,
        array &$responseIds,
        array &$providerRequestIds,
        array &$usage
    ): void {
        if (! empty($result['response_id'])) {
            $responseIds[] = (string) $result['response_id'];
        }
        if (! empty($result['provider_request_id'])) {
            $providerRequestIds[] = (string) $result['provider_request_id'];
        }
        $usage = $this->mergeUsage($usage, $result['usage'] ?? []);
    }

    /** @param array<int, string> $providerRequestIds @param array<string, int> $usage */
    private function captureVerificationMetadata(array $validation, array &$providerRequestIds, array &$usage): void
    {
        $grounding = $validation['grounding_verification'] ?? [];
        if (! empty($grounding['provider_request_id'])) {
            $providerRequestIds[] = (string) $grounding['provider_request_id'];
        }
        $usage = $this->mergeUsage($usage, $grounding['usage'] ?? []);
    }

    /** @param array<string, int> $current @param array<string, mixed> $additional @return array<string, int> */
    private function mergeUsage(array $current, array $additional): array
    {
        foreach ($additional as $key => $value) {
            if (is_numeric($value)) {
                $current[(string) $key] = (int) ($current[(string) $key] ?? 0) + (int) $value;
            }
        }

        return $current;
    }

    private function elapsedMilliseconds(float $startedAt): int
    {
        return (int) ((microtime(true) - $startedAt) * 1000);
    }

    private function fallbackLanguage(string $responseLanguage, string $question): string
    {
        if (in_array($responseLanguage, ['en', 'bm'], true)) {
            return $responseLanguage;
        }

        return preg_match('/\b(?:apa|apakah|siapa|berapa|bagaimana|mengapa|bila|mana|untuk|dalam|saya|anda|ada|tak|nak|macam|pemeriksaan|panduan|langkah|dokumen|lampiran)\b/iu', $question) === 1
            ? 'bm'
            : 'en';
    }
}
