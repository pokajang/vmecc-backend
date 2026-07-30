<?php

namespace Tests\Unit;

use App\Services\AiHelperCriticalFactValidator;
use Tests\TestCase;

class AiHelperCriticalFactValidatorTest extends TestCase
{
    private array $guidance = [[
        'source_id' => 'S1',
        'title' => 'ANNEX 11 Epidemic ERP',
        'content' => 'The team can handle a maximum of 2 casualties. Call 999 for ambulance support.',
    ]];

    public function test_it_accepts_exact_critical_values_from_cited_evidence(): void
    {
        $result = app(AiHelperCriticalFactValidator::class)->validate(
            'The maximum is 2 casualties and the ambulance number is 999. [S1]',
            $this->guidance,
        );

        $this->assertTrue($result['valid']);
        $this->assertSame('validated', $result['status']);
    }

    public function test_it_rejects_a_number_not_present_in_the_cited_evidence(): void
    {
        $result = app(AiHelperCriticalFactValidator::class)->validate(
            'The team can handle 3 casualties. [S1]',
            $this->guidance,
        );

        $this->assertFalse($result['valid']);
        $this->assertSame('3 casualties', $result['failures'][0]['token']);
    }

    public function test_it_validates_critical_values_in_a_grouped_list_against_the_intro_citation(): void
    {
        $result = app(AiHelperCriticalFactValidator::class)->validate(
            "Annex 11 states: [S1]\n\n- Maximum of 2 casualties.\n- Call 999 for ambulance support.",
            $this->guidance,
        );

        $this->assertTrue($result['valid']);
    }

    public function test_it_requires_an_explicit_label_for_a_source_without_a_revision_marker(): void
    {
        $guidance = [
            ['source_id' => 'S1', 'title' => 'ANNEX 18 ERP for Man Overboard (MOB)', 'content' => 'Content'],
            ['source_id' => 'S2', 'title' => 'ANNEX 18 ERP for Man Overboard (MOB).Rev 001 - Feb 2026', 'content' => 'Content'],
        ];

        $missing = app(AiHelperCriticalFactValidator::class)->validate(
            'Both Annex 18 sources are available. [S1][S2]',
            $guidance,
            'Does the knowledge establish which source is authoritative?',
        );
        $labelled = app(AiHelperCriticalFactValidator::class)->validate(
            'The first source has revision not stated. [S1] The second is Rev 001. [S2]',
            $guidance,
            'Does the knowledge establish which source is authoritative?',
        );

        $this->assertFalse($missing['valid']);
        $this->assertSame('missing_revision_status_label', $missing['failures'][0]['type']);
        $this->assertTrue($labelled['valid']);
    }

    public function test_it_matches_bahasa_melayu_operational_units_to_english_evidence(): void
    {
        $guidance = [[
            'source_id' => 'S1',
            'title' => 'Response limits',
            'content' => 'The team can handle 2 people for 30 minutes over 5 kilometers, representing 50 percent.',
        ]];

        $result = app(AiHelperCriticalFactValidator::class)->validate(
            'Hadnya ialah 2 orang selama 30 minit dalam jarak 5 kilometer, iaitu 50 peratus. [S1]',
            $guidance,
        );

        $this->assertTrue($result['valid'], json_encode($result['failures']));
    }

    public function test_it_rejects_an_acronym_expansion_not_present_in_evidence(): void
    {
        $guidance = [[
            'source_id' => 'S1',
            'title' => 'Emergency roles',
            'content' => 'A Tactical Response Team Member performs emergency response under OSC command.',
        ]];

        $unsupported = app(AiHelperCriticalFactValidator::class)->validate(
            'A TRT member belongs to the Training Review Team. [S1]',
            $guidance,
            'What is the role of a trt member?',
        );
        $supported = app(AiHelperCriticalFactValidator::class)->validate(
            'TRT refers to the Tactical Response Team. [S1]',
            $guidance,
            'What is TRT?',
        );

        $this->assertFalse($unsupported['valid']);
        $this->assertSame('unsupported_acronym_expansion', $unsupported['failures'][0]['type']);
        $this->assertTrue($supported['valid']);
    }
}
