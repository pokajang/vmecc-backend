<?php

namespace Tests\Unit;

use App\Services\AiHelperGroundingVerifier;
use App\Services\AiHelperOpenAiService;
use RuntimeException;
use Tests\TestCase;

class AiHelperGroundingVerifierTest extends TestCase
{
    private array $guidance = [[
        'source_id' => 'S1',
        'title' => 'Annex 1',
        'content' => '999 is the official emergency service number.',
    ]];

    public function test_enforce_mode_accepts_only_a_passing_supported_verdict(): void
    {
        config(['ai_helper.grounding_verification_mode' => 'enforce']);
        $this->mock(AiHelperOpenAiService::class, function ($mock) {
            $mock->shouldReceive('structuredResponse')->once()->andReturn([
                'response_id' => 'verify-1',
                'data' => [
                    'verdict' => 'pass',
                    'question_answered' => true,
                    'claims' => [[
                        'claim' => '999 is the official emergency number.',
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

        $result = app(AiHelperGroundingVerifier::class)->verify(
            'What is 999?',
            '999 is the official emergency number. [S1]',
            $this->guidance,
        );

        $this->assertTrue($result['valid']);
        $this->assertSame('verified', $result['status']);
    }

    public function test_enforce_mode_fails_closed_when_verification_is_unavailable(): void
    {
        config(['ai_helper.grounding_verification_mode' => 'enforce']);
        $this->mock(AiHelperOpenAiService::class, function ($mock) {
            $mock->shouldReceive('structuredResponse')->once()->andThrow(new RuntimeException('unavailable'));
        });

        $result = app(AiHelperGroundingVerifier::class)->verify('What is 999?', 'Answer [S1]', $this->guidance);

        $this->assertFalse($result['valid']);
        $this->assertSame('verification_unavailable', $result['status']);
    }

    public function test_an_invalid_mode_fails_closed_without_calling_the_provider(): void
    {
        config(['ai_helper.grounding_verification_mode' => 'typo']);
        $this->mock(AiHelperOpenAiService::class, function ($mock) {
            $mock->shouldNotReceive('structuredResponse');
        });

        $result = app(AiHelperGroundingVerifier::class)->verify('What is 999?', 'Answer [S1]', $this->guidance);

        $this->assertFalse($result['valid']);
        $this->assertSame('invalid_configuration', $result['status']);
    }

    public function test_shadow_mode_records_a_failure_without_blocking_the_answer(): void
    {
        config(['ai_helper.grounding_verification_mode' => 'shadow']);
        $this->mock(AiHelperOpenAiService::class, function ($mock) {
            $mock->shouldReceive('structuredResponse')->once()->andReturn([
                'response_id' => 'verify-shadow',
                'data' => [
                    'verdict' => 'revise',
                    'question_answered' => true,
                    'claims' => [[
                        'claim' => 'Unsupported claim',
                        'source_ids' => ['S1'],
                        'supported' => false,
                        'contradicted' => false,
                        'missing_qualifier' => false,
                        'reason' => 'Not present in evidence',
                    ]],
                    'missing_requested_facts' => [],
                ],
            ]);
        });

        $result = app(AiHelperGroundingVerifier::class)->verify('Question?', 'Unsupported claim [S1]', $this->guidance);

        $this->assertTrue($result['valid']);
        $this->assertFalse($result['would_pass']);
        $this->assertSame('shadow_failed', $result['status']);
    }

    public function test_it_rejects_empty_claims_and_unknown_source_ids_from_the_verifier(): void
    {
        config(['ai_helper.grounding_verification_mode' => 'enforce']);
        $responses = [
            ['verdict' => 'pass', 'question_answered' => true, 'claims' => [], 'missing_requested_facts' => []],
            [
                'verdict' => 'pass',
                'question_answered' => true,
                'claims' => [[
                    'claim' => 'Claim',
                    'source_ids' => ['S99'],
                    'supported' => true,
                    'contradicted' => false,
                    'missing_qualifier' => false,
                    'reason' => null,
                ]],
                'missing_requested_facts' => [],
            ],
        ];
        $this->mock(AiHelperOpenAiService::class, function ($mock) use (&$responses) {
            $mock->shouldReceive('structuredResponse')->twice()->andReturnUsing(
                function () use (&$responses) {
                    return ['response_id' => 'verify-invalid', 'data' => array_shift($responses)];
                },
            );
        });

        $empty = app(AiHelperGroundingVerifier::class)->verify('Question?', 'Operational answer [S1]', $this->guidance);
        $unknown = app(AiHelperGroundingVerifier::class)->verify('Question?', 'Operational answer [S1]', $this->guidance);

        $this->assertFalse($empty['valid']);
        $this->assertSame('no_claims_returned', $empty['failures'][0]['reason']);
        $this->assertFalse($unknown['valid']);
        $this->assertSame('claim_has_unknown_source', $unknown['failures'][0]['reason']);
    }

    public function test_it_uses_actionable_claim_failures_instead_of_an_inconsistent_top_level_verdict(): void
    {
        config(['ai_helper.grounding_verification_mode' => 'enforce']);
        $this->mock(AiHelperOpenAiService::class, function ($mock) {
            $mock->shouldReceive('structuredResponse')->once()->andReturn([
                'response_id' => 'verify-inconsistent-verdict',
                'data' => [
                    'verdict' => 'revise',
                    'question_answered' => true,
                    'claims' => [[
                        'claim' => '999 is the official emergency number.',
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

        $result = app(AiHelperGroundingVerifier::class)->verify(
            'What is 999?',
            '999 is the official emergency number. [S1]',
            $this->guidance,
        );

        $this->assertTrue($result['valid']);
        $this->assertSame('verified', $result['status']);
        $this->assertSame('revise', $result['verdict']);
    }

    public function test_it_rejects_a_missing_requested_fact_even_when_the_verdict_says_pass(): void
    {
        config(['ai_helper.grounding_verification_mode' => 'enforce']);
        $this->mock(AiHelperOpenAiService::class, function ($mock) {
            $mock->shouldReceive('structuredResponse')->once()->andReturn([
                'response_id' => 'verify-missing-fact',
                'data' => [
                    'verdict' => 'pass',
                    'question_answered' => true,
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

        $result = app(AiHelperGroundingVerifier::class)->verify(
            'What is 999 and who calls it?',
            '999 is the official emergency number. [S1]',
            $this->guidance,
        );

        $this->assertFalse($result['valid']);
        $this->assertSame('missing_requested_fact', $result['failures'][0]['reason']);
    }
}
