<?php

namespace App\Http\Controllers;

use App\Services\AssignmentAuthorizationService;
use App\Services\DashboardStatsService;
use App\Services\ModuleActivationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DashboardController extends Controller
{
    private const MODULE_PERMISSIONS = [
        'payroll' => 'dashboard.payroll.view',
        'overtime' => 'dashboard.overtime.view',
        'leave' => 'dashboard.leave.view',
        'roster' => 'dashboard.roster.view',
        'reports' => 'dashboard.reports.view',
    ];

    private const MODULE_ACTIVATION_KEYS = [
        'payroll' => 'dashboard.payroll',
        'overtime' => 'dashboard.overtime',
        'leave' => 'dashboard.leave',
        'roster' => 'dashboard.roster',
        'reports' => 'dashboard.reports',
    ];

    public function stats(
        Request $request,
        DashboardStatsService $statsService,
        AssignmentAuthorizationService $authorizationService,
        ModuleActivationService $moduleActivationService,
    ): JsonResponse {
        $data = $request->validate([
            'period' => ['nullable', 'string', Rule::in(DashboardStatsService::PERIODS)],
            'modules' => ['nullable', 'string'],
        ]);

        $modules = $this->requestedModules($data['modules'] ?? null);
        $user = $request->user();
        $payload = [];

        foreach ($modules as $module) {
            $permission = self::MODULE_PERMISSIONS[$module] ?? null;
            $hasPermission = $module === 'payroll'
                ? $authorizationService->hasOrganizationWidePermission($user, (string) $permission)
                : $authorizationService->hasPermission($user, (string) $permission);
            if (! $permission || ! $hasPermission) {
                abort(403, 'Forbidden');
            }

            $activationKey = self::MODULE_ACTIVATION_KEYS[$module] ?? null;
            $state = $activationKey ? $moduleActivationService->effectiveState($activationKey) : ['enabled' => true];
            if (! ($state['enabled'] ?? true)) {
                abort(403, 'Module is disabled.');
            }

            $payload[$module] = $statsService->stats(
                $module,
                (string) ($data['period'] ?? 'this_month'),
                $user,
            );
        }

        return response()->json($payload);
    }

    public function payrollStats(Request $request, DashboardStatsService $statsService): JsonResponse
    {
        return $this->moduleStats($request, 'payroll', $statsService);
    }

    public function overtimeStats(Request $request, DashboardStatsService $statsService): JsonResponse
    {
        return $this->moduleStats($request, 'overtime', $statsService);
    }

    public function leaveStats(Request $request, DashboardStatsService $statsService): JsonResponse
    {
        return $this->moduleStats($request, 'leave', $statsService);
    }

    public function rosterStats(Request $request, DashboardStatsService $statsService): JsonResponse
    {
        return $this->moduleStats($request, 'roster', $statsService);
    }

    public function reportStats(Request $request, DashboardStatsService $statsService): JsonResponse
    {
        return $this->moduleStats($request, 'reports', $statsService);
    }

    private function moduleStats(
        Request $request,
        string $module,
        DashboardStatsService $statsService,
    ): JsonResponse {
        $data = $request->validate([
            'period' => ['nullable', 'string', Rule::in(DashboardStatsService::PERIODS)],
        ]);

        return response()->json(
            $statsService->stats(
                $module,
                (string) ($data['period'] ?? 'this_month'),
                $request->user(),
            ),
        );
    }

    private function requestedModules(?string $rawModules): array
    {
        $modules = collect(explode(',', (string) $rawModules))
            ->map(fn (string $module) => trim($module))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (empty($modules)) {
            return array_keys(self::MODULE_PERMISSIONS);
        }

        $invalid = array_values(array_diff($modules, array_keys(self::MODULE_PERMISSIONS)));
        if (! empty($invalid)) {
            abort(422, 'Invalid dashboard stats module.');
        }

        return $modules;
    }
}
