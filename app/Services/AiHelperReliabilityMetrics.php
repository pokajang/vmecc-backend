<?php

namespace App\Services;

use App\Models\AiHelperMessage;
use App\Models\AiHelperRun;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class AiHelperReliabilityMetrics
{
    /** @return array<string, int|float|null> */
    public function recent(int $limit = 500): array
    {
        $limit = max(1, min(2000, $limit));
        if ($this->runTelemetryReady()) {
            $runs = $this->recentRuns($limit);
            if ($runs->isNotEmpty()) {
                return $this->metricsFromRuns($runs);
            }
        }

        return $this->recentFromMessageMetadata($limit);
    }

    protected function runTelemetryReady(): bool
    {
        return Schema::hasTable('ai_helper_runs')
            && Schema::hasColumns('ai_helper_runs', [
                'status',
                'result_code',
                'verification_status',
                'verification_attempts',
                'retrieval_recovered',
                'semantic_fallback',
                'rerank_fallback',
                'provider_calls',
                'input_tokens',
                'output_tokens',
                'duration_ms',
                'answer_mode',
                'workflow_key',
            ]);
    }

    /** @return Collection<int, AiHelperRun> */
    protected function recentRuns(int $limit): Collection
    {
        return AiHelperRun::query()
            ->latest('id')
            ->limit($limit)
            ->get([
                'status',
                'result_code',
                'verification_status',
                'verification_attempts',
                'retrieval_recovered',
                'semantic_fallback',
                'rerank_fallback',
                'provider_calls',
                'input_tokens',
                'output_tokens',
                'duration_ms',
                'answer_mode',
                'workflow_key',
            ]);
    }

    /**
     * @param  Collection<int, AiHelperRun>  $runs
     * @return array<string, int|float|null>
     */
    private function metricsFromRuns(Collection $runs): array
    {
        $total = $runs->count();
        $count = fn (callable $predicate) => $runs->filter($predicate)->count();
        $completed = $count(fn (AiHelperRun $run) => $run->status === AiHelperRun::STATUS_COMPLETED);
        $failed = $count(fn (AiHelperRun $run) => $run->status === AiHelperRun::STATUS_FAILED);
        $aborted = $count(fn (AiHelperRun $run) => $run->status === AiHelperRun::STATUS_ABORTED);
        $started = $count(fn (AiHelperRun $run) => $run->status === AiHelperRun::STATUS_STARTED);
        $verified = $count(fn (AiHelperRun $run) => $run->verification_status === 'verified');
        $rejected = $count(fn (AiHelperRun $run) => $run->verification_status === 'rejected');
        $fallbacks = $count(fn (AiHelperRun $run) => $run->verification_status === 'fallback_extractive');
        $noEvidence = $count(fn (AiHelperRun $run) => $run->result_code === 'AI_HELPER_NO_AUTHORIZED_EVIDENCE');
        $providerFailures = $count(fn (AiHelperRun $run) => str_starts_with(
            (string) $run->result_code,
            'AI_HELPER_PROVIDER_',
        ) || $run->result_code === 'AI_HELPER_VERIFICATION_TEMPORARY');
        $retrievalRecoveries = $count(fn (AiHelperRun $run) => (bool) $run->retrieval_recovered);
        $rerankFallbacks = $count(fn (AiHelperRun $run) => (bool) $run->rerank_fallback);
        $semanticFallbacks = $count(fn (AiHelperRun $run) => (bool) $run->semantic_fallback);
        $repaired = $count(fn (AiHelperRun $run) => $run->verification_status === 'verified'
            && (int) $run->verification_attempts > 1);
        $shadowFailures = $count(fn (AiHelperRun $run) => $run->verification_status === 'shadow_failed');
        $casualAnswers = $count(fn (AiHelperRun $run) => $run->answer_mode === 'casual');
        $capabilityAnswers = $count(fn (AiHelperRun $run) => $run->answer_mode === 'product_capability');
        $navigationAnswers = $count(fn (AiHelperRun $run) => $run->answer_mode === 'product_navigation');
        $workflowAnswers = $count(fn (AiHelperRun $run) => $run->answer_mode === 'product_workflow');
        $operationalAnswers = $count(fn (AiHelperRun $run) => $run->answer_mode === 'operational_knowledge');
        $deterministicWorkflows = $count(fn (AiHelperRun $run) => filled($run->workflow_key));
        $durations = $runs
            ->map(fn (AiHelperRun $run) => (int) $run->duration_ms)
            ->filter(fn (int $duration) => $duration > 0)
            ->sort()
            ->values()
            ->all();
        $inputTokens = (int) $runs->sum(fn (AiHelperRun $run) => max(0, (int) $run->input_tokens));
        $outputTokens = (int) $runs->sum(fn (AiHelperRun $run) => max(0, (int) $run->output_tokens));

        return [
            'sample_size' => $total,
            'completed' => $completed,
            'failed' => $failed,
            'aborted' => $aborted,
            'started' => $started,
            'verified' => $verified,
            'repaired' => $repaired,
            'rejected' => $rejected,
            'fallbacks' => $fallbacks,
            'no_evidence' => $noEvidence,
            'provider_failures' => $providerFailures,
            'retrieval_recoveries' => $retrievalRecoveries,
            'semantic_fallbacks' => $semanticFallbacks,
            'rerank_fallbacks' => $rerankFallbacks,
            'grounding_shadow_failures' => $shadowFailures,
            'casual_answers' => $casualAnswers,
            'product_capability_answers' => $capabilityAnswers,
            'product_navigation_answers' => $navigationAnswers,
            'product_workflow_answers' => $workflowAnswers,
            'operational_knowledge_answers' => $operationalAnswers,
            'deterministic_workflow_answers' => $deterministicWorkflows,
            'verification_pass_rate' => $this->rate($verified, $completed),
            'completion_rate' => $this->rate($completed, $total),
            'failure_rate' => $this->rate($failed, $total),
            'abort_rate' => $this->rate($aborted, $total),
            'fallback_rate' => $this->rate($fallbacks, $completed),
            'no_evidence_rate' => $this->rate($noEvidence, $completed),
            'provider_failure_rate' => $this->rate($providerFailures, $total),
            'median_response_ms' => $this->percentile($durations, 0.5),
            'p95_response_ms' => $this->percentile($durations, 0.95),
            'total_provider_calls' => (int) $runs->sum(fn (AiHelperRun $run) => max(0, (int) $run->provider_calls)),
            'total_input_tokens' => $inputTokens,
            'total_output_tokens' => $outputTokens,
            'total_tokens' => $inputTokens + $outputTokens,
        ];
    }

    /** @return array<string, int|float|null> */
    protected function recentFromMessageMetadata(int $limit): array
    {
        if (! Schema::hasColumn('ai_helper_messages', 'retrieval_metadata')) {
            return $this->emptyMetrics();
        }

        $messages = AiHelperMessage::query()
            ->where('role', AiHelperMessage::ROLE_ASSISTANT)
            ->where('status', AiHelperMessage::STATUS_COMPLETED)
            ->whereNotNull('retrieval_metadata')
            ->latest('id')
            ->limit($limit)
            ->get(['retrieval_metadata']);

        return $this->metricsFromMessages($messages);
    }

    /**
     * @param  Collection<int, AiHelperMessage>  $messages
     * @return array<string, int|float|null>
     */
    private function metricsFromMessages(Collection $messages): array
    {
        $total = $messages->count();
        $count = fn (callable $predicate) => $messages->filter(
            fn (AiHelperMessage $message) => $predicate($message->retrieval_metadata ?? [])
        )->count();
        $verified = $count(fn (array $metadata) => Arr::get($metadata, 'verification.status') === 'verified');
        $rejected = $count(fn (array $metadata) => Arr::get($metadata, 'verification.status') === 'rejected');
        $fallbacks = $count(fn (array $metadata) => Arr::get($metadata, 'verification.status') === 'fallback_extractive');
        $providerFailures = $count(fn (array $metadata) => Arr::get(
            $metadata,
            'verification.grounding_verification.status',
        ) === 'provider_unavailable' || Arr::get(
            $metadata,
            'verification.failure.grounding_verification.status',
        ) === 'provider_unavailable');
        $durations = $messages
            ->map(fn (AiHelperMessage $message) => (int) Arr::get(
                $message->retrieval_metadata ?? [],
                'response_timings_ms.total',
                0,
            ))
            ->filter(fn (int $duration) => $duration > 0)
            ->sort()
            ->values()
            ->all();

        return [
            'sample_size' => $total,
            'completed' => $total,
            'failed' => 0,
            'aborted' => 0,
            'started' => 0,
            'verified' => $verified,
            'repaired' => $count(fn (array $metadata) => (int) Arr::get($metadata, 'verification.attempts', 0) > 1
                && Arr::get($metadata, 'verification.status') === 'verified'),
            'rejected' => $rejected,
            'fallbacks' => $fallbacks,
            'no_evidence' => 0,
            'provider_failures' => $providerFailures,
            'retrieval_recoveries' => $count(fn (array $metadata) => (bool) ($metadata['recovery_succeeded'] ?? false)),
            'semantic_fallbacks' => $count(fn (array $metadata) => (bool) ($metadata['semantic_fallback'] ?? false)),
            'rerank_fallbacks' => $count(fn (array $metadata) => (bool) Arr::get($metadata, 'rerank.fallback', false)),
            'grounding_shadow_failures' => $count(fn (array $metadata) => Arr::get(
                $metadata,
                'verification.grounding_verification.would_pass',
            ) === false),
            'casual_answers' => 0,
            'product_capability_answers' => 0,
            'product_navigation_answers' => 0,
            'product_workflow_answers' => 0,
            'operational_knowledge_answers' => 0,
            'deterministic_workflow_answers' => 0,
            'verification_pass_rate' => $this->rate($verified, $total),
            'completion_rate' => $this->rate($total, $total),
            'failure_rate' => $this->rate(0, $total),
            'abort_rate' => $this->rate(0, $total),
            'fallback_rate' => $this->rate($fallbacks, $total),
            'no_evidence_rate' => $this->rate(0, $total),
            'provider_failure_rate' => $this->rate($providerFailures, $total),
            'median_response_ms' => $this->percentile($durations, 0.5),
            'p95_response_ms' => $this->percentile($durations, 0.95),
            'total_provider_calls' => 0,
            'total_input_tokens' => 0,
            'total_output_tokens' => 0,
            'total_tokens' => 0,
        ];
    }

    private function rate(int $numerator, int $denominator): ?float
    {
        return $denominator > 0 ? round($numerator / $denominator, 4) : null;
    }

    /** @param array<int, int> $sorted */
    private function percentile(array $sorted, float $percentile): ?int
    {
        if ($sorted === []) {
            return null;
        }

        $index = max(0, min(count($sorted) - 1, (int) ceil(count($sorted) * $percentile) - 1));

        return $sorted[$index];
    }

    /** @return array<string, int|float|null> */
    private function emptyMetrics(): array
    {
        return [
            'sample_size' => 0,
            'completed' => 0,
            'failed' => 0,
            'aborted' => 0,
            'started' => 0,
            'verified' => 0,
            'repaired' => 0,
            'rejected' => 0,
            'fallbacks' => 0,
            'no_evidence' => 0,
            'provider_failures' => 0,
            'retrieval_recoveries' => 0,
            'semantic_fallbacks' => 0,
            'rerank_fallbacks' => 0,
            'grounding_shadow_failures' => 0,
            'casual_answers' => 0,
            'product_capability_answers' => 0,
            'product_navigation_answers' => 0,
            'product_workflow_answers' => 0,
            'operational_knowledge_answers' => 0,
            'deterministic_workflow_answers' => 0,
            'verification_pass_rate' => null,
            'completion_rate' => null,
            'failure_rate' => null,
            'abort_rate' => null,
            'fallback_rate' => null,
            'no_evidence_rate' => null,
            'provider_failure_rate' => null,
            'median_response_ms' => null,
            'p95_response_ms' => null,
            'total_provider_calls' => 0,
            'total_input_tokens' => 0,
            'total_output_tokens' => 0,
            'total_tokens' => 0,
        ];
    }
}
