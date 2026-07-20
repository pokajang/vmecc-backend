<?php

namespace Tests\Feature;

use App\Models\AiHelperKnowledgeEntry;
use App\Models\AiHelperMessage;
use App\Models\User;
use App\Services\AiHelperKnowledgeRetriever;
use App\Services\AiHelperOpenAiService;
use Database\Seeders\AiHelperSystemGuideSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class AiHelperRetrievalV4Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'ai_helper.system_guides_enabled' => true,
            'ai_helper.system_guide_final_corpus_enforced' => true,
            'ai_helper.embedding_enabled' => false,
            'ai_helper.retrieval_v3' => true,
            'ai_helper.retrieval_v4' => true,
            'ai_helper.rerank_enabled' => false,
        ]);
        $this->seed(AiHelperSystemGuideSeeder::class);
    }

    public function test_explicit_leave_question_uses_global_authorized_knowledge_from_inspection_page(): void
    {
        $user = $this->userWithPermissions(['self.leave', 'reports.inspection.view']);
        $leaveGuide = $this->systemGuide('leave-self-service');
        $inspectionGuide = $this->systemGuide('inspection-view');

        $result = app(AiHelperKnowledgeRetriever::class)->retrieve(
            ['path' => '/inspection'],
            $user,
            'How do I apply for leave?',
        );

        $this->assertSame(4, $result['trace']['pipeline_version']);
        $this->assertSame('explicit_topic', $result['trace']['query_plan']['context_dependency']);
        $this->assertContains($leaveGuide->id, $result['trace']['document_ids']);
        $this->assertSame($leaveGuide->title, $result['guidance'][0]['title']);
        $this->assertNotSame($inspectionGuide->title, $result['guidance'][0]['title']);
        $this->assertGreaterThanOrEqual(1, $result['trace']['candidate_lanes']['topic']);
    }

    public function test_bahasa_melayu_alias_retrieves_the_same_leave_guide(): void
    {
        $user = $this->userWithPermissions(['self.leave', 'reports.inspection.view']);
        $leaveGuide = $this->systemGuide('leave-self-service');

        $result = app(AiHelperKnowledgeRetriever::class)->retrieve(
            ['path' => '/inspection'],
            $user,
            'Macam mana nak apply cuti?',
        );

        $this->assertContains('leave', $result['analysis']['topic_keys']);
        $this->assertContains($leaveGuide->id, $result['trace']['document_ids']);
        $this->assertSame($leaveGuide->title, $result['guidance'][0]['title']);
    }

    public function test_generic_here_question_prefers_the_current_page_guide(): void
    {
        $user = $this->userWithPermissions(['self.leave', 'reports.inspection.view']);
        $inspectionGuide = $this->systemGuide('inspection-view');

        $result = app(AiHelperKnowledgeRetriever::class)->retrieve(
            ['path' => '/inspection'],
            $user,
            'What can I do here?',
        );

        $this->assertSame('page_deictic', $result['analysis']['context_dependency']);
        $this->assertSame($inspectionGuide->title, $result['guidance'][0]['title']);
        $this->assertGreaterThanOrEqual(1, $result['trace']['candidate_lanes']['page']);
    }

    public function test_colloquial_bm_extinguisher_inspection_retrieves_authorized_intersection_guides(): void
    {
        $user = $this->userWithPermissions([
            'reports.inspection.conduct',
            'reports.inspection.extinguishers.manage',
            'reports.inspection.issues.verify',
        ]);
        $fireInspectionGuide = $this->systemGuide('inspection-fire-extinguisher-conduct');
        $verificationGuide = $this->systemGuide('inspection-issue-verification');

        $result = app(AiHelperKnowledgeRetriever::class)->retrieve(
            ['path' => '/leave'],
            $user,
            'ada tak panduan nk buat pemeriksaan fire extinguisher?',
        );

        $this->assertSame('explicit_topic', $result['analysis']['context_dependency']);
        $this->assertContains($fireInspectionGuide->id, $result['trace']['document_ids']);
        $this->assertNotContains($verificationGuide->id, $result['trace']['document_ids']);
        $this->assertSame($fireInspectionGuide->title, $result['guidance'][0]['title']);
        $this->assertSame(['inspection.conduct'], $result['trace']['query_plan']['task_keys']);
        $this->assertGreaterThanOrEqual(1, $result['trace']['candidate_lanes']['topic_intersection']);
    }

    public function test_english_typo_and_compatible_follow_up_keep_the_fire_inspection_task(): void
    {
        $user = $this->userWithPermissions([
            'reports.inspection.conduct',
            'reports.inspection.issues.verify',
        ]);
        $fireInspectionGuide = $this->systemGuide('inspection-fire-extinguisher-conduct');
        $verificationGuide = $this->systemGuide('inspection-issue-verification');

        $result = app(AiHelperKnowledgeRetriever::class)->retrieve(
            ['path' => '/inspection'],
            $user,
            'but how do i do onsite inspection',
            ['How do I inspect a fire extiguisher?'],
        );

        $this->assertTrue($result['analysis']['follow_up']);
        $this->assertContains($fireInspectionGuide->id, $result['trace']['document_ids']);
        $this->assertNotContains($verificationGuide->id, $result['trace']['document_ids']);
        $this->assertSame($fireInspectionGuide->title, $result['guidance'][0]['title']);
    }

    public function test_compound_extinguisher_maintenance_question_separates_the_two_tasks(): void
    {
        $user = $this->userWithPermissions([
            'reports.inspection.conduct',
            'reports.inspection.extinguishers.manage',
        ]);
        $result = app(AiHelperKnowledgeRetriever::class)->retrieve(
            ['path' => '/inspection'],
            $user,
            'What are the steps for fire extinguisher inspection or maintenance?',
            [],
            true,
        );

        $this->assertSame('mixed', $result['analysis']['source_mode']);
        $this->assertNotEmpty($result['guidance']);
        $this->assertContains('maintain', $result['trace']['query_plan']['operation_keys']);
        $this->assertSame(
            ['inspection.conduct', 'inspection.physical.maintain'],
            $result['trace']['query_plan']['task_keys'],
        );
        $this->assertTrue($result['trace']['recovery_attempted']);
        $this->assertContains('pemadam', $result['trace']['recovery_expansion_terms']);
    }

    public function test_view_only_user_does_not_receive_extinguisher_management_steps(): void
    {
        $user = $this->userWithPermissions(['reports.inspection.view']);
        $extinguisherGuide = $this->systemGuide('extinguisher-management');

        $result = app(AiHelperKnowledgeRetriever::class)->retrieve(
            ['path' => '/inspection'],
            $user,
            'What are the steps for fire extinguisher inspection?',
        );

        $this->assertNotContains($extinguisherGuide->id, $result['trace']['document_ids']);
        $this->assertStringNotContainsString($extinguisherGuide->title, json_encode($result));
    }

    public function test_inspection_type_count_retrieves_the_authorized_catalogue_guide(): void
    {
        $user = $this->userWithPermissions(['reports.inspection.view']);
        $typeGuide = $this->systemGuide('inspection-types');

        $result = app(AiHelperKnowledgeRetriever::class)->retrieve(
            ['path' => '/leave'],
            $user,
            'How many types of inspections are there?',
        );

        $this->assertSame('capability_catalogue', $result['analysis']['intent']);
        $this->assertSame(['inspection.types.list'], $result['analysis']['task_keys']);
        $this->assertContains($typeGuide->id, $result['trace']['document_ids']);
        $this->assertSame('inspection-types', $result['guidance'][0]['guide_key']);
    }

    public function test_inspection_type_catalogue_stream_is_deterministic_cited_and_persisted(): void
    {
        config([
            'ai_helper.enabled' => true,
            'ai_helper.api_key' => 'test-key',
            'ai_helper.knowledge_strict_readiness' => false,
        ]);
        $this->actingAs($this->userWithPermissions(['reports.inspection.view']));
        $this->mock(AiHelperOpenAiService::class, function ($mock) {
            $mock->shouldReceive('isAvailable')->andReturnTrue();
            $mock->shouldNotReceive('streamResponse');
            $mock->shouldNotReceive('structuredResponse');
        });

        $content = $this->postJson('/api/ai-helper/messages/stream', [
            'message' => 'How many types of inspections are there?',
            'page_context' => ['path' => '/leave'],
            'response_language' => 'en',
            'new_thread' => true,
        ])->assertOk()->streamedContent();

        $this->assertStringContainsString('There are 8 built-in inspection types', $content);
        $this->assertStringContainsString('Fire Extinguisher', $content);
        $this->assertStringContainsString('General Inspection', $content);
        $this->assertStringContainsString('[S1]', $content);

        $message = AiHelperMessage::query()
            ->where('role', AiHelperMessage::ROLE_ASSISTANT)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('deterministic', $message->retrieval_metadata['verification']['status']);
        $this->assertSame(['S1'], collect($message->sources)->pluck('source_id')->all());
        $this->assertSame(
            ['Inspection Types Available in VMECC'],
            collect($message->sources)->pluck('title')->all(),
        );
    }

    public function test_asset_registration_does_not_return_conduct_issue_or_cross_module_guides(): void
    {
        $user = $this->userWithPermissions([
            'reports.inspection.conduct',
            'reports.inspection.extinguishers.manage',
            'reports.inspection.issues.verify',
            'self.leave',
        ]);
        $assetGuide = $this->systemGuide('extinguisher-management');
        $conductGuide = $this->systemGuide('inspection-fire-extinguisher-conduct');
        $verificationGuide = $this->systemGuide('inspection-issue-verification');
        $leaveGuide = $this->systemGuide('leave-self-service');

        $result = app(AiHelperKnowledgeRetriever::class)->retrieve(
            ['path' => '/leave'],
            $user,
            'How do I register a fire extinguisher asset?',
        );

        $this->assertSame(['inspection.asset.manage'], $result['analysis']['task_keys']);
        $this->assertSame($assetGuide->title, $result['guidance'][0]['title']);
        $this->assertContains($assetGuide->id, $result['trace']['document_ids']);
        $this->assertNotContains($conductGuide->id, $result['trace']['document_ids']);
        $this->assertNotContains($verificationGuide->id, $result['trace']['document_ids']);
        $this->assertNotContains($leaveGuide->id, $result['trace']['document_ids']);
    }

    public function test_defect_verification_does_not_return_issue_management_steps(): void
    {
        $user = $this->userWithPermissions([
            'reports.inspection.issues.manage',
            'reports.inspection.issues.verify',
        ]);
        $managementGuide = $this->systemGuide('inspection-issue-management');
        $verificationGuide = $this->systemGuide('inspection-issue-verification');

        $result = app(AiHelperKnowledgeRetriever::class)->retrieve(
            ['path' => '/inspection'],
            $user,
            'How do I verify a defect?',
        );

        $this->assertSame(['inspection.issue.verify'], $result['analysis']['task_keys']);
        $this->assertSame($verificationGuide->title, $result['guidance'][0]['title']);
        $this->assertContains($verificationGuide->id, $result['trace']['document_ids']);
        $this->assertNotContains($managementGuide->id, $result['trace']['document_ids']);
    }

    private function userWithPermissions(array $permissionNames): User
    {
        $user = User::factory()->create(['status' => 'active']);
        foreach ($permissionNames as $permissionName) {
            Permission::findOrCreate($permissionName, 'web');
        }
        $user->givePermissionTo($permissionNames);

        return $user;
    }

    private function systemGuide(string $key): AiHelperKnowledgeEntry
    {
        return AiHelperKnowledgeEntry::query()
            ->where('source_path', 'seed:system-guide:'.$key)
            ->firstOrFail();
    }
}
