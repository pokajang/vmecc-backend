<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AssignmentAuthorizationService;
use App\Services\FitnessTestAnalyticsService;
use App\Services\ReportReadAuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FitnessTestAnalyticsController extends Controller
{
    public function __construct(
        private readonly FitnessTestAnalyticsService $analytics,
        private readonly AssignmentAuthorizationService $authorization,
        private readonly ReportReadAuthorizationService $reportReadAuthorization,
    ) {}

    public function stats(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->analytics->stats($this->analytics->filters($request->query()))]);
    }

    public function trends(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->analytics->trends($this->analytics->filters($request->query()))]);
    }

    public function checkpoints(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->analytics->checkpoints($this->analytics->filters($request->query()))]);
    }

    public function coverage(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->analytics->coverage($this->analytics->filters($request->query()))]);
    }

    public function personnel(Request $request, int $user): JsonResponse
    {
        $actor = $request->user();
        if (! $actor || ! $this->reportReadAuthorization->canViewModule($actor, 'fitness-test') || ! $this->authorization->hasPermission($actor, 'reports.fitness.individual-results.view|reports.fitness.manage|reports.manage')) {
            abort(403, 'Forbidden');
        }
        $person = User::query()->findOrFail($user);

        return response()->json(['data' => array_merge([
            'user' => ['id' => (int) $person->id, 'name' => (string) $person->name],
        ], $this->analytics->personnel((int) $person->id, $this->analytics->filters($request->query())))]);
    }
}
