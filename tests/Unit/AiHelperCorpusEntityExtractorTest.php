<?php

namespace Tests\Unit;

use App\Models\AiHelperKnowledgeChunk;
use App\Services\AiHelperCorpusEntityExtractor;
use Tests\TestCase;

class AiHelperCorpusEntityExtractorTest extends TestCase
{
    public function test_it_extracts_acronym_pairs_roles_and_document_codes_deterministically(): void
    {
        $chunk = new AiHelperKnowledgeChunk([
            'heading_path' => [
                'SITE TACTICAL RESPONSE TEAM',
                'EMERGENCY RESPONSE TEAM MEMBER (ERTM)',
            ],
            'content' => <<<'TEXT'
| TACTICAL RESPONSE TEAM MEMBER | Familiarize with the shift roster and conduct inspections. |
Use VMECC-OPR-SOP-003 during the response.
TEXT,
        ]);

        $entities = collect(app(AiHelperCorpusEntityExtractor::class)->extract($chunk));

        $ertm = $entities->firstWhere('normalized_name', 'emergency response team member');
        $this->assertNotNull($ertm);
        $this->assertSame('role', $ertm['entity_type']);
        $this->assertContains('ERTM', array_column($ertm['aliases'], 'alias'));
        $trtMember = $entities->firstWhere('normalized_name', 'tactical response team member');
        $this->assertNotNull($trtMember);
        $this->assertContains('TRT member', array_column($trtMember['aliases'], 'alias'));
        $this->assertNotNull($entities->firstWhere('normalized_name', 'vmecc opr sop 003'));
    }

    public function test_it_does_not_promote_action_phrases_to_canonical_entities(): void
    {
        $chunk = new AiHelperKnowledgeChunk([
            'content' => 'Mobilize Tactical Response Team (TRT) immediately.',
        ]);

        $entities = collect(app(AiHelperCorpusEntityExtractor::class)->extract($chunk));

        $this->assertNull($entities->firstWhere('normalized_name', 'mobilize tactical response team'));
    }

    public function test_it_does_not_treat_instruction_text_after_a_hyphen_as_an_acronym_expansion(): void
    {
        $chunk = new AiHelperKnowledgeChunk([
            'content' => '| ADMINISTER AID- AS PER DEEM NECESSARY UNLESS YOU ARE A CERTIFIED MEDICAL PRACTIONER |',
        ]);

        $entities = collect(app(AiHelperCorpusEntityExtractor::class)->extract($chunk));

        $this->assertNull($entities->firstWhere(
            'normalized_name',
            'as per deem necessary unless you are a certified medical practioner',
        ));
    }
}
