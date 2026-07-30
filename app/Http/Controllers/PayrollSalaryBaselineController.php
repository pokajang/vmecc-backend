<?php

namespace App\Http\Controllers;

use App\Services\PayrollSalaryBaselineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PayrollSalaryBaselineController extends Controller
{
    public function __construct(private readonly PayrollSalaryBaselineService $baselineService) {}

    public function show(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'period' => ['required', 'regex:/^\d{4}-\d{2}$/'],
        ]);

        return response()->json([
            'data' => $this->baselineService->resolve($request->user(), $validated['period']),
        ]);
    }
}
