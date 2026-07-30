<?php

namespace Tests\Unit;

use App\Models\AiHelperKnowledgeChunk;
use App\Services\AiHelperEvidenceAdequacyValidator;
use Tests\TestCase;

class AiHelperEvidenceAdequacyValidatorTest extends TestCase
{
    public function test_exact_member_evidence_outranks_and_filters_a_related_commander_passage(): void
    {
        $validator = app(AiHelperEvidenceAdequacyValidator::class);
        $candidates = collect([
            $this->candidate(
                1,
                ['SITE TACTICAL RESPONSE TEAM', 'ON SCENE COMMANDER (OSC)'],
                'Organizing and managing the on-scene tactical response and resources.',
                500,
            ),
            $this->candidate(
                2,
                ['QUALIFICATION AND RESPONSIBILITIES'],
                'TACTICAL RESPONSE TEAM MEMBER. Carry out tasks, conduct inspections and perform emergency response under the On Scene Commander.',
                100,
            ),
            $this->candidate(
                3,
                ['SITE TACTICAL RESPONSE TEAM', 'EMERGENCY RESPONSE TEAM MEMBER (ERTM)'],
                'Personnel to assist OSC on firefighting and follow clear instructions from OSC.',
                90,
            ),
        ]);

        $result = $validator->assessCandidates($candidates, [
            'resolved_entities' => ['tactical_response_team_member'],
            'requested_facets' => ['role_responsibilities'],
        ]);

        $this->assertSame('adequate', $result['status']);
        $this->assertSame([2, 3], $result['candidates']->pluck('chunk.id')->all());
        $this->assertSame(2, $result['candidates']->first()['chunk']->id);
    }

    public function test_missing_requested_entity_requires_recovery(): void
    {
        $result = app(AiHelperEvidenceAdequacyValidator::class)->assessCandidates(
            collect([$this->candidate(1, ['ON SCENE COMMANDER'], 'Command the response.', 100)]),
            [
                'resolved_entities' => ['tactical_response_team_member'],
                'requested_facets' => ['role_responsibilities'],
            ],
        );

        $this->assertSame('recover', $result['status']);
        $this->assertSame('requested_entity_missing', $result['reason']);
        $this->assertTrue($result['candidates']->isEmpty());
    }

    public function test_staffing_table_outranks_broad_scope_for_quantity_and_schedule_question(): void
    {
        $result = app(AiHelperEvidenceAdequacyValidator::class)->assessCandidates(
            collect([
                $this->candidate(
                    1,
                    ['SERVICES SCOPE'],
                    'The tactical response team provides emergency response coverage for the facility.',
                    100,
                ),
                $this->candidate(
                    2,
                    ['OPERATIONAL REQUIREMENTS', 'STAFFING'],
                    'Tactical response team: 2 groups, 8 members per shift, total 16 members, operating 24/7.',
                    100,
                ),
            ]),
            [
                'resolved_entities' => ['tactical_response_team'],
                'requested_facets' => ['requirements', 'numbers_capacity', 'scope_coverage', 'timing_frequency'],
            ],
        );

        $this->assertSame('adequate', $result['status']);
        $this->assertSame(2, $result['candidates']->first()['chunk']->id);
        $this->assertGreaterThan(
            $result['candidates']->last()['facet_adequacy_score'],
            $result['candidates']->first()['facet_adequacy_score'],
        );
    }

    private function candidate(int $id, array $headings, string $content, float $score): array
    {
        $chunk = new AiHelperKnowledgeChunk([
            'content' => $content,
            'heading_path' => $headings,
        ]);
        $chunk->id = $id;

        return ['chunk' => $chunk, 'score' => $score];
    }
}
