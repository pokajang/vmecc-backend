<?php

namespace App\Http\Controllers;

use App\Models\OvertimeDraft;
use App\Services\OvertimeEligibilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OvertimeDraftController extends Controller
{
    public function __construct(
        private readonly OvertimeEligibilityService $overtimeEligibilityService,
    ) {
    }

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $draft = OvertimeDraft::query()->where('user_id', $user->id)->first();

        return response()->json(['data' => $draft?->payload]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $eligibility = $this->overtimeEligibilityService->resolveForUser($user);
        if ($eligibility['eligible'] !== true) {
            return response()->json([
                'code' => 'OT_NOT_APPLICABLE',
                'message' => 'Your current role is not eligible to submit overtime claims.',
                'data' => $eligibility,
            ], 403);
        }

        $payload = $request->validate([
            'payload' => ['required', 'array'],
        ]);

        $draft = OvertimeDraft::query()->updateOrCreate(
            ['user_id' => $user->id],
            ['payload' => $payload['payload'], 'saved_at' => now()],
        );

        return response()->json(['data' => $draft->payload]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $user = $request->user();
        OvertimeDraft::query()->where('user_id', $user->id)->delete();

        return response()->json(null, 204);
    }
}
