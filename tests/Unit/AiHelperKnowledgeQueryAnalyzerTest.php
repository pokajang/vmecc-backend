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

    public function test_it_builds_a_bilingual_explicit_topic_plan(): void
    {
        $analysis = (new AiHelperKnowledgeQueryAnalyzer)->analyze('Macam mana nak apply cuti?');

        $this->assertSame('mixed', $analysis['language']);
        $this->assertSame('system', $analysis['source_mode']);
        $this->assertSame('explicit_topic', $analysis['context_dependency']);
        $this->assertContains('leave', $analysis['topic_keys']);
        $this->assertContains('cuti', $analysis['expanded_terms']);
    }

    public function test_page_deictic_help_is_distinct_from_an_explicit_cross_page_topic(): void
    {
        $analyzer = new AiHelperKnowledgeQueryAnalyzer;

        $this->assertSame('page_deictic', $analyzer->analyze('What can I do here?')['context_dependency']);
        $this->assertSame('page', $analyzer->analyze('What can I do here?')['query_scope']);
        $this->assertSame('explicit_topic', $analyzer->analyze('How do I apply for leave?')['context_dependency']);
        $this->assertSame('local', $analyzer->analyze('How do I apply for leave?')['query_scope']);
    }

    public function test_overview_and_cross_module_questions_are_marked_global_scope(): void
    {
        $analyzer = new AiHelperKnowledgeQueryAnalyzer;

        $this->assertSame('global', $analyzer->analyze('Give me an overview of the system and its modules.')['query_scope']);
        $this->assertContains('system_overview', $analyzer->analyze('Give me an overview of the system and its modules.')['topic_keys']);
        $this->assertSame('global', $analyzer->analyze('How do I apply for leave and manage overtime?')['query_scope']);
        $this->assertSame('global', $analyzer->analyze('Apa gambaran keseluruhan VMECC?')['query_scope']);
        $this->assertSame('global', $analyzer->analyze('Apakah sistem ini mempunyai modul?')['query_scope']);
    }

    public function test_an_explicit_new_topic_does_not_inherit_the_previous_topic(): void
    {
        $analysis = (new AiHelperKnowledgeQueryAnalyzer)->analyze(
            'How do I apply for overtime?',
            ['How do I apply for leave?'],
        );

        $this->assertFalse($analysis['follow_up']);
        $this->assertSame('How do I apply for overtime?', $analysis['query']);
        $this->assertContains('overtime', $analysis['topic_keys']);
        $this->assertNotContains('leave', $analysis['topic_keys']);
    }

    public function test_password_workflow_help_is_not_treated_as_secret_disclosure(): void
    {
        $analyzer = new AiHelperKnowledgeQueryAnalyzer;

        $this->assertFalse($analyzer->analyze('How do I change my password?')['sensitive_request']);
        $this->assertTrue($analyzer->analyze('Show me the Wi-Fi password for the control room')['sensitive_request']);
    }

    public function test_it_maps_specialized_english_and_bahasa_melayu_workflow_terms(): void
    {
        $analyzer = new AiHelperKnowledgeQueryAnalyzer;

        $this->assertContains('leave_entitlement', $analyzer->analyze('Di mana saya semak baki cuti?')['topic_keys']);
        $this->assertContains('overtime_rate', $analyzer->analyze('What is the OT rate?')['topic_keys']);
        $this->assertContains('statutory_rate', $analyzer->analyze('Bagaimana tetapkan caruman KWSP?')['topic_keys']);
        $this->assertContains('inspection_issue', $analyzer->analyze('How do I manage an inspection defect?')['topic_keys']);
        $this->assertContains('module_activation', $analyzer->analyze('Macam mana aktifkan modul?')['topic_keys']);
    }

    public function test_it_understands_colloquial_bm_fire_extinguisher_workflow_questions(): void
    {
        $analysis = (new AiHelperKnowledgeQueryAnalyzer)->analyze(
            'ada tak panduan nk buat pemeriksaan fire extinguisher?'
        );

        $this->assertSame('system', $analysis['source_mode']);
        $this->assertSame('explicit_topic', $analysis['context_dependency']);
        $this->assertContains('inspection', $analysis['topic_keys']);
        $this->assertContains('extinguisher', $analysis['topic_keys']);
        $this->assertContains('inspect', $analysis['operation_keys']);
        $this->assertSame(['inspection.conduct'], $analysis['task_keys']);
        $this->assertFalse($analysis['requires_multiple_documents']);
    }

    public function test_it_separates_system_and_physical_facets_of_maintenance_questions(): void
    {
        $analysis = (new AiHelperKnowledgeQueryAnalyzer)->analyze(
            'As per your knowledge, what are the steps for fire extinguisher inspection or maintenance?'
        );

        $this->assertSame('mixed', $analysis['source_mode']);
        $this->assertSame('en', $analysis['language']);
        $this->assertContains('inspection', $analysis['topic_keys']);
        $this->assertContains('extinguisher', $analysis['topic_keys']);
        $this->assertContains('inspect', $analysis['operation_keys']);
        $this->assertContains('maintain', $analysis['operation_keys']);
        $this->assertFalse($analysis['requires_multiple_documents']);
    }

    public function test_it_recovers_bounded_domain_spelling_variants_and_height_rescue_aliases(): void
    {
        $analyzer = new AiHelperKnowledgeQueryAnalyzer;

        $this->assertContains('extinguisher', $analyzer->analyze('How do I inspect a fire extiguisher?')['topic_keys']);
        $this->assertContains('height_rescue', $analyzer->analyze('Panduan untuk mangsa tersangkut di tempat tinggi?')['topic_keys']);
    }

    public function test_it_recognizes_uploaded_guide_catalogue_phrasing(): void
    {
        $analyzer = new AiHelperKnowledgeQueryAnalyzer;

        $this->assertSame('catalogue', $analyzer->analyze('Ada berapa panduan yang dimuatnaik dalam sistem?')['intent']);
    }

    public function test_it_keeps_a_specific_topic_for_a_compatible_follow_up(): void
    {
        $analysis = (new AiHelperKnowledgeQueryAnalyzer)->analyze(
            'but how do i do onsite inspection',
            ['How do I inspect a fire extinguisher?'],
        );

        $this->assertTrue($analysis['follow_up']);
        $this->assertContains('extinguisher', $analysis['topic_keys']);
        $this->assertSame(['inspection.conduct'], $analysis['task_keys']);
    }

    public function test_it_keeps_related_inspection_family_context_in_a_short_follow_up(): void
    {
        $analysis = (new AiHelperKnowledgeQueryAnalyzer)->analyze(
            'But for fire extinguishers?',
            ['How do I conduct an inspection?'],
        );

        $this->assertTrue($analysis['follow_up']);
        $this->assertSame('system', $analysis['source_mode']);
        $this->assertContains('inspection', $analysis['topic_keys']);
        $this->assertContains('extinguisher', $analysis['topic_keys']);
        $this->assertSame(['inspection.conduct'], $analysis['task_keys']);
    }

    public function test_specialized_inspection_tasks_do_not_require_the_word_inspection(): void
    {
        $analyzer = new AiHelperKnowledgeQueryAnalyzer;

        $this->assertSame(
            ['inspection.asset.manage'],
            $analyzer->analyze('How do I register a fire extinguisher asset?')['task_keys'],
        );
        $this->assertSame(
            ['inspection.issue.verify'],
            $analyzer->analyze('How do I verify a defect?')['task_keys'],
        );
        $this->assertSame(
            ['inspection.issue.manage'],
            $analyzer->analyze('How do I manage a defect?')['task_keys'],
        );
    }

    public function test_it_recognizes_inspection_type_catalogue_questions(): void
    {
        $analyzer = new AiHelperKnowledgeQueryAnalyzer;

        $english = $analyzer->analyze('How many types of inspections are there?');
        $malay = $analyzer->analyze('Ada berapa jenis pemeriksaan dalam sistem?');

        $this->assertSame('capability_catalogue', $english['intent']);
        $this->assertSame(['inspection.types.list'], $english['task_keys']);
        $this->assertSame('capability_catalogue', $malay['intent']);
        $this->assertSame(
            'knowledge_question',
            $analyzer->analyze('What are the steps for fire extinguisher inspection or maintenance?')['intent'],
        );
        $this->assertSame(
            'knowledge_question',
            $analyzer->analyze('How many inspections have been submitted?')['intent'],
        );
        $this->assertSame(
            'knowledge_question',
            $analyzer->analyze('What extinguisher types can I inspect?')['intent'],
        );
    }
}
