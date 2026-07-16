<?php

namespace Tests\Unit;

use App\Services\AiHelperKnowledgeQueryAnalyzer;
use PHPUnit\Framework\TestCase;

class AiHelperKnowledgeQueryAnalyzerTest extends TestCase
{
    public function test_it_detects_catalogue_intent_in_english_and_bahasa_melayu(): void
    {
        $analyzer = new AiHelperKnowledgeQueryAnalyzer;

        $this->assertSame('catalogue', $analyzer->analyze('Show all available annexes')['intent']);
        $this->assertSame('catalogue', $analyzer->analyze('Senarai semua lampiran')['intent']);
    }

    public function test_it_extracts_annex_revision_and_document_codes(): void
    {
        $analysis = (new AiHelperKnowledgeQueryAnalyzer)->analyze(
            'Explain Annex 18 Rev. 001 and VMECC-OPR-SOP-003'
        );

        $this->assertSame([18], $analysis['annex_numbers']);
        $this->assertSame(['1'], $analysis['revisions']);
        $this->assertContains('VMECC-OPR-SOP-003', $analysis['document_codes']);
    }

    public function test_follow_up_queries_include_recent_user_context(): void
    {
        $analysis = (new AiHelperKnowledgeQueryAnalyzer)->analyze(
            'What about the next step?',
            ['Explain the Annex 13 fire response.'],
        );

        $this->assertTrue($analysis['follow_up']);
        $this->assertStringContainsString('Annex 13', $analysis['query']);
    }

    public function test_it_marks_credential_requests_as_sensitive_and_removes_question_stopwords(): void
    {
        $analysis = (new AiHelperKnowledgeQueryAnalyzer)->analyze(
            'What is the Wi-Fi password for the VMECC control room?'
        );

        $this->assertTrue($analysis['sensitive_request']);
        $this->assertNotContains('is', $analysis['terms']);
        $this->assertNotContains('the', $analysis['terms']);
        $this->assertContains('password', $analysis['terms']);
    }

    public function test_it_recognizes_the_bahasa_melayu_annex_term(): void
    {
        $analysis = (new AiHelperKnowledgeQueryAnalyzer)->analyze('Apakah maksud 999 menurut Lampiran 1?');

        $this->assertSame([1], $analysis['annex_numbers']);
    }

    public function test_it_does_not_confuse_bahasa_or_specific_annex_questions_with_catalogue_intent(): void
    {
        $analyzer = new AiHelperKnowledgeQueryAnalyzer;

        $this->assertSame('knowledge_question', $analyzer->analyze('Untuk Annex 11, berapa maximum capacity?')['intent']);
        $this->assertSame('knowledge_question', $analyzer->analyze('Compare both available Annex 18 sources.')['intent']);
        $this->assertSame('catalogue', $analyzer->analyze('Berapa dokumen pengetahuan tersedia?')['intent']);
    }

    public function test_it_decomposes_a_multi_part_question_for_coverage_tracking(): void
    {
        $analysis = (new AiHelperKnowledgeQueryAnalyzer)->analyze(
            'Who calls mutual aid, and what is the capacity, and what number is used?',
        );

        $this->assertGreaterThanOrEqual(2, count($analysis['subqueries']));
        $this->assertStringContainsString('Who calls mutual aid', $analysis['subqueries'][0]);
    }

    public function test_it_keeps_greetings_and_generic_page_help_out_of_the_knowledge_not_found_gate(): void
    {
        $analyzer = new AiHelperKnowledgeQueryAnalyzer;

        $this->assertSame('casual', $analyzer->analyze('Hello')['intent']);
        $this->assertSame('general_help', $analyzer->analyze('What can I do here?')['intent']);
        $this->assertSame('knowledge_question', $analyzer->analyze('What is the control room lunch menu?')['intent']);
    }
}
