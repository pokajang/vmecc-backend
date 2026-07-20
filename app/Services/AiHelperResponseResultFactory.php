<?php

namespace App\Services;

final class AiHelperResponseResultFactory
{
    public function __construct(
        private readonly AiHelperCitationValidator $citationValidator,
        private readonly AiHelperCriticalFactValidator $criticalFactValidator,
        private readonly AiHelperExtractiveAnswerRenderer $extractiveRenderer,
    ) {}

    /** @return array<string, mixed> */
    public function deterministic(string $content, array $sources = []): array
    {
        $citedIds = collect($sources)
            ->pluck('source_id')
            ->filter(fn ($sourceId) => is_string($sourceId) && str_contains($content, '['.$sourceId.']'))
            ->values();
        $displaySources = $citedIds->isEmpty()
            ? []
            : collect($sources)->whereIn('source_id', $citedIds->all())->values()->all();

        return [
            'content' => $content,
            'sources' => $displaySources,
            'response_id' => null,
            'provider_response_ids' => [],
            'provider_request_ids' => [],
            'usage' => [],
            'outcome_code' => 'AI_HELPER_DETERMINISTIC',
            'recovery_action' => null,
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

    /** @return array<string, mixed> */
    public function verified(
        string $draft,
        array $sources,
        array $responseIds,
        array $providerRequestIds,
        array $usage,
        int $generationDuration,
        int $verificationDuration,
        float $pipelineStartedAt,
        int $attempt,
        array $validation,
        string $status = 'verified',
        string $outcomeCode = 'AI_HELPER_VERIFIED',
    ): array {
        return [
            'content' => $draft,
            'sources' => $sources,
            'response_id' => $responseIds === [] ? null : end($responseIds),
            'provider_response_ids' => array_values(array_unique($responseIds)),
            'provider_request_ids' => array_values(array_unique($providerRequestIds)),
            'usage' => $usage,
            'outcome_code' => $outcomeCode,
            'recovery_action' => null,
            'timings_ms' => $this->timings($generationDuration, $verificationDuration, $pipelineStartedAt),
            'verification' => [
                'status' => $status,
                'attempts' => $attempt,
                ...$validation,
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function providerFallback(
        AiHelperProviderException $failure,
        array $guidance,
        array $sources,
        string $responseLanguage,
        int $attempt,
        array $responseIds,
        array $providerRequestIds,
        array $usage,
        int $generationDuration,
        int $verificationDuration,
        float $pipelineStartedAt,
    ): array {
        if ($failure->providerRequestId !== null) {
            $providerRequestIds[] = $failure->providerRequestId;
        }

        return $this->extractiveFallback(
            $guidance,
            $sources,
            $responseLanguage,
            'provider_unavailable',
            $failure->failureCode,
            $failure->retryable ? 'retry_provider' : 'check_provider_configuration',
            $attempt,
            $responseIds,
            $providerRequestIds,
            $usage,
            $generationDuration,
            $verificationDuration,
            $pipelineStartedAt,
            [
                'valid' => false,
                'citation_validation' => ['valid' => false, 'status' => 'not_run'],
                'critical_fact_validation' => ['valid' => false, 'status' => 'not_run', 'failures' => []],
                'grounding_verification' => [
                    'valid' => false,
                    'status' => 'provider_unavailable',
                    'failures' => [$failure->context()],
                ],
            ],
        );
    }

    /** @return array<string, mixed> */
    public function extractiveFallback(
        array $guidance,
        array $sources,
        string $responseLanguage,
        string $reason,
        string $outcomeCode,
        string $recoveryAction,
        int $attempt,
        array $responseIds,
        array $providerRequestIds,
        array $usage,
        int $generationDuration,
        int $verificationDuration,
        float $pipelineStartedAt,
        array $validation,
    ): array {
        $extractive = $this->extractiveRenderer->render($guidance, $sources, $responseLanguage, $reason);
        if ($extractive === null) {
            return $this->rejected(
                $sources,
                $responseLanguage,
                $outcomeCode,
                $recoveryAction,
                $attempt,
                $responseIds,
                $providerRequestIds,
                $generationDuration,
                $verificationDuration,
                $pipelineStartedAt,
                $validation,
                $usage,
            );
        }

        $citation = $this->citationValidator->validate($extractive['content'], $extractive['sources']);
        $critical = $citation['valid']
            ? $this->criticalFactValidator->validate($extractive['content'], $guidance)
            : ['valid' => false, 'status' => 'skipped', 'failures' => []];
        if (! $citation['valid'] || ! $critical['valid']) {
            return $this->rejected(
                $sources,
                $responseLanguage,
                $outcomeCode,
                $recoveryAction,
                $attempt,
                $responseIds,
                $providerRequestIds,
                $generationDuration,
                $verificationDuration,
                $pipelineStartedAt,
                $validation,
                $usage,
            );
        }

        return [
            'content' => $extractive['content'],
            'sources' => $extractive['sources'],
            'response_id' => $responseIds === [] ? null : end($responseIds),
            'provider_response_ids' => array_values(array_unique($responseIds)),
            'provider_request_ids' => array_values(array_unique($providerRequestIds)),
            'usage' => $usage,
            'outcome_code' => $outcomeCode,
            'recovery_action' => $recoveryAction,
            'timings_ms' => $this->timings($generationDuration, $verificationDuration, $pipelineStartedAt),
            'verification' => [
                'status' => 'fallback_extractive',
                'attempts' => $attempt,
                'failure' => $validation,
                'citation_validation' => $citation,
                'critical_fact_validation' => $critical,
                'grounding_verification' => [
                    'valid' => true,
                    'status' => 'not_required_for_verbatim_extract',
                    'failures' => [],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function rejected(
        array $sources,
        string $responseLanguage,
        string $outcomeCode,
        ?string $recoveryAction,
        int $attempt,
        array $responseIds,
        array $providerRequestIds,
        int $generationDuration,
        int $verificationDuration,
        float $pipelineStartedAt,
        array $validation,
        array $usage = [],
    ): array {
        $rejection = $this->citationValidator->enforce('', $sources, $responseLanguage);
        $content = $outcomeCode === 'AI_HELPER_NO_AUTHORIZED_EVIDENCE'
            ? ($responseLanguage === 'bm'
                ? 'Tiada arahan diluluskan yang berkaitan tersedia untuk permintaan ini dalam akses VMECC semasa anda. Jika tugas ini sebahagian daripada tanggungjawab anda, minta penyelia atau pentadbir menyemak akses anda.'
                : 'No applicable approved instructions are available for this request within your current VMECC access. If this task is part of your responsibility, ask your supervisor or administrator to check your access.')
            : $rejection['content'];

        return [
            'content' => $content,
            'sources' => [],
            'response_id' => $responseIds === [] ? null : end($responseIds),
            'provider_response_ids' => array_values(array_unique($responseIds)),
            'provider_request_ids' => array_values(array_unique($providerRequestIds)),
            'usage' => $usage,
            'outcome_code' => $outcomeCode,
            'recovery_action' => $recoveryAction,
            'timings_ms' => $this->timings($generationDuration, $verificationDuration, $pipelineStartedAt),
            'verification' => [
                ...$validation,
                'status' => 'rejected',
                'attempts' => $attempt,
            ],
        ];
    }

    /** @return array{generation: int, verification: int, total: int} */
    private function timings(int $generation, int $verification, float $pipelineStartedAt): array
    {
        return [
            'generation' => $generation,
            'verification' => $verification,
            'total' => (int) ((microtime(true) - $pipelineStartedAt) * 1000),
        ];
    }
}
