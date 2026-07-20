<?php

namespace App\Services;

class AiHelperResponsePipeline
{
    public function __construct(
        private readonly AiHelperOpenAiService $openAi,
        private readonly AiHelperResponseValidationService $validator,
        private readonly AiHelperResponseResultFactory $results,
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
    ): array {
        $pipelineStartedAt = microtime(true);
        $deadline ??= AiHelperRequestDeadline::fromConfig();
        if ($deterministicContent !== null) {
            return $this->results->deterministic($deterministicContent);
        }
        if ($evidenceRequired && ($guidance === [] || $sources === [])) {
            return $this->results->rejected(
                $sources,
                $responseLanguage,
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

                return $this->results->providerFallback(
                    $failure,
                    $guidance,
                    $sources,
                    $responseLanguage,
                    $attempt,
                    $responseIds,
                    $providerRequestIds,
                    $usage,
                    $generationDuration,
                    $verificationDuration,
                    $pipelineStartedAt,
                );
            }
            $generationDuration += $this->elapsedMilliseconds($generationStartedAt);
            $this->captureProviderMetadata($providerResult, $responseIds, $providerRequestIds, $usage);

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
                return $this->results->verified(
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
                );
            }

            $failureCategory = $this->validator->failureCategory($validation);
            if ($failureCategory === 'evidence_incomplete') {
                return $this->results->extractiveFallback(
                    $guidance,
                    $sources,
                    $responseLanguage,
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
            }
            if ($failureCategory === 'verification_unavailable') {
                return $this->results->extractiveFallback(
                    $guidance,
                    $sources,
                    $responseLanguage,
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
            return $this->results->verified(
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
            );
        }

        return $this->results->extractiveFallback(
            $guidance,
            $sources,
            $responseLanguage,
            'validation_failed',
            'AI_HELPER_VALIDATION_FAILED',
            $failureCategory === 'citation_format' ? 'repair_citations' : 'remove_unsupported_claims',
            $attempt,
            $responseIds,
            $providerRequestIds,
            $usage,
            $generationDuration,
            $verificationDuration,
            $pipelineStartedAt,
            $validation,
        );
    }

    /** @param array<int, string> $responseIds @param array<int, string> $providerRequestIds @param array<string, int> $usage */
    private function captureProviderMetadata(
        array $result,
        array &$responseIds,
        array &$providerRequestIds,
        array &$usage,
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
}
