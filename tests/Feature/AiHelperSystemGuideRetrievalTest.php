<?php

namespace Tests\Feature;

use App\Models\AiHelperKnowledgeChunk;
use App\Models\AiHelperKnowledgeEntry;
use App\Models\Setting;
use App\Models\User;
use App\Services\AiHelperKnowledgeRetriever;
use App\Services\AiHelperKnowledgeService;
use App\Services\ModuleActivationService;
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
            'ai_helper.system_guide_approval_enforced' => false,
            'ai_helper.embedding_enabled' => false,
            'ai_helper.retrieval_v3' => true,
        ]);
    }

    public function test_self_service_user_gets_only_the_authorized_guide_even_with_forged_context(): void
    {
        $user = $this->userWithPermissions(['self.leave']);
        $selfGuide = $this->guide(
            'Applying for Leave',
            'leave-self-service',
            ['self.leave'],
            'leave.self_service',
            'Apply for leave by opening Leave and submitting the required dates.',
        );
        $managementGuide = $this->guide(
            'Managing Leave Records',
            'leave-management',
            ['staff.leave.manage'],
            'leave.management',
            'Approve another user leave record.',
        );

        $result = app(AiHelperKnowledgeRetriever::class)->retrieve(
            ['path' => '/staff/leave-management', 'route_key' => 'leave-management', 'module_key' => 'leave'],
            $user,
            'How do I apply for leave?',
        );

        $this->assertContains($selfGuide->id, $result['trace']['document_ids']);
        $this->assertNotContains($managementGuide->id, $result['trace']['document_ids']);
        $this->assertSame(['Applying for Leave'], collect($result['guidance'])->pluck('title')->unique()->values()->all());
        $this->assertStringNotContainsString('Approve another user', json_encode($result));
    }

    public function test_management_permission_can_retrieve_management_guide(): void
    {
        $user = $this->userWithPermissions(['staff.leave.manage']);
        $managementGuide = $this->guide(
            'Managing Leave Records',
            'leave-management',
            ['staff.leave.manage'],
            'leave.management',
            'Review the record and use the available workflow action.',
        );

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
        $this->guide(
            'Applying for Leave',
            'leave-self-service',
            ['self.leave'],
            'leave.self_service',
            'Apply for leave by opening Leave and submitting the required dates.',
        );
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
        $this->assertSame(0, $result['trace']['documents_considered']);
    }

    public function test_system_guide_citation_has_no_pdf_document_id(): void
    {
        $guide = $this->guide(
            'Applying for Leave',
            'leave-self-service',
            ['self.leave'],
            'leave.self_service',
            'Open Leave and submit the required dates.',
        );
        $citation = app(AiHelperKnowledgeService::class)->citationsForGuidance([[
            'source_id' => 'S1',
            'source_type' => AiHelperKnowledgeEntry::KNOWLEDGE_SYSTEM_GUIDE,
            'id' => $guide->id,
            'title' => $guide->title,
            'guide_version' => 2,
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
        $payment = $this->guide(
            'Payroll Payment Actions',
            'payment-actions',
            ['staff.salary.pay'],
            'payroll.payment_actions',
            'Mark an approved salary claim paid after verifying the payment reference.',
            'payroll',
            'salary-claims',
            'Finance',
        );

        $result = app(AiHelperKnowledgeRetriever::class)->retrieve(
            ['path' => '/staff/salary-claims'],
            $user,
            'How do I mark a salary claim paid?',
        );

        $this->assertNotContains($payment->id, $result['trace']['document_ids']);
        $this->assertStringNotContainsString('Payroll Payment Actions', json_encode($result));
    }

    public function test_payment_permission_can_retrieve_payment_actions(): void
    {
        $user = $this->userWithPermissions(['staff.salary.pay']);
        $payment = $this->guide(
            'Payroll Payment Actions',
            'payment-actions',
            ['staff.salary.pay'],
            'payroll.payment_actions',
            'Mark an approved salary claim paid after verifying the payment reference.',
            'payroll',
            'salary-claims',
            'Finance',
        );

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
        $settings = $this->guide(
            'Role Permissions',
            'role-permissions',
            ['settings.manage'],
            'settings.role_permissions',
            'Change the permissions assigned to a role.',
            'settings.role_permissions',
            'settings',
            'System Administration',
        );

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

    private function guide(
        string $title,
        string $key,
        array $permissions,
        string $moduleGate,
        string $content,
        string $moduleKey = 'leave',
        ?string $routeKey = null,
        string $owner = 'Human Resources',
    ): AiHelperKnowledgeEntry {
        $entry = AiHelperKnowledgeEntry::create([
            'knowledge_type' => AiHelperKnowledgeEntry::KNOWLEDGE_SYSTEM_GUIDE,
            'title' => $title,
            'content' => $content,
            'source_mime' => 'text/markdown',
            'source_path' => 'seed:system-guide:'.$key,
            'module_key' => $moduleKey,
            'route_key' => $routeKey ?? (str_contains($key, 'management') ? 'leave-management' : 'leave'),
            'module_gate' => $moduleGate,
            'required_permissions' => $permissions,
            'permission_match' => AiHelperKnowledgeEntry::PERMISSION_MATCH_ANY,
            'allowed_roles' => [],
            'guide_owner' => $owner,
            'review_due_at' => now()->addMonth(),
            'visibility' => AiHelperKnowledgeEntry::VISIBILITY_SHARED,
            'status' => AiHelperKnowledgeEntry::STATUS_ACTIVE,
            'review_status' => AiHelperKnowledgeEntry::REVIEW_APPROVED,
            'active' => true,
            'version' => 2,
        ]);
        AiHelperKnowledgeChunk::create([
            'knowledge_entry_id' => $entry->id,
            'chunk_index' => 0,
            'content' => $content,
            'search_text' => $title.' '.$content,
            'content_hash' => hash('sha256', $content),
            'token_estimate' => 20,
            'module_key' => $entry->module_key,
            'route_key' => $entry->route_key,
            'active' => true,
        ]);

        return $entry;
    }
}
