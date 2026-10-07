<?php

namespace App\Http\Controllers;

use App\Services\PayrollSalaryBaselineService;
use App\Services\PayrollClaimWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PayrollSalaryBaselineController extends Controller
{
    public function __construct(
        private readonly PayrollSalaryBaselineService $baselineService,
        private readonly PayrollClaimWorkflowService $workflowService,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'period' => ['required', 'regex:/^\d{4}-\d{2}$/'],
        ]);

        $employee = $request->user();
        $baseline = $this->baselineService->resolve($employee, $validated['period']);
        $overtimePreview = $this->workflowService->calculateSalaryOvertimeSnapshot(
            userId: (int) $employee->id,
            periodValue: $validated['period'],
            assignedBasicSalary: (float) ($baseline['basic'] ?? 0),
            applicantRoles: $employee->roles?->pluck('name')->values()->all() ?? [],
        );

        return response()->json([
            'data' => array_merge($baseline, [
                'overtimePreview' => $overtimePreview,
            ]),
        ]);
    }
}
