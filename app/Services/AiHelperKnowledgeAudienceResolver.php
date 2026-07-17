<?php

namespace App\Services;

use App\Models\AiHelperKnowledgeEntry;
use App\Models\User;

class AiHelperKnowledgeAudienceResolver
{
    public function __construct(
        private readonly AssignmentAuthorizationService $authorization,
        private readonly ModuleActivationService $modules,
        private readonly AiHelperSystemGuideCatalog $catalog,
    ) {}

    public function resolve(?User $user, array $pageContext): AiHelperKnowledgeAudience
    {
        $modulePayload = $this->modules->load();
        $moduleStates = collect($modulePayload['effective'] ?? [])
            ->mapWithKeys(fn (array $state, string $key) => [$key => (bool) ($state['enabled'] ?? true)])
            ->all();
        $trustedRoute = $this->catalog->resolveTrustedRoute((string) ($pageContext['path'] ?? '/'));

        return new AiHelperKnowledgeAudience(
            userId: $user?->id,
            systemAdministrator: $user ? $this->authorization->isSystemAdministrator($user) : false,
            roleNames: $user ? $this->authorization->getActiveRoleNames($user)->values()->all() : [],
            permissionNames: $user ? $this->authorization->getActivePermissionNames($user)->values()->all() : [],
            moduleStates: $moduleStates,
            routeKey: $trustedRoute['route_key'],
            moduleKey: $trustedRoute['module_key'],
        );
    }

    public function allowsSystemGuide(AiHelperKnowledgeEntry $entry, AiHelperKnowledgeAudience $audience): bool
    {
        if (! (bool) config('ai_helper.system_guides_enabled', false)
            || $audience->userId === null
            || $entry->knowledge_type !== AiHelperKnowledgeEntry::KNOWLEDGE_SYSTEM_GUIDE
            || ! str_starts_with((string) $entry->source_path, 'seed:system-guide:')
            || ! $entry->active
            || $entry->status !== AiHelperKnowledgeEntry::STATUS_ACTIVE
            || $entry->review_status !== AiHelperKnowledgeEntry::REVIEW_APPROVED
            || $entry->review_due_at === null
            || $entry->review_due_at->isPast()
            || ! $this->catalog->matchesStoredMetadata($entry)
            || ((bool) config('ai_helper.system_guide_approval_enforced', true)
                && ! $this->catalog->approvalMatchesEntry($entry))) {
            return false;
        }
        if (! ModuleCatalog::has((string) $entry->module_gate)
            || ! ($audience->moduleStates[(string) $entry->module_gate] ?? false)) {
            return false;
        }

        if (! $this->matchesPermissions(
            $entry->required_permissions ?? [],
            (string) $entry->permission_match,
            $audience,
        )) {
            return false;
        }

        $allowedRoles = collect($entry->allowed_roles ?? [])->filter();
        if (! $audience->systemAdministrator
            && $allowedRoles->isNotEmpty()
            && ! $allowedRoles->contains(fn (string $role) => in_array($role, $audience->roleNames, true))) {
            return false;
        }

        return true;
    }

    public function matchesPermissions(
        array $requiredPermissions,
        string $permissionMatch,
        AiHelperKnowledgeAudience $audience,
    ): bool {
        if ($audience->systemAdministrator || $requiredPermissions === []) {
            return true;
        }

        if (in_array('*', $audience->permissionNames, true)) {
            return true;
        }

        $required = collect($requiredPermissions)->filter()->unique()->values();
        $matches = $required->filter(fn (string $permission) => in_array($permission, $audience->permissionNames, true));

        return $permissionMatch === AiHelperKnowledgeEntry::PERMISSION_MATCH_ALL
            ? $matches->count() === $required->count()
            : $matches->isNotEmpty();
    }
}
