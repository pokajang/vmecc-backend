<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\QueryException;

class OvertimeEligibilityService
{
    private const SYSTEM_ADMIN_ROLE_KEYS = ['system administrator', 'system admin'];

    public function __construct(
        private readonly OvertimeWorkflowService $overtimeWorkflowService,
        private readonly AssignmentAuthorizationService $authorizationService,
    ) {
    }

    public function resolveForUser(?User $user): array
    {
        if (! $user) {
            return [
                'eligible' => false,
                'applicableRoles' => [],
                'userRoles' => [],
            ];
        }

        try {
            $rateSettings = $this->overtimeWorkflowService->loadRateSettings();
        } catch (QueryException) {
            $rateSettings = $this->overtimeWorkflowService->normalizeRateSettings([]);
        }

        $applicableRoles = $this->normalizeRoles($rateSettings['otApplicability']['roles'] ?? []);
        $userRoles = $this->normalizeRoles(
            $this->authorizationService->getActiveRoleNames($user)->all(),
        );
        if ($this->isSystemAdministrator($userRoles)) {
            return [
                'eligible' => true,
                'applicableRoles' => $applicableRoles,
                'userRoles' => $userRoles,
            ];
        }

        $applicableRoleLookup = collect($applicableRoles)
            ->mapWithKeys(fn (string $role) => [mb_strtolower($role) => true]);
        $eligible = collect($userRoles)
            ->contains(fn (string $role) => $applicableRoleLookup->has(mb_strtolower($role)));

        return [
            'eligible' => $eligible,
            'applicableRoles' => $applicableRoles,
            'userRoles' => $userRoles,
        ];
    }

    private function normalizeRoles(mixed $roles): array
    {
        return collect(is_array($roles) ? $roles : [])
            ->map(fn ($role) => trim((string) $role))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function isSystemAdministrator(array $roles): bool
    {
        return collect($roles)->contains(function ($role) {
            $normalized = mb_strtolower(trim((string) $role));
            return in_array($normalized, self::SYSTEM_ADMIN_ROLE_KEYS, true);
        });
    }
}
