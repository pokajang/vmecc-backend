<?php

namespace Tests\Unit;

use App\Models\AiHelperMessage;
use App\Models\AiHelperRun;
use App\Services\AiHelperReliabilityMetrics;
use Illuminate\Support\Collection;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

class AiHelperReliabilityMetricsTest extends TestCase
{
    public function test_run_telemetry_produces_release_metrics_and_preserves_legacy_keys(): void
    {
        $runs = collect([
            $this->makeRun(AiHelperRun::STATUS_COMPLETED, 'AI_HELPER_VERIFIED', 'verified', 100, [
                'answer_mode' => 'general_conversation',
                'provider_calls' => 2,
                'input_tokens' => 100,
                'output_tokens' => 10,
                'retrieval_recovered' => true,
                'semantic_fallback' => true,
                'verification_attempts' => 2,
            ]),
            $this->makeRun(AiHelperRun::STATUS_COMPLETED, 'AI_HELPER_VERIFIED', 'verified', 200, [
                'answer_mode' => 'product_capability',
                'provider_calls' => 2,
                'input_tokens' => 200,
                'output_tokens' => 20,
                'rerank_fallback' => true,
            ]),
            $this->makeRun(AiHelperRun::STATUS_COMPLETED, 'AI_HELPER_VALIDATION_FAILED', 'rejected', 300, [
                'answer_mode' => 'product_navigation',
                'provider_calls' => 2,
            ]),
            $this->makeRun(AiHelperRun::STATUS_COMPLETED, 'AI_HELPER_VERIFICATION_SHADOW_FAILED', 'shadow_failed', 350, [
                'answer_mode' => 'product_workflow',
                'workflow_key' => 'inspection.conduct.fire_truck',
                'provider_calls' => 2,
            ]),
            $this->makeRun(AiHelperRun::STATUS_COMPLETED, 'AI_HELPER_NO_AUTHORIZED_EVIDENCE', 'rejected', 400, [
                'answer_mode' => 'operational_knowledge',
            ]),
            $this->makeRun(AiHelperRun::STATUS_COMPLETED, 'AI_HELPER_PROVIDER_TIMEOUT', 'fallback_extractive', 500, [
                'provider_calls' => 1,
            ]),
            $this->makeRun(AiHelperRun::STATUS_FAILED, 'AI_HELPER_PROVIDER_UNAVAILABLE', null, 600, [
                'provider_calls' => 1,
            ]),
            $this->makeRun(AiHelperRun::STATUS_ABORTED, 'AI_HELPER_STREAM_ABORTED', null, 700),
            $this->makeRun(AiHelperRun::STATUS_STARTED, null, null, null),
        ]);

        $metrics = $this->invokeMetrics('metricsFromRuns', $runs);

        $this->assertSame(9, $metrics['sample_size']);
        $this->assertSame(6, $metrics['completed']);
        $this->assertSame(1, $metrics['failed']);
        $this->assertSame(1, $metrics['aborted']);
        $this->assertSame(1, $metrics['started']);
        $this->assertSame(2, $metrics['verified']);
        $this->assertSame(1, $metrics['repaired']);
        $this->assertSame(2, $metrics['rejected']);
        $this->assertSame(1, $metrics['fallbacks']);
        $this->assertSame(1, $metrics['no_evidence']);
        $this->assertSame(2, $metrics['provider_failures']);
        $this->assertSame(1, $metrics['retrieval_recoveries']);
        $this->assertSame(1, $metrics['semantic_fallbacks']);
        $this->assertSame(1, $metrics['rerank_fallbacks']);
        $this->assertSame(1, $metrics['grounding_shadow_failures']);
        $this->assertSame(1, $metrics['casual_answers']);
        $this->assertSame(1, $metrics['general_conversation_answers']);
        $this->assertSame(1, $metrics['product_capability_answers']);
        $this->assertSame(1, $metrics['product_navigation_answers']);
        $this->assertSame(1, $metrics['product_workflow_answers']);
        $this->assertSame(1, $metrics['operational_knowledge_answers']);
        $this->assertSame(1, $metrics['deterministic_workflow_answers']);
        $this->assertSame(0.3333, $metrics['verification_pass_rate']);
        $this->assertSame(0.6667, $metrics['completion_rate']);
        $this->assertSame(0.1111, $metrics['failure_rate']);
        $this->assertSame(0.1111, $metrics['abort_rate']);
        $this->assertSame(0.1667, $metrics['fallback_rate']);
        $this->assertSame(0.1667, $metrics['no_evidence_rate']);
        $this->assertSame(0.2222, $metrics['provider_failure_rate']);
        $this->assertSame(350, $metrics['median_response_ms']);
        $this->assertSame(700, $metrics['p95_response_ms']);
        $this->assertSame(10, $metrics['total_provider_calls']);
        $this->assertSame(300, $metrics['total_input_tokens']);
        $this->assertSame(30, $metrics['total_output_tokens']);
        $this->assertSame(330, $metrics['total_tokens']);
    }

    public function test_message_metadata_fallback_retains_historical_metrics(): void
    {
        $messages = collect([
            $this->message([
                'semantic_fallback' => true,
                'recovery_succeeded' => true,
                'rerank' => ['fallback' => true],
                'response_timings_ms' => ['total' => 100],
                'verification' => ['status' => 'verified', 'attempts' => 2],
            ]),
            $this->message([
                'response_timings_ms' => ['total' => 300],
                'verification' => [
                    'status' => 'rejected',
                    'grounding_verification' => ['would_pass' => false],
                ],
            ]),
            $this->message([
                'response_timings_ms' => ['total' => 200],
                'verification' => [
                    'status' => 'fallback_extractive',
                    'failure' => [
                        'grounding_verification' => ['status' => 'provider_unavailable'],
                    ],
                ],
            ]),
        ]);

        $metrics = $this->invokeMetrics('metricsFromMessages', $messages);

        $this->assertSame(3, $metrics['sample_size']);
        $this->assertSame(3, $metrics['completed']);
        $this->assertSame(1, $metrics['verified']);
        $this->assertSame(1, $metrics['repaired']);
        $this->assertSame(1, $metrics['rejected']);
        $this->assertSame(1, $metrics['fallbacks']);
        $this->assertSame(1, $metrics['provider_failures']);
        $this->assertSame(1, $metrics['retrieval_recoveries']);
        $this->assertSame(1, $metrics['semantic_fallbacks']);
        $this->assertSame(1, $metrics['rerank_fallbacks']);
        $this->assertSame(1, $metrics['grounding_shadow_failures']);
        $this->assertSame(0.3333, $metrics['verification_pass_rate']);
        $this->assertSame(200, $metrics['median_response_ms']);
        $this->assertSame(300, $metrics['p95_response_ms']);
    }

    public function test_recent_prefers_non_empty_run_telemetry_and_falls_back_when_no_runs_exist(): void
    {
        $run = $this->makeRun(AiHelperRun::STATUS_COMPLETED, 'AI_HELPER_VERIFIED', 'verified', 100);
        $runBacked = Mockery::mock(AiHelperReliabilityMetrics::class)
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();
        $runBacked->shouldReceive('runTelemetryReady')->once()->andReturnTrue();
        $runBacked->shouldReceive('recentRuns')->once()->with(25)->andReturn(collect([$run]));
        $runBacked->shouldNotReceive('recentFromMessageMetadata');

        $this->assertSame(1, $runBacked->recent(25)['sample_size']);

        $fallback = Mockery::mock(AiHelperReliabilityMetrics::class)
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();
        $fallback->shouldReceive('runTelemetryReady')->once()->andReturnTrue();
        $fallback->shouldReceive('recentRuns')->once()->with(25)->andReturn(collect());
        $fallback->shouldReceive('recentFromMessageMetadata')->once()->with(25)->andReturn([
            'sample_size' => 7,
        ]);

        $this->assertSame(7, $fallback->recent(25)['sample_size']);
    }

    /** @param array<string, mixed> $overrides */
    private function makeRun(
        string $status,
        ?string $resultCode,
        ?string $verificationStatus,
        ?int $duration,
        array $overrides = [],
    ): AiHelperRun {
        return new AiHelperRun(array_merge([
            'status' => $status,
            'result_code' => $resultCode,
            'verification_status' => $verificationStatus,
            'duration_ms' => $duration,
        ], $overrides));
    }

    /** @param array<string, mixed> $metadata */
    private function message(array $metadata): AiHelperMessage
    {
        return new AiHelperMessage(['retrieval_metadata' => $metadata]);
    }

    /**
     * @param  Collection<int, AiHelperRun>|Collection<int, AiHelperMessage>  $records
     * @return array<string, int|float|null>
     */
    private function invokeMetrics(string $method, Collection $records): array
    {
        return (new ReflectionMethod(AiHelperReliabilityMetrics::class, $method))
            ->invoke(new AiHelperReliabilityMetrics, $records);
    }
}
