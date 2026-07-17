<?php

namespace App\Services;

use App\Models\AiHelperKnowledgeEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use RuntimeException;

class AiHelperSystemGuideCatalog
{
    public const DISPLAY_LABEL = 'VMECC System Guide';

    public const FINAL_VERSION = 3;

    public const RELEASE_DRAFT = 'draft';

    public const RELEASE_APPROVED = 'approved';

    public const RELEASE_STATUSES = [self::RELEASE_DRAFT, self::RELEASE_APPROVED];

    public function __construct(private readonly AiHelperSystemGuideApprovalManifest $approvals) {}

    /** @var array<string, array{module_key: string, route_key: string, module_gate: string, permissions: array<int, string>, permission_match?: string, roles?: array<int, string>, owner: string}> */
    private const GUIDES = [
        'ask-ai-usage' => ['module_key' => 'profile', 'route_key' => 'global', 'module_gate' => 'profile', 'permissions' => [], 'owner' => 'System Administration'],
        'dashboard-basics' => ['module_key' => 'dashboard', 'route_key' => 'dashboard', 'module_gate' => 'dashboard', 'permissions' => ['self.dashboard'], 'owner' => 'System Administration'],
        'profile-security' => ['module_key' => 'profile', 'route_key' => 'profile', 'module_gate' => 'profile', 'permissions' => [], 'owner' => 'System Administration'],
        'profile-banking' => ['module_key' => 'profile', 'route_key' => 'profile', 'module_gate' => 'profile', 'permissions' => ['self.profile.banking'], 'owner' => 'Human Resources'],
        'profile-medical' => ['module_key' => 'profile', 'route_key' => 'profile', 'module_gate' => 'profile', 'permissions' => ['self.profile.medical'], 'owner' => 'Human Resources'],
        'profile-emergency' => ['module_key' => 'profile', 'route_key' => 'profile', 'module_gate' => 'profile', 'permissions' => ['self.profile.emergency'], 'owner' => 'Human Resources'],
        'messages' => ['module_key' => 'messages', 'route_key' => 'messages', 'module_gate' => 'messages', 'permissions' => ['self.messages'], 'owner' => 'System Administration'],
        'leave-self-service' => ['module_key' => 'leave', 'route_key' => 'leave', 'module_gate' => 'leave.self_service', 'permissions' => ['self.leave'], 'owner' => 'Human Resources'],
        'leave-management' => ['module_key' => 'leave', 'route_key' => 'leave-management', 'module_gate' => 'leave.management', 'permissions' => ['staff.leave.manage'], 'owner' => 'Human Resources'],
        'leave-entitlements' => ['module_key' => 'leave', 'route_key' => 'leave-management', 'module_gate' => 'leave.assignments', 'permissions' => ['staff.leave.manage'], 'owner' => 'Human Resources'],
        'holiday-administration' => ['module_key' => 'leave', 'route_key' => 'leave-management', 'module_gate' => 'leave.holidays', 'permissions' => ['staff.leave.manage'], 'owner' => 'Human Resources'],
        'leave-workflow-rules' => ['module_key' => 'leave', 'route_key' => 'leave-management', 'module_gate' => 'leave.workflow_rules', 'permissions' => ['settings.manage'], 'owner' => 'Human Resources'],
        'overtime-self-service' => ['module_key' => 'overtime', 'route_key' => 'overtime', 'module_gate' => 'overtime.self_service', 'permissions' => ['self.overtime'], 'owner' => 'Human Resources'],
        'overtime-management' => ['module_key' => 'overtime', 'route_key' => 'overtime-management', 'module_gate' => 'overtime.management', 'permissions' => ['staff.overtime.manage'], 'owner' => 'Human Resources'],
        'overtime-rates' => ['module_key' => 'overtime', 'route_key' => 'overtime-management', 'module_gate' => 'overtime.rate_settings', 'permissions' => ['settings.manage'], 'owner' => 'Human Resources'],
        'overtime-workflow-rules' => ['module_key' => 'overtime', 'route_key' => 'overtime-management', 'module_gate' => 'overtime.workflow_rules', 'permissions' => ['settings.manage'], 'owner' => 'Human Resources'],
        'payroll-self-service' => ['module_key' => 'payroll', 'route_key' => 'payroll', 'module_gate' => 'payroll.self_service', 'permissions' => ['self.payroll'], 'owner' => 'Finance'],
        'payroll-claims' => ['module_key' => 'payroll', 'route_key' => 'payroll', 'module_gate' => 'payroll.claims', 'permissions' => ['self.payroll'], 'owner' => 'Finance'],
        'salary-claims-management' => ['module_key' => 'payroll', 'route_key' => 'salary-claims', 'module_gate' => 'payroll.salary_claims_management', 'permissions' => ['staff.salary.manage'], 'owner' => 'Finance'],
        'payment-actions' => ['module_key' => 'payroll', 'route_key' => 'salary-claims', 'module_gate' => 'payroll.payment_actions', 'permissions' => ['staff.salary.pay'], 'owner' => 'Finance'],
        'salary-assignments' => ['module_key' => 'payroll', 'route_key' => 'salary-claims', 'module_gate' => 'payroll.salary_assignments', 'permissions' => ['staff.salary.manage'], 'owner' => 'Finance'],
        'payroll-statutory-rates' => ['module_key' => 'payroll', 'route_key' => 'salary-claims', 'module_gate' => 'payroll.statutory_rates', 'permissions' => ['settings.manage', 'staff.salary.manage'], 'owner' => 'Finance'],
        'payroll-company-profile' => ['module_key' => 'payroll', 'route_key' => 'salary-claims', 'module_gate' => 'payroll.company_profile', 'permissions' => ['settings.manage', 'staff.salary.manage'], 'owner' => 'Finance'],
        'payroll-workflow-rules' => ['module_key' => 'payroll', 'route_key' => 'salary-claims', 'module_gate' => 'payroll.workflow_rules', 'permissions' => ['settings.manage'], 'owner' => 'Finance'],
        'staff-directory' => ['module_key' => 'staff', 'route_key' => 'staff', 'module_gate' => 'staff.directory', 'permissions' => ['staff.view'], 'owner' => 'Human Resources'],
        'staff-records' => ['module_key' => 'staff', 'route_key' => 'staff', 'module_gate' => 'staff', 'permissions' => ['staff.manage'], 'owner' => 'Human Resources'],
        'user-administration' => ['module_key' => 'users', 'route_key' => 'users', 'module_gate' => 'users', 'permissions' => ['users.manage'], 'owner' => 'System Administration'],
        'role-assignments' => ['module_key' => 'users', 'route_key' => 'users', 'module_gate' => 'users', 'permissions' => ['roles.assign'], 'owner' => 'System Administration'],
        'password-session-controls' => ['module_key' => 'users', 'route_key' => 'users', 'module_gate' => 'users', 'permissions' => ['users.manage'], 'owner' => 'System Administration'],
        'teams-view' => ['module_key' => 'teams', 'route_key' => 'teams', 'module_gate' => 'teams.directory', 'permissions' => ['teams.view'], 'owner' => 'Operations'],
        'teams-manage' => ['module_key' => 'teams', 'route_key' => 'teams', 'module_gate' => 'teams.directory', 'permissions' => ['teams.manage'], 'owner' => 'Operations'],
        'roster-view' => ['module_key' => 'roster', 'route_key' => 'roster', 'module_gate' => 'roster', 'permissions' => ['teams.view'], 'owner' => 'Operations'],
        'roster-manage' => ['module_key' => 'roster', 'route_key' => 'roster', 'module_gate' => 'roster', 'permissions' => ['rosters.manage'], 'owner' => 'Operations'],
        'reports-navigation' => ['module_key' => 'reports', 'route_key' => 'reports', 'module_gate' => 'reports', 'permissions' => ['reports.inspection.view', 'reports.erco.view', 'reports.drill.view', 'reports.fitness.view'], 'owner' => 'Operations'],
        'erco-reports' => ['module_key' => 'reports', 'route_key' => 'erco', 'module_gate' => 'reports.erco', 'permissions' => ['reports.erco.view'], 'owner' => 'Operations'],
        'drill-reports' => ['module_key' => 'reports', 'route_key' => 'drill', 'module_gate' => 'reports.drill', 'permissions' => ['reports.drill.view'], 'owner' => 'Operations'],
        'fitness-reports' => ['module_key' => 'reports', 'route_key' => 'fitness', 'module_gate' => 'reports.fitness_test', 'permissions' => ['reports.fitness.view'], 'owner' => 'Operations'],
        'report-management' => ['module_key' => 'reports', 'route_key' => 'reports', 'module_gate' => 'reports', 'permissions' => ['reports.manage'], 'owner' => 'Operations'],
        'inspection-view' => ['module_key' => 'reports.inspection', 'route_key' => 'inspection', 'module_gate' => 'reports.inspection', 'permissions' => ['reports.inspection.view'], 'owner' => 'Operations'],
        'inspection-manage' => ['module_key' => 'reports.inspection', 'route_key' => 'inspection', 'module_gate' => 'reports.inspection', 'permissions' => ['reports.manage'], 'owner' => 'Operations'],
        'extinguisher-management' => ['module_key' => 'reports.inspection', 'route_key' => 'inspection', 'module_gate' => 'reports.inspection', 'permissions' => ['reports.inspection.extinguishers.manage'], 'owner' => 'Operations'],
        'inspection-issue-management' => ['module_key' => 'reports.inspection', 'route_key' => 'inspection', 'module_gate' => 'reports.inspection', 'permissions' => ['reports.inspection.issues.manage'], 'owner' => 'Operations'],
        'inspection-issue-verification' => ['module_key' => 'reports.inspection', 'route_key' => 'inspection', 'module_gate' => 'reports.inspection', 'permissions' => ['reports.inspection.issues.verify'], 'owner' => 'Operations'],
        'inspection-workflow-settings' => ['module_key' => 'reports.inspection', 'route_key' => 'settings', 'module_gate' => 'reports.inspection', 'permissions' => ['settings.manage'], 'owner' => 'Operations'],
        'module-activation' => ['module_key' => 'settings.module_activation', 'route_key' => 'settings', 'module_gate' => 'settings.module_activation', 'permissions' => ['settings.manage'], 'owner' => 'System Administration'],
        'role-permissions' => ['module_key' => 'settings.role_permissions', 'route_key' => 'settings', 'module_gate' => 'settings.role_permissions', 'permissions' => ['settings.manage'], 'owner' => 'System Administration'],
        'dashboard-visibility' => ['module_key' => 'settings.dashboard_visibility', 'route_key' => 'settings', 'module_gate' => 'settings.dashboard_visibility', 'permissions' => ['settings.manage'], 'owner' => 'System Administration'],
        'system-maintenance' => ['module_key' => 'settings.system_maintenance', 'route_key' => 'settings', 'module_gate' => 'settings.system_maintenance', 'permissions' => ['settings.manage'], 'owner' => 'System Administration'],
        'workflow-notifications-settings' => ['module_key' => 'workflow_notifications', 'route_key' => 'settings', 'module_gate' => 'workflow_notifications', 'permissions' => ['settings.manage'], 'owner' => 'System Administration'],
        'audit-logs' => ['module_key' => 'audit', 'route_key' => 'audit', 'module_gate' => 'audit', 'permissions' => ['audit.view'], 'owner' => 'System Administration'],
        'ask-ai-administration' => ['module_key' => 'profile', 'route_key' => 'ai-helper-admin', 'module_gate' => 'profile', 'permissions' => ['*'], 'roles' => ['System Administrator'], 'owner' => 'System Administration'],
    ];

    /** @var array<string, array{module_key: string, patterns: array<int, string>}> */
    private const ROUTES = [
        'global' => ['module_key' => 'profile', 'patterns' => []],
        'dashboard' => ['module_key' => 'dashboard', 'patterns' => ['#^/dashboard(?:/|$)#']],
        'profile' => ['module_key' => 'profile', 'patterns' => ['#^/profile(?:/|$)#']],
        'messages' => ['module_key' => 'messages', 'patterns' => ['#^/messages(?:/|$)#']],
        'leave-management' => ['module_key' => 'leave', 'patterns' => ['#^/staff/leave-management(?:/|$)#']],
        'leave' => ['module_key' => 'leave', 'patterns' => ['#^/leave(?:/|$)#']],
        'overtime-management' => ['module_key' => 'overtime', 'patterns' => ['#^/staff/overtime-management(?:/|$)#']],
        'overtime' => ['module_key' => 'overtime', 'patterns' => ['#^/overtime(?:/|$)#']],
        'salary-claims' => ['module_key' => 'payroll', 'patterns' => ['#^/staff/(?:salary-claims|set-salary)(?:/|$)#']],
        'payroll' => ['module_key' => 'payroll', 'patterns' => ['#^/payroll(?:/|$)#']],
        'users' => ['module_key' => 'users', 'patterns' => ['#^/admin/users(?:/|$)#']],
        'staff' => ['module_key' => 'staff', 'patterns' => ['#^/staff(?:/|$)#']],
        'teams' => ['module_key' => 'teams', 'patterns' => ['#^/team(?:/|$)#']],
        'roster' => ['module_key' => 'roster', 'patterns' => ['#^/roster(?:/|$)#']],
        'inspection' => ['module_key' => 'reports.inspection', 'patterns' => ['#^/(?:inspection|report/inspection)(?:/|$)#']],
        'erco' => ['module_key' => 'reports', 'patterns' => ['#^/report/erco(?:/|$)#']],
        'drill' => ['module_key' => 'reports', 'patterns' => ['#^/report/drill(?:/|$)#']],
        'fitness' => ['module_key' => 'reports', 'patterns' => ['#^/report/fitness(?:-test)?(?:/|$)#']],
        'reports' => ['module_key' => 'reports', 'patterns' => ['#^/report(?:/|$)#']],
        'settings' => ['module_key' => 'settings.module_activation', 'patterns' => ['#^/(?:settings|reporting-settings|notifications)(?:/|$)#']],
        'audit' => ['module_key' => 'audit', 'patterns' => ['#^/admin/audit(?:/|$)#']],
        'ai-helper-admin' => ['module_key' => 'profile', 'patterns' => ['#^/admin/ai-helper-(?:knowledge|reports)(?:/|$)#']],
    ];

    private const REQUIRED_SECTIONS = [
        'Purpose', 'Who can access it', 'Required permission/module state', 'Where to find the page',
        'Prerequisites', 'Exact steps', 'Fields and validation', 'Statuses and transitions',
        'Who performs the next action', 'Attachments and limits', 'Common errors and recovery',
        'What Ask AI cannot do', 'Related pages', 'Source-of-truth code references for maintainers',
        'Guide maintenance',
    ];

    public function keys(): array
    {
        return array_keys(self::GUIDES);
    }

    public function expectedCount(): int
    {
        return count(self::GUIDES);
    }

    public function definition(string $key): ?array
    {
        return self::GUIDES[$key] ?? null;
    }

    public function all(): array
    {
        return self::GUIDES;
    }

    public function displayLabel(): string
    {
        return self::DISPLAY_LABEL;
    }

    /** @return array<int, string> */
    public function validateRegistry(): array
    {
        $errors = [
            ...ModuleCatalog::validateRegistry(),
            ...$this->approvals->registryErrors($this->keys()),
        ];
        foreach (self::GUIDES as $key => $guide) {
            if (! ModuleCatalog::has($guide['module_key']) || ! ModuleCatalog::has($guide['module_gate'])) {
                $errors[] = "{$key} has unknown module metadata";
            }
            if (! isset(self::ROUTES[$guide['route_key']])) {
                $errors[] = "{$key} has unknown route metadata";
            }
            foreach ($guide['permissions'] as $permission) {
                if ($permission !== '*' && ! in_array($permission, RoleCatalog::allPermissions(), true)) {
                    $errors[] = "{$key} has unknown permission {$permission}";
                }
            }
            foreach ($guide['roles'] ?? [] as $role) {
                if (! in_array($role, RoleCatalog::ROLES, true)) {
                    $errors[] = "{$key} has unknown role {$role}";
                }
            }
        }

        return array_values(array_unique($errors));
    }

    public function resolveTrustedRoute(string $path): array
    {
        $path = '/'.ltrim((string) parse_url($path, PHP_URL_PATH), '/');
        foreach (self::ROUTES as $routeKey => $route) {
            foreach ($route['patterns'] as $pattern) {
                if (preg_match($pattern, $path) === 1) {
                    return ['route_key' => $routeKey, 'module_key' => $route['module_key']];
                }
            }
        }

        return ['route_key' => null, 'module_key' => null];
    }

    public function matchesStoredMetadata(AiHelperKnowledgeEntry $entry): bool
    {
        $prefix = 'seed:system-guide:';
        if (! str_starts_with((string) $entry->source_path, $prefix)) {
            return false;
        }
        $key = Str::after((string) $entry->source_path, $prefix);
        $definition = self::GUIDES[$key] ?? null;
        if ($definition === null) {
            return false;
        }

        $actualPermissions = $this->stringList($entry->required_permissions ?? []);
        $expectedPermissions = $definition['permissions'];
        $actualRoles = $this->stringList($entry->allowed_roles ?? []);
        $expectedRoles = $definition['roles'] ?? [];
        sort($actualPermissions);
        sort($expectedPermissions);
        sort($actualRoles);
        sort($expectedRoles);

        return $entry->knowledge_type === AiHelperKnowledgeEntry::KNOWLEDGE_SYSTEM_GUIDE
            && $entry->uploaded_by === null
            && $entry->source_document_id === null
            && $entry->visibility === AiHelperKnowledgeEntry::VISIBILITY_SHARED
            && $entry->source_mime === 'text/markdown'
            && $entry->module_key === $definition['module_key']
            && $entry->route_key === $definition['route_key']
            && $entry->module_gate === $definition['module_gate']
            && $entry->guide_owner === $definition['owner']
            && $entry->permission_match === ($definition['permission_match'] ?? AiHelperKnowledgeEntry::PERMISSION_MATCH_ANY)
            && $actualPermissions === $expectedPermissions
            && $actualRoles === $expectedRoles;
    }

    public function validate(array $frontmatter, string $content, string $source): array
    {
        $key = $this->requiredString($frontmatter, 'key', $source);
        $definition = self::GUIDES[$key] ?? null;
        if ($definition === null) {
            throw new RuntimeException("Unknown system-guide key in {$source}: {$key}");
        }
        if (($frontmatter['knowledge_type'] ?? null) !== AiHelperKnowledgeEntry::KNOWLEDGE_SYSTEM_GUIDE) {
            throw new RuntimeException("System guide knowledge_type must be system_guide in {$source}.");
        }

        $moduleKey = $this->requiredString($frontmatter, 'module_key', $source);
        $routeKey = $this->requiredString($frontmatter, 'route_key', $source);
        $moduleGate = $this->requiredString($frontmatter, 'module_gate', $source);
        $scopeType = $this->requiredString($frontmatter, 'scope_type', $source);
        if (! in_array($scopeType, [
            AiHelperKnowledgeEntry::SCOPE_GLOBAL,
            AiHelperKnowledgeEntry::SCOPE_MODULE,
            AiHelperKnowledgeEntry::SCOPE_ROUTE,
        ], true)) {
            throw new RuntimeException("System guide has invalid scope_type in {$source}.");
        }
        if (! ModuleCatalog::has($moduleKey) || ! ModuleCatalog::has($moduleGate)) {
            throw new RuntimeException("System guide has unknown module metadata in {$source}.");
        }
        if (! isset(self::ROUTES[$routeKey])) {
            throw new RuntimeException("System guide has unknown route_key in {$source}: {$routeKey}");
        }
        foreach (['module_key' => $moduleKey, 'route_key' => $routeKey, 'module_gate' => $moduleGate] as $field => $value) {
            if ($definition[$field] !== $value) {
                throw new RuntimeException("System guide {$field} does not match the catalog in {$source}.");
            }
        }

        $permissions = $this->stringList($frontmatter['required_permissions'] ?? []);
        $expectedPermissions = $definition['permissions'];
        sort($permissions);
        sort($expectedPermissions);
        if ($permissions !== $expectedPermissions) {
            throw new RuntimeException("System guide permissions do not match the catalog in {$source}.");
        }
        foreach ($permissions as $permission) {
            if ($permission !== '*' && ! in_array($permission, RoleCatalog::allPermissions(), true)) {
                throw new RuntimeException("System guide has unknown permission {$permission} in {$source}.");
            }
        }

        $permissionMatch = (string) ($frontmatter['permission_match'] ?? AiHelperKnowledgeEntry::PERMISSION_MATCH_ANY);
        if (! in_array($permissionMatch, AiHelperKnowledgeEntry::PERMISSION_MATCHES, true)) {
            throw new RuntimeException("System guide has invalid permission_match in {$source}.");
        }
        $roles = $this->stringList($frontmatter['allowed_roles'] ?? []);
        $expectedRoles = $definition['roles'] ?? [];
        sort($roles);
        sort($expectedRoles);
        if ($roles !== $expectedRoles || array_diff($roles, RoleCatalog::ROLES) !== []) {
            throw new RuntimeException("System guide roles do not match the catalog in {$source}.");
        }

        $owner = $this->requiredString($frontmatter, 'owner', $source);
        $reviewedOn = CarbonImmutable::parse($this->requiredString($frontmatter, 'reviewed_on', $source))->startOfDay();
        $reviewDueOn = CarbonImmutable::parse($this->requiredString($frontmatter, 'review_due_on', $source))->endOfDay();
        if ($reviewedOn->isFuture() || $reviewDueOn->isPast() || $reviewDueOn->lessThan($reviewedOn)) {
            throw new RuntimeException("System guide review date is stale or invalid in {$source}.");
        }
        if ($owner !== $definition['owner']) {
            throw new RuntimeException("System guide owner does not match the catalog in {$source}.");
        }
        if ($permissions === [] && $this->isPrivileged($content)) {
            throw new RuntimeException("Privileged system guide has no permission restriction in {$source}.");
        }
        foreach (self::REQUIRED_SECTIONS as $section) {
            if (preg_match('/^##\s+'.preg_quote($section, '/').'\s*$/mi', $content) !== 1) {
                throw new RuntimeException("System guide is missing required section '{$section}' in {$source}.");
            }
        }
        if (preg_match('/\b(?:placeholder|todo|tbd|lorem ipsum|coming soon)\b/i', $content) === 1) {
            throw new RuntimeException("System guide contains placeholder wording in {$source}.");
        }

        $releaseStatus = $this->requiredString($frontmatter, 'release_status', $source);
        if (! in_array($releaseStatus, self::RELEASE_STATUSES, true)) {
            throw new RuntimeException("System guide has invalid release_status in {$source}.");
        }
        $active = $this->strictBoolean($frontmatter, 'active', $source);
        $version = max(1, (int) ($frontmatter['version'] ?? 1));
        if ($active && $releaseStatus !== self::RELEASE_APPROVED) {
            throw new RuntimeException("Draft system guide cannot be active in {$source}.");
        }
        if ($releaseStatus === self::RELEASE_APPROVED && ! $active) {
            throw new RuntimeException("Approved system guide must be active in {$source}.");
        }
        if ($releaseStatus === self::RELEASE_DRAFT && isset($this->approvals->all()[$key])) {
            throw new RuntimeException("Draft system guide must not have an approval manifest record in {$source}.");
        }
        if ($releaseStatus === self::RELEASE_APPROVED) {
            if ($version !== self::FINAL_VERSION) {
                throw new RuntimeException('Approved system guide must use version '.self::FINAL_VERSION." in {$source}.");
            }
            $this->validateReleaseContent($content, $source);
        }

        $metadata = [
            'key' => $key,
            'title' => $this->requiredString($frontmatter, 'title', $source),
            'scope_type' => $scopeType,
            'module_key' => $moduleKey,
            'route_key' => $routeKey,
            'module_gate' => $moduleGate,
            'required_permissions' => $permissions,
            'permission_match' => $permissionMatch,
            'allowed_roles' => $roles,
            'version' => $version,
            'owner' => $owner,
            'reviewed_on' => $reviewedOn,
            'review_due_on' => $reviewDueOn,
            'release_status' => $releaseStatus,
            'tags' => $this->stringList($frontmatter['tags'] ?? []),
            'active' => $active,
        ];

        if ($releaseStatus === self::RELEASE_APPROVED) {
            $metadata['approval'] = $this->approvals->validateApprovedGuide($metadata, $content, $source);
        }

        return $metadata;
    }

    public function approvalMatchesEntry(AiHelperKnowledgeEntry $entry): bool
    {
        return $this->approvals->matchesEntry($entry);
    }

    private function requiredString(array $values, string $key, string $source): string
    {
        $value = trim((string) ($values[$key] ?? ''));
        if ($value === '') {
            throw new RuntimeException("System guide is missing {$key} in {$source}.");
        }

        return $value;
    }

    private function stringList(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/\s*,\s*/', $value) ?: [];
        }

        return collect(is_array($value) ? $value : [])
            ->map(fn ($item) => trim((string) $item))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function isPrivileged(string $content): bool
    {
        return Str::contains(Str::lower($content), [
            'approve another user', 'reject another user', 'delete another user', 'publish roster',
            'assign role', 'mark paid',
            'maintenance mode', 'activate module',
        ]);
    }

    private function strictBoolean(array $values, string $key, string $source): bool
    {
        if (! array_key_exists($key, $values) || ! is_bool($values[$key])) {
            throw new RuntimeException("System guide {$key} must be true or false in {$source}.");
        }

        return $values[$key];
    }

    private function validateReleaseContent(string $content, string $source): void
    {
        $genericPhrases = [
            'open the stated page',
            'select the intended record or section',
            'the next actor is determined by current state',
            'audit vmecc-frontend/src/routes.js and the current page component',
            'related navigation stays within the',
            'typically',
            'usually',
            'if available',
            'depending on configuration',
        ];
        if (Str::contains(Str::lower($content), $genericPhrases)) {
            throw new RuntimeException("Approved system guide still contains generic draft wording in {$source}.");
        }

        $steps = $this->section($content, 'Exact steps');
        if (preg_match_all('/^\d+\.\s+\S+/m', $steps) < 3) {
            throw new RuntimeException("Approved system guide needs at least three concrete steps in {$source}.");
        }

        $navigation = $this->section($content, 'Where to find the page');
        preg_match_all('#(?<![\w`])/[a-z][a-z0-9_/:.-]*#i', $navigation, $routeMatches);
        foreach (array_unique($routeMatches[0] ?? []) as $path) {
            $path = rtrim($path, '.,;:)');
            if ($this->resolveTrustedRoute($path)['route_key'] === null) {
                throw new RuntimeException("Approved system guide references an unknown frontend route {$path} in {$source}.");
            }
        }

        $references = $this->section($content, 'Source-of-truth code references for maintainers');
        if (preg_match('/`[^`]*(?:vmecc-frontend\/|src\/)[^`]+`/i', $references) !== 1
            || preg_match('/`[^`]*(?:vmecc-backend\/|app\/|routes\/|tests\/)[^`]+`/i', $references) !== 1) {
            throw new RuntimeException("Approved system guide needs concrete frontend and backend code references in {$source}.");
        }
    }

    private function section(string $content, string $heading): string
    {
        if (preg_match(
            '/^##\s+'.preg_quote($heading, '/').'\s*$\R(.*?)(?=^##\s+|\z)/ims',
            $content,
            $matches,
        ) !== 1) {
            return '';
        }

        return trim($matches[1]);
    }
}
