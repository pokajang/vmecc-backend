<?php

namespace App\Http\Controllers;

use App\Services\DashboardStatsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DashboardController extends Controller
{
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
            $statsService->stats($module, (string) ($data['period'] ?? 'this_month')),
        );
    }
}
