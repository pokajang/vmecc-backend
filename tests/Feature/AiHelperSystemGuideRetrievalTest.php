<?php

namespace Tests\Feature;

use App\Models\AiHelperKnowledgeEntry;
use App\Models\Setting;
use App\Models\User;
use App\Services\AiHelperKnowledgeRetriever;
use App\Services\AiHelperKnowledgeService;
use App\Services\ModuleActivationService;
use Database\Seeders\AiHelperSystemGuideSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class AiHelperSystemGuideRetrievalTest extends TestCase
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
        ]);
        $this->seed(AiHelperSystemGuideSeeder::class);
    }

    public function test_self_service_user_gets_only_the_authorized_guide_even_with_forged_context(): void
    {
        $user = $this->userWithPermissions(['self.leave']);
        $selfGuide = $this->systemGuide('leave-self-service');
        $managementGuide = $this->systemGuide('leave-management');

        $result = app(AiHelperKnowledgeRetriever::class)->retrieve(
            ['path' => '/staff/leave-management', 'route_key' => 'leave-management', 'module_key' => 'leave'],
            $user,
            'How do I apply for leave?',
        );

        $this->assertContains($selfGuide->id, $result['trace']['document_ids']);
        $this->assertNotContains($managementGuide->id, $result['trace']['document_ids']);
        $this->assertContains($selfGuide->title, collect($result['guidance'])->pluck('title')->unique()->values()->all());
        $this->assertStringNotContainsString($managementGuide->title, json_encode($result));
    }

    public function test_management_permission_can_retrieve_management_guide(): void
    {
        $user = $this->userWithPermissions(['staff.leave.manage']);
        $managementGuide = $this->systemGuide('leave-management');

        $result = app(AiHelperKnowledgeRetriever::class)->retrieve(
            ['path' => '/staff/leave-management'],
            $user,
            'How do I manage a leave record?',
        );

        $this->assertContains($managementGuide->id, $result['trace']['document_ids']);
        $this->assertSame('system_guide', $result['guidance'][0]['source_type']);
    }

    public function test_disabled_module_rejects_an_otherwise_authorized_guide(): void
    {
        $user = $this->userWithPermissions(['self.leave']);
        $leaveGuide = $this->systemGuide('leave-self-service');
        Setting::query()->updateOrCreate(
            ['key' => ModuleActivationService::SETTING_KEY],
            ['value' => ['configured' => ['leave.self_service' => false]]],
        );

        $result = app(AiHelperKnowledgeRetriever::class)->retrieve(
            ['path' => '/leave'],
            $user,
            'How do I apply for leave?',
        );

        $this->assertSame([], $result['guidance']);
        $this->assertNotContains($leaveGuide->id, $result['trace']['document_ids']);
        $this->assertStringNotContainsString($leaveGuide->title, json_encode($result));
    }

    public function test_system_guide_citation_has_no_pdf_document_id(): void
    {
        $guide = $this->systemGuide('leave-self-service');
        $citation = app(AiHelperKnowledgeService::class)->citationsForGuidance([[
            'source_id' => 'S1',
            'source_type' => AiHelperKnowledgeEntry::KNOWLEDGE_SYSTEM_GUIDE,
            'id' => $guide->id,
            'title' => $guide->title,
            'guide_version' => 3,
            'module_key' => 'leave',
            'route_key' => 'leave',
        ]])[0];

        $this->assertNull($citation['document_id']);
        $this->assertSame('VMECC System Guide', $citation['display_label']);
        $this->assertArrayNotHasKey('source_path', $citation);
    }

    public function test_salary_management_permission_does_not_grant_payment_actions(): void
    {
        $user = $this->userWithPermissions(['staff.salary.manage']);
        $payment = $this->systemGuide('payment-actions');

        $result = app(AiHelperKnowledgeRetriever::class)->retrieve(
            ['path' => '/staff/salary-claims'],
            $user,
            'How do I mark a salary claim paid?',
        );

        $this->assertNotContains($payment->id, $result['trace']['document_ids']);
        $this->assertStringNotContainsString($payment->title, json_encode($result));
    }

    public function test_payment_permission_can_retrieve_payment_actions(): void
    {
        $user = $this->userWithPermissions(['staff.salary.pay']);
        $payment = $this->systemGuide('payment-actions');

        $result = app(AiHelperKnowledgeRetriever::class)->retrieve(
            ['path' => '/staff/salary-claims'],
            $user,
            'How do I mark a salary claim paid?',
        );

        $this->assertContains($payment->id, $result['trace']['document_ids']);
    }

    public function test_ordinary_user_cannot_retrieve_role_permission_settings(): void
    {
        $user = $this->userWithPermissions(['self.dashboard']);
        $settings = $this->systemGuide('role-permissions');

        $result = app(AiHelperKnowledgeRetriever::class)->retrieve(
            ['path' => '/settings/role-permissions'],
            $user,
            'How do I change role permissions?',
        );

        $this->assertNotContains($settings->id, $result['trace']['document_ids']);
        $this->assertStringNotContainsString('Role Permissions', json_encode($result));
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
