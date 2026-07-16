<?php

namespace Tests\Unit;

use App\Services\AiHelperOpenAiService;
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

    public function test_it_returns_a_safe_refusal_after_the_repair_also_fails(): void
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

        $this->assertSame('rejected', $result['verification']['status']);
        $this->assertSame([], $result['sources']);
        $this->assertStringContainsString('sufficiently sourced', $result['content']);
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
}
