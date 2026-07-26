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
        $this->assertSame('casual', $analyzer->analyze('Hello, how are you?')['intent']);
        $this->assertSame('casual', $analyzer->analyze('Selamat pagi')['intent']);
        $this->assertSame('casual', $analyzer->analyze('Apa khabar?')['intent']);
        $this->assertSame('general_help', $analyzer->analyze('What can I do here?')['intent']);
        $this->assertSame('knowledge_question', $analyzer->analyze('What is the control room lunch menu?')['intent']);
        $this->assertSame('knowledge_question', $analyzer->analyze('Hello, how do I apply for leave?')['intent']);
    }

    public function test_it_assigns_answer_modes_and_canonical_product_entities(): void
    {
        $analyzer = new AiHelperKnowledgeQueryAnalyzer;

        $casual = $analyzer->analyze('hello');
        $overview = $analyzer->analyze('system ni boleh buat apa');
        $dashboard = $analyzer->analyze('What does this dashboard show?');
        $fireTruck = $analyzer->analyze('macam mana nak inspect fire rescue truck');
        $hse = $analyzer->analyze('cara buat HSE inspection');

        $this->assertSame('general_conversation', $casual['answer_mode']);
        $this->assertFalse($casual['evidence_required']);
        $this->assertSame('product_capability', $overview['answer_mode']);
        $this->assertContains('system_overview', $overview['topic_keys']);
        $this->assertSame('product_navigation', $dashboard['answer_mode']);
        $this->assertSame('product_workflow', $fireTruck['answer_mode']);
        $this->assertSame(['fire_truck'], $fireTruck['entity_keys']);
        $this->assertContains('hse_inspection', $hse['entity_keys']);
    }

    public function test_common_greeting_and_identity_variants_remain_casual_without_hiding_real_questions(): void
    {
        $analyzer = new AiHelperKnowledgeQueryAnalyzer;

        foreach (['Hi there', 'salam', 'assalamualaikum', 'Who are you?', 'Boleh bantu saya?'] as $message) {
            $analysis = $analyzer->analyze($message);
            $this->assertSame('general_conversation', $analysis['answer_mode'], $message);
            $this->assertFalse($analysis['evidence_required'], $message);
        }

        $workflow = $analyzer->analyze('Hi there, how do I apply for leave?');
        $this->assertSame('product_workflow', $workflow['answer_mode']);
        $this->assertSame(['leave.self_service'], $workflow['task_keys']);
    }

    public function test_daily_conversation_defaults_to_general_help_without_evidence_gating(): void
    {
        $analyzer = new AiHelperKnowledgeQueryAnalyzer;

        foreach ([
            'saya rasa kurang sihat hari ini',
            'jika saya kurang sihat perlu ke saya datang bekerja',
            'saya lapar hari ini, boleh bagi saya cadangan?',
            'saya gaduh dengan isteri hari ini',
            'macam mana nak pujuk isteri selepas gaduh?',
            'bila nak naik gaji ni?',
            'saya lapar, menu apa yang sedap?',
            'I feel stressed today',
            'Can you suggest something for lunch?',
        ] as $message) {
            $analysis = $analyzer->analyze($message);

            $this->assertSame('general_conversation', $analysis['answer_mode'], $message);
            $this->assertFalse($analysis['evidence_required'], $message);
        }
    }

    public function test_explicit_product_policy_and_operational_requests_remain_grounded(): void
    {
        $analyzer = new AiHelperKnowledgeQueryAnalyzer;

        foreach ([
            'Apa polisi kenaikan gaji syarikat?',
            'Macam mana nak mohon cuti dalam VMECC?',
            'Saya kurang sihat, macam mana nak mohon cuti?',
            'Macam mana nak inspect fire rescue truck?',
            'Menurut Annex 11, apakah prosedurnya?',
            'Di mana saya boleh lihat slip gaji?',
            'What is my leave balance?',
            'Where is payroll?',
            'What is the overtime rate?',
            'Has my salary claim been approved?',
            'Why is my payslip deduction high?',
            'What is the public holiday entitlement?',
        ] as $message) {
            $analysis = $analyzer->analyze($message);

            $this->assertNotSame('general_conversation', $analysis['answer_mode'], $message);
            $this->assertTrue($analysis['evidence_required'], $message);
        }

    }

    public function test_authoritative_document_follow_up_keeps_its_grounded_context(): void
    {
        $analysis = (new AiHelperKnowledgeQueryAnalyzer)->analyze(
            'What about the number?',
            ['According to Annex 11, what should happen for multiple casualties?'],
        );

        $this->assertTrue($analysis['follow_up']);
        $this->assertSame('operational_knowledge', $analysis['answer_mode']);
        $this->assertTrue($analysis['evidence_required']);
        $this->assertSame([11], $analysis['annex_numbers']);
    }

    public function test_generic_next_step_continues_general_conversation_unless_page_context_is_explicit(): void
    {
        $analyzer = new AiHelperKnowledgeQueryAnalyzer;
        $history = ['Saya kurang sihat hari ini'];

        $general = $analyzer->analyze('What should I do next?', $history);
        $pageHelp = $analyzer->analyze('What should I do next on this page?', $history);

        $this->assertTrue($general['follow_up']);
        $this->assertSame('general_conversation', $general['answer_mode']);
        $this->assertFalse($general['evidence_required']);
        $this->assertSame('product_navigation', $pageHelp['answer_mode']);
        $this->assertTrue($pageHelp['evidence_required']);
    }

    public function test_it_assigns_canonical_tasks_for_common_product_workflows(): void
    {
        $analyzer = new AiHelperKnowledgeQueryAnalyzer;

        $this->assertSame(['leave.self_service'], $analyzer->analyze('How do I apply for leave?')['task_keys']);
        $this->assertSame(['overtime.self_service'], $analyzer->analyze('Macam mana nak submit overtime?')['task_keys']);
        $this->assertSame(['payroll.payslip.view'], $analyzer->analyze('How do I download my payslip?')['task_keys']);
        $this->assertSame(['payroll.claim.submit'], $analyzer->analyze('Cara buat salary claim')['task_keys']);
        $this->assertSame(['roster.manage'], $analyzer->analyze('How do I publish a roster?')['task_keys']);
        $this->assertSame(['users.manage'], $analyzer->analyze('How do I create a user account?')['task_keys']);
        $this->assertSame('operational_knowledge', $analyzer->analyze('What is the leave policy?')['answer_mode']);
    }

    public function test_it_recognizes_bilingual_lifecycle_actions_without_losing_specific_report_tasks(): void
    {
        $analyzer = new AiHelperKnowledgeQueryAnalyzer;

        $ercoWrite = $analyzer->analyze('macam mana nak tulis report erco');
        $ercoEdit = $analyzer->analyze('how can I revise an ERCO report');
        $drillSubmit = $analyzer->analyze('how do I submit a drill report');
        $reportReview = $analyzer->analyze('how do I review and approve a submitted report');

        $this->assertContains('create', $ercoWrite['operation_keys']);
        $this->assertSame(['reports.erco.manage'], $ercoWrite['task_keys']);
        $this->assertContains('edit', $ercoEdit['operation_keys']);
        $this->assertSame(['reports.erco.manage'], $ercoEdit['task_keys']);
        $this->assertContains('submit', $drillSubmit['operation_keys']);
        $this->assertSame(['reports.drill.manage'], $drillSubmit['task_keys']);
        $this->assertContains('review', $reportReview['operation_keys']);
        $this->assertContains('approve', $reportReview['operation_keys']);
        $this->assertSame(['reports.review'], $reportReview['task_keys']);
    }

    public function test_it_recognizes_cancel_edit_and_contextual_configuration_actions(): void
    {
        $analyzer = new AiHelperKnowledgeQueryAnalyzer;

        $this->assertContains('cancel', $analyzer->analyze('How can I cancel my leave request?')['operation_keys']);
        $this->assertContains('edit', $analyzer->analyze('Cara tukar kata laluan saya')['operation_keys']);
        $this->assertContains('configure', $analyzer->analyze('Cara ubah dan simpan kebenaran akses peranan')['operation_keys']);
        $this->assertSame(
            ['settings.module_activation'],
            $analyzer->analyze('How do I enable a module?')['task_keys'],
        );
        $this->assertSame(
            ['payroll.payment.manage'],
            $analyzer->analyze('How do I mark an approved salary claim as paid?')['task_keys'],
        );
        $this->assertSame(
            ['inspection.workflow.configure'],
            $analyzer->analyze('How do I configure inspection workflow settings?')['task_keys'],
        );
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

    public function test_emergency_response_service_scope_questions_require_grounded_knowledge(): void
    {
        $analyzer = new AiHelperKnowledgeQueryAnalyzer;

        foreach ([
            'How can the VMM site be accessed and what area does the emergency response service cover?',
            'What is the response perimeter and TRT staffing requirement?',
            'Apakah akses tapak dan liputan perkhidmatan tindak balas kecemasan?',
        ] as $message) {
            $analysis = $analyzer->analyze($message);

            $this->assertContains('emergency_response_service', $analysis['topic_keys'], $message);
            $this->assertSame('operational_knowledge', $analysis['answer_mode'], $message);
            $this->assertTrue($analysis['evidence_required'], $message);
        }

        $accessAnalysis = $analyzer->analyze(
            'How can the VMM site be accessed and what area does the emergency response service cover?',
        );
        $this->assertContains('access', $accessAnalysis['terms']);
        $this->assertContains('coverage', $accessAnalysis['terms']);

        $staffingAnalysis = $analyzer->analyze('What is the response perimeter and TRT staffing requirement?');
        $this->assertContains('tactical', $staffingAnalysis['terms']);
        $this->assertContains('manpower', $staffingAnalysis['terms']);
    }

    public function test_follow_up_analysis_tracks_confidence_and_scope_hint(): void
    {
        $analysis = (new AiHelperKnowledgeQueryAnalyzer)->analyze(
            'What about that?',
            ['How do I apply for leave?'],
        );

        $this->assertTrue($analysis['follow_up']);
        $this->assertSame('medium', $analysis['follow_up_confidence']);
        $this->assertSame('none', $analysis['scope_adjustment_hint']);
    }

    public function test_cross_module_followup_does_not_inherit_context_but_flags_scope_hint(): void
    {
        $analysis = (new AiHelperKnowledgeQueryAnalyzer)->analyze(
            'How do I process salary claims?',
            ['How do I apply for leave?'],
        );

        $this->assertFalse($analysis['follow_up']);
        $this->assertSame('cross_module_candidate', $analysis['scope_adjustment_hint']);
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

    public function test_it_requires_structured_clarification_for_ambiguous_or_unsafe_actions(): void
    {
        $analyzer = new AiHelperKnowledgeQueryAnalyzer;

        $missingType = $analyzer->analyze('How do I submit a report?');
        $this->assertTrue($missingType['clarification_required']);
        $this->assertSame('missing_report_type', $missingType['clarification_reason']);
        $this->assertSame(['erco', 'drill', 'fitness', 'inspection'], $missingType['clarification_option_keys']);
        $this->assertSame([], $missingType['task_keys']);

        $ambiguousAction = $analyzer->analyze('How do I check a report?');
        $this->assertSame('ambiguous_action', $ambiguousAction['clarification_reason']);
        $this->assertSame(['view', 'review'], $ambiguousAction['clarification_option_keys']);

        $missingRecord = $analyzer->analyze('How do I approve this?');
        $this->assertSame('missing_record_context', $missingRecord['clarification_reason']);

        $unsupported = $analyzer->analyze('How do I delete an approved ERCO report?');
        $this->assertSame('unsupported_action', $unsupported['clarification_reason']);
        $this->assertSame([], $unsupported['task_keys']);
    }
}
