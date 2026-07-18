<?php

namespace Tests\Unit;

use App\Models\AiHelperKnowledgeEntry;
use App\Services\AiHelperKnowledgeAudience;
use App\Services\AiHelperKnowledgeAudienceResolver;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class AiHelperKnowledgeAudienceResolverTest extends TestCase
{
    public function test_permission_any_and_all_are_enforced(): void
    {
        config(['ai_helper.system_guides_enabled' => true]);
        $resolver = app(AiHelperKnowledgeAudienceResolver::class);
        $audience = $this->audience(['self.leave']);

        $this->assertTrue($resolver->matchesPermissions(
            ['self.leave', 'staff.leave.manage'],
            AiHelperKnowledgeEntry::PERMISSION_MATCH_ANY,
            $audience,
        ));
        $this->assertFalse($resolver->matchesPermissions(
            ['self.leave', 'staff.leave.manage'],
            AiHelperKnowledgeEntry::PERMISSION_MATCH_ALL,
            $audience,
        ));
        $this->assertTrue($resolver->matchesPermissions(
            ['staff.salary.pay'],
            AiHelperKnowledgeEntry::PERMISSION_MATCH_ALL,
            $this->audience(['*']),
        ));
    }

    public function test_role_module_review_and_feature_controls_fail_closed(): void
    {
        config(['ai_helper.system_guides_enabled' => true]);
        $resolver = app(AiHelperKnowledgeAudienceResolver::class);
        $entry = $this->guide(['self.leave']);

        $this->assertTrue($resolver->allowsSystemGuide($entry, $this->audience(['self.leave'])));
        $this->assertFalse($resolver->allowsSystemGuide($entry, $this->audience(['self.leave'], [], false)));

        $entry->review_due_at = CarbonImmutable::yesterday();
        $this->assertFalse($resolver->allowsSystemGuide($entry, $this->audience(['self.leave'])));

        config(['ai_helper.system_guides_enabled' => false]);
        $entry->review_due_at = CarbonImmutable::tomorrow();
        $this->assertFalse($resolver->allowsSystemGuide($entry, $this->audience(['self.leave'])));
    }

    public function test_system_administrator_bypasses_permission_and_role_but_not_module_gate(): void
    {
        config(['ai_helper.system_guides_enabled' => true]);
        $resolver = app(AiHelperKnowledgeAudienceResolver::class);
        $entry = $this->guide(['self.leave']);

        $admin = $this->audience([], [], true, true);
        $this->assertTrue($resolver->allowsSystemGuide($entry, $admin));

        $adminWithDisabledModule = $this->audience([], [], false, true);
        $this->assertFalse($resolver->allowsSystemGuide($entry, $adminWithDisabledModule));
    }

    public function test_database_metadata_cannot_weaken_the_catalog_permission_boundary(): void
    {
        config(['ai_helper.system_guides_enabled' => true]);
        $resolver = app(AiHelperKnowledgeAudienceResolver::class);
        $entry = $this->guide([]);

        $this->assertFalse($resolver->allowsSystemGuide($entry, $this->audience([])));
    }

    public function test_system_guide_must_remain_code_controlled_and_shared(): void
    {
        config(['ai_helper.system_guides_enabled' => true]);
        $resolver = app(AiHelperKnowledgeAudienceResolver::class);
        $entry = $this->guide(['self.leave']);
        $audience = $this->audience(['self.leave']);

        $entry->visibility = AiHelperKnowledgeEntry::VISIBILITY_PERSONAL;
        $this->assertFalse($resolver->allowsSystemGuide($entry, $audience));

        $entry->visibility = AiHelperKnowledgeEntry::VISIBILITY_SHARED;
        $entry->uploaded_by = 99;
        $this->assertFalse($resolver->allowsSystemGuide($entry, $audience));

        $entry->uploaded_by = null;
        $entry->source_document_id = 99;
        $this->assertFalse($resolver->allowsSystemGuide($entry, $audience));
    }

    public function test_allowed_role_is_enforced_for_a_catalog_role_restricted_guide(): void
    {
        config(['ai_helper.system_guides_enabled' => true]);
        $resolver = app(AiHelperKnowledgeAudienceResolver::class);
        $entry = new AiHelperKnowledgeEntry([
            'knowledge_type' => AiHelperKnowledgeEntry::KNOWLEDGE_SYSTEM_GUIDE,
            'source_path' => 'seed:system-guide:ask-ai-administration',
            'required_permissions' => ['*'],
            'permission_match' => AiHelperKnowledgeEntry::PERMISSION_MATCH_ANY,
            'allowed_roles' => ['System Administrator'],
            'module_gate' => 'profile',
            'module_key' => 'profile',
            'route_key' => 'ai-helper-admin',
            'guide_owner' => 'System Administration',
            'status' => AiHelperKnowledgeEntry::STATUS_ACTIVE,
            'review_status' => AiHelperKnowledgeEntry::REVIEW_APPROVED,
            'review_due_at' => CarbonImmutable::tomorrow(),
            'source_mime' => 'text/markdown',
            'visibility' => AiHelperKnowledgeEntry::VISIBILITY_SHARED,
            'active' => true,
        ]);
        $withoutRole = new AiHelperKnowledgeAudience(
            userId: 1,
            systemAdministrator: false,
            roleNames: [],
            permissionNames: ['*'],
            moduleStates: ['profile' => true],
            routeKey: 'ai-helper-admin',
            moduleKey: 'profile',
        );
        $withRole = new AiHelperKnowledgeAudience(
            userId: 1,
            systemAdministrator: false,
            roleNames: ['System Administrator'],
            permissionNames: ['*'],
            moduleStates: ['profile' => true],
            routeKey: 'ai-helper-admin',
            moduleKey: 'profile',
        );

        $this->assertFalse($resolver->allowsSystemGuide($entry, $withoutRole));
        $this->assertTrue($resolver->allowsSystemGuide($entry, $withRole));
    }

    private function guide(array $permissions, array $roles = []): AiHelperKnowledgeEntry
    {
        return new AiHelperKnowledgeEntry([
            'knowledge_type' => AiHelperKnowledgeEntry::KNOWLEDGE_SYSTEM_GUIDE,
            'source_path' => 'seed:system-guide:leave-self-service',
            'required_permissions' => $permissions,
            'permission_match' => AiHelperKnowledgeEntry::PERMISSION_MATCH_ANY,
            'allowed_roles' => $roles,
            'module_gate' => 'leave.self_service',
            'module_key' => 'leave',
            'route_key' => 'leave',
            'guide_owner' => 'Human Resources',
            'status' => AiHelperKnowledgeEntry::STATUS_ACTIVE,
            'review_status' => AiHelperKnowledgeEntry::REVIEW_APPROVED,
            'review_due_at' => CarbonImmutable::tomorrow(),
            'source_mime' => 'text/markdown',
            'visibility' => AiHelperKnowledgeEntry::VISIBILITY_SHARED,
            'active' => true,
        ]);
    }

    private function audience(
        array $permissions,
        array $roles = [],
        bool $moduleEnabled = true,
        bool $systemAdministrator = false,
    ): AiHelperKnowledgeAudience {
        return new AiHelperKnowledgeAudience(
            userId: 1,
            systemAdministrator: $systemAdministrator,
            roleNames: $roles,
            permissionNames: $permissions,
            moduleStates: ['leave.self_service' => $moduleEnabled],
            routeKey: 'leave',
            moduleKey: 'leave',
        );
    }
}
