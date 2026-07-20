<?php

namespace Tests\Unit;

use App\Services\AiHelperOpenAiService;
use App\Services\AiHelperProviderException;
use App\Services\AiHelperResponsePipeline;
use Tests\TestCase;

class AiHelperResponsePipelineTest extends TestCase
{
    private array $guidance = [[
        'source_id' => 'S1',
        'source_document_id' => 10,
        'title' => 'ANNEX 1 Terminologies and Definitions',
        'content' => '999 is the official Malaysian Emergency Service Centre telephone number.',
    ]];

    private array $sources = [[
        'source_id' => 'S1',
        'document_id' => 10,
        'title' => 'ANNEX 1 Terminologies and Definitions',
    ]];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'ai_helper.verification_max_attempts' => 2,
            'ai_helper.grounding_verification_mode' => 'disabled',
            'ai_helper.critical_fact_validation_enabled' => true,
        ]);
    }

    public function test_it_repairs_a_failed_draft_once_before_returning_it(): void
    {
        $attempt = 0;
        $this->mock(AiHelperOpenAiService::class, function ($mock) use (&$attempt) {
            $mock->shouldReceive('streamResponse')->twice()->andReturnUsing(
                function ($instructions, $input, $onDelta) use (&$attempt) {
                    $attempt++;
                    $onDelta($attempt === 1
                        ? '999 is the emergency number.'
                        : '999 is the official Malaysian Emergency Service Centre telephone number. [S1]');

                    return ['response_id' => 'response-'.$attempt];
                },
            );
        });

        $result = app(AiHelperResponsePipeline::class)->respond(
            'What is 999?',
            'Use the evidence.',
            [['role' => 'user', 'content' => 'What is 999?']],
            $this->guidance,
            $this->sources,
            null,
            'en',
            fn () => null,
            fn () => null,
        );

        $this->assertSame('verified', $result['verification']['status']);
        $this->assertSame(2, $result['verification']['attempts']);
        $this->assertStringContainsString('[S1]', $result['content']);
        $this->assertSame(['response-1', 'response-2'], $result['provider_response_ids']);
    }

    public function test_it_returns_a_safe_approved_extract_after_the_repair_also_fails(): void
    {
        $this->mock(AiHelperOpenAiService::class, function ($mock) {
            $mock->shouldReceive('streamResponse')->twice()->andReturnUsing(function ($instructions, $input, $onDelta) {
                $onDelta('Call 999 immediately for every incident.');

                return ['response_id' => 'failed-response'];
            });
        });

        $result = app(AiHelperResponsePipeline::class)->respond(
            'What is 999?',
            'Use the evidence.',
            [['role' => 'user', 'content' => 'What is 999?']],
            $this->guidance,
            $this->sources,
            null,
            'en',
            fn () => null,
            fn () => null,
        );

        $this->assertSame('fallback_extractive', $result['verification']['status']);
        $this->assertSame(['S1'], collect($result['sources'])->pluck('source_id')->all());
        $this->assertStringContainsString('supporting guidance directly', $result['content']);
        $this->assertStringContainsString('official Malaysian Emergency Service Centre', $result['content']);
        $this->assertStringNotContainsString('Call 999 immediately', $result['content']);
    }

    public function test_shadow_grounding_failure_triggers_one_repair_attempt(): void
    {
        config(['ai_helper.grounding_verification_mode' => 'shadow']);
        $verificationResponses = [
            [
                'verdict' => 'revise',
                'question_answered' => true,
                'claims' => [[
                    'claim' => '999 is a routine office number.',
                    'source_ids' => ['S1'],
                    'supported' => false,
                    'contradicted' => true,
                    'missing_qualifier' => false,
                    'reason' => 'Contradicted by the source.',
                ]],
                'missing_requested_facts' => [],
            ],
            [
                'verdict' => 'pass',
                'question_answered' => true,
                'claims' => [[
                    'claim' => '999 is the official Malaysian Emergency Service Centre telephone number.',
                    'source_ids' => ['S1'],
                    'supported' => true,
                    'contradicted' => false,
                    'missing_qualifier' => false,
                    'reason' => null,
                ]],
                'missing_requested_facts' => [],
            ],
        ];
        $attempt = 0;
        $this->mock(AiHelperOpenAiService::class, function ($mock) use (&$attempt, &$verificationResponses) {
            $mock->shouldReceive('streamResponse')->twice()->andReturnUsing(
                function ($instructions, $input, $onDelta) use (&$attempt) {
                    $attempt++;
                    $onDelta($attempt === 1
                        ? '999 is a routine office number. [S1]'
                        : '999 is the official Malaysian Emergency Service Centre telephone number. [S1]');

                    return ['response_id' => 'shadow-response-'.$attempt];
                },
            );
            $mock->shouldReceive('structuredResponse')->twice()->andReturnUsing(
                function () use (&$verificationResponses) {
                    return ['response_id' => 'verifier', 'data' => array_shift($verificationResponses)];
                },
            );
        });

        $result = app(AiHelperResponsePipeline::class)->respond(
            'What is 999?',
            'Use the evidence.',
            [['role' => 'user', 'content' => 'What is 999?']],
            $this->guidance,
            $this->sources,
            null,
            'en',
            fn () => null,
            fn () => null,
        );

        $this->assertSame('verified', $result['verification']['status']);
        $this->assertSame(2, $result['verification']['attempts']);
        $this->assertStringContainsString('official Malaysian Emergency Service Centre', $result['content']);
    }

    public function test_it_deterministically_adds_a_missing_revision_status_label(): void
    {
        config(['ai_helper.grounding_verification_mode' => 'shadow']);
        $guidance = [
            [
                'source_id' => 'S1',
                'source_document_id' => 18,
                'title' => 'ANNEX 18 ERP for Man Overboard (MOB).Rev 001 - Feb 2026',
                'content' => 'Revised procedure.',
            ],
            [
                'source_id' => 'S2',
                'source_document_id' => 19,
                'title' => 'ANNEX 18 ERP for Man Overboard (MOB)',
                'content' => 'Original procedure.',
            ],
        ];
        $sources = [
            ['source_id' => 'S1', 'document_id' => 18, 'title' => $guidance[0]['title']],
            ['source_id' => 'S2', 'document_id' => 19, 'title' => $guidance[1]['title']],
        ];
        $this->mock(AiHelperOpenAiService::class, function ($mock) {
            $mock->shouldReceive('streamResponse')->once()->andReturnUsing(function ($instructions, $input, $onDelta) {
                $onDelta('Two Annex 18 sources are available. [S1][S2]');

                return ['response_id' => 'revision-response'];
            });
            $mock->shouldReceive('structuredResponse')->once()->andReturn([
                'response_id' => 'revision-verifier',
                'data' => [
                    'verdict' => 'pass',
                    'question_answered' => true,
                    'claims' => [[
                        'claim' => 'The unrevisioned title has no revision stated.',
                        'source_ids' => ['S2'],
                        'supported' => true,
                        'contradicted' => false,
                        'missing_qualifier' => false,
                        'reason' => null,
                    ]],
                    'missing_requested_facts' => [],
                ],
            ]);
        });

        $result = app(AiHelperResponsePipeline::class)->respond(
            'Which Annex 18 revisions are available?',
            'Use the evidence.',
            [['role' => 'user', 'content' => 'Which Annex 18 revisions are available?']],
            $guidance,
            $sources,
            null,
            'en',
            fn () => null,
            fn () => null,
        );

        $this->assertSame('verified', $result['verification']['status']);
        $this->assertSame(1, $result['verification']['attempts']);
        $this->assertStringContainsString('revision not stated [S2]', $result['content']);
    }

    public function test_it_requires_evidence_before_generating_an_operational_answer(): void
    {
        $this->mock(AiHelperOpenAiService::class, function ($mock) {
            $mock->shouldNotReceive('streamResponse');
        });

        $result = app(AiHelperResponsePipeline::class)->respond(
            'How do I apply for leave?',
            'Use the evidence.',
            [['role' => 'user', 'content' => 'How do I apply for leave?']],
            [],
            [],
            null,
            'en',
            fn () => null,
            fn () => null,
        );

        $this->assertSame('AI_HELPER_NO_AUTHORIZED_EVIDENCE', $result['outcome_code']);
        $this->assertSame('retrieve_more_evidence', $result['recovery_action']);
        $this->assertSame('rejected', $result['verification']['status']);
        $this->assertStringContainsString('within your current VMECC access', $result['content']);
    }

    public function test_it_returns_a_direct_approved_extract_when_generation_is_temporarily_unavailable(): void
    {
        $this->mock(AiHelperOpenAiService::class, function ($mock) {
            $mock->shouldReceive('streamResponse')->once()->andThrow(new AiHelperProviderException(
                'AI_HELPER_PROVIDER_RATE_LIMITED',
                'rate limited',
                true,
                429,
                'req-rate',
            ));
        });

        $result = app(AiHelperResponsePipeline::class)->respond(
            'What is 999?',
            'Use the evidence.',
            [['role' => 'user', 'content' => 'What is 999?']],
            $this->guidance,
            $this->sources,
            null,
            'en',
            fn () => null,
            fn () => null,
        );

        $this->assertSame('AI_HELPER_PROVIDER_RATE_LIMITED', $result['outcome_code']);
        $this->assertSame('retry_provider', $result['recovery_action']);
        $this->assertSame('fallback_extractive', $result['verification']['status']);
        $this->assertStringContainsString('999 is the official', $result['content']);
        $this->assertSame(['req-rate'], $result['provider_request_ids']);
        $this->assertSame(['S1'], collect($result['sources'])->pluck('source_id')->all());
    }

    public function test_missing_requested_evidence_signals_retrieval_recovery_without_a_second_generation(): void
    {
        config(['ai_helper.grounding_verification_mode' => 'enforce']);
        $this->mock(AiHelperOpenAiService::class, function ($mock) {
            $mock->shouldReceive('streamResponse')->once()->andReturnUsing(function ($instructions, $input, $onDelta) {
                $onDelta('999 is the official emergency number. [S1]');

                return ['response_id' => 'answer-1'];
            });
            $mock->shouldReceive('structuredResponse')->once()->andReturn([
                'response_id' => 'verify-1',
                'data' => [
                    'verdict' => 'revise',
                    'question_answered' => false,
                    'claims' => [[
                        'claim' => '999 is the official emergency number.',
                        'source_ids' => ['S1'],
                        'supported' => true,
                        'contradicted' => false,
                        'missing_qualifier' => false,
                        'reason' => null,
                    ]],
                    'missing_requested_facts' => ['Who must make the call'],
                ],
            ]);
        });

        $result = app(AiHelperResponsePipeline::class)->respond(
            'What is 999 and who calls it?',
            'Use the evidence.',
            [['role' => 'user', 'content' => 'What is 999 and who calls it?']],
            $this->guidance,
            $this->sources,
            null,
            'en',
            fn () => null,
            fn () => null,
        );

        $this->assertSame('AI_HELPER_EVIDENCE_INCOMPLETE', $result['outcome_code']);
        $this->assertSame('retrieve_more_evidence', $result['recovery_action']);
        $this->assertSame('fallback_extractive', $result['verification']['status']);
    }

    public function test_it_can_render_a_missing_single_source_citation_before_grounding_verification(): void
    {
        config(['ai_helper.grounding_verification_mode' => 'enforce']);
        $this->mock(AiHelperOpenAiService::class, function ($mock) {
            $mock->shouldReceive('streamResponse')->once()->andReturnUsing(function ($instructions, $input, $onDelta) {
                $onDelta('999 is the official Malaysian Emergency Service Centre telephone number.');

                return ['response_id' => 'answer-1'];
            });
            $mock->shouldReceive('structuredResponse')->once()->andReturn([
                'response_id' => 'verify-1',
                'data' => [
                    'verdict' => 'pass',
                    'question_answered' => true,
                    'claims' => [[
                        'claim' => '999 is the official Malaysian Emergency Service Centre telephone number.',
                        'source_ids' => ['S1'],
                        'supported' => true,
                        'contradicted' => false,
                        'missing_qualifier' => false,
                        'reason' => null,
                    ]],
                    'missing_requested_facts' => [],
                ],
            ]);
        });

        $result = app(AiHelperResponsePipeline::class)->respond(
            'What is 999?',
            'Use the evidence.',
            [['role' => 'user', 'content' => 'What is 999?']],
            $this->guidance,
            $this->sources,
            null,
            'en',
            fn () => null,
            fn () => null,
        );

        $this->assertSame('verified', $result['verification']['status']);
        $this->assertTrue($result['verification']['citation_rendered']);
        $this->assertStringEndsWith('[S1]', $result['content']);
    }
}
