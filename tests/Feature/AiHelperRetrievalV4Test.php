<?php

namespace Tests\Feature;

use App\Models\AiHelperKnowledgeEntry;
use App\Models\User;
use App\Services\AiHelperKnowledgeRetriever;
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
        ]);
        $inspectionGuide = $this->systemGuide('inspection-manage');
        $extinguisherGuide = $this->systemGuide('extinguisher-management');

        $result = app(AiHelperKnowledgeRetriever::class)->retrieve(
            ['path' => '/leave'],
            $user,
            'ada tak panduan nk buat pemeriksaan fire extinguisher?',
        );

        $this->assertSame('explicit_topic', $result['analysis']['context_dependency']);
        $this->assertContains($inspectionGuide->id, $result['trace']['document_ids']);
        $this->assertContains($extinguisherGuide->id, $result['trace']['document_ids']);
        $this->assertGreaterThanOrEqual(1, $result['trace']['candidate_lanes']['topic_intersection']);
    }

    public function test_compound_extinguisher_maintenance_question_searches_system_and_reference_lanes(): void
    {
        $user = $this->userWithPermissions([
            'reports.inspection.conduct',
            'reports.inspection.extinguishers.manage',
        ]);
        $inspectionGuide = $this->systemGuide('inspection-manage');
        $extinguisherGuide = $this->systemGuide('extinguisher-management');

        $result = app(AiHelperKnowledgeRetriever::class)->retrieve(
            ['path' => '/inspection'],
            $user,
            'What are the steps for fire extinguisher inspection or maintenance?',
            [],
            true,
        );

        $this->assertSame('mixed', $result['analysis']['source_mode']);
        $this->assertContains($inspectionGuide->id, $result['trace']['document_ids']);
        $this->assertContains($extinguisherGuide->id, $result['trace']['document_ids']);
        $this->assertContains('maintain', $result['trace']['query_plan']['operation_keys']);
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
