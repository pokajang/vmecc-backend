<?php

namespace App\Http\Controllers;

use App\Services\AssignmentAuthorizationService;
use App\Services\InspectionDutyConfirmationService;
use App\Services\InspectionDutyContextResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InspectionDutyContextController extends Controller
{
    public function __construct(
        private readonly AssignmentAuthorizationService $authorization,
        private readonly InspectionDutyContextResolver $resolver,
        private readonly InspectionDutyConfirmationService $confirmations,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $this->authorizeInspection($request);

        return response()->json(['data' => $this->resolver->resolve($request->user())]);
    }

    public function confirm(Request $request): JsonResponse
    {
        $this->authorizeInspection($request);
        $data = $request->validate([
            'operation' => ['required', 'string', 'max:32'],
            'contextVersion' => ['required', 'string', 'max:80'],
            'teamId' => ['nullable', 'integer', 'min:1'],
            'shiftKey' => ['nullable', 'string', 'max:80'],
            'formId' => ['nullable', 'string', 'max:100'],
            'recordId' => ['nullable', 'string', 'max:190'],
            'idempotencyKey' => ['nullable', 'string', 'max:190'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        return response()->json(['data' => $this->confirmations->issue($request->user(), $request, $data)], 201);
    }

    private function authorizeInspection(Request $request): void
    {
        if (! $request->user() || ! $this->authorization->hasPermission($request->user(), 'reports.manage|reports.inspection.view')) {
            abort(403, 'Missing inspection report permission.');
        }
    }
}
