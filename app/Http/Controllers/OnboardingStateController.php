<?php

namespace App\Http\Controllers;

use App\Models\UserOnboardingState;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OnboardingStateController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        return response()->json([
            'data' => UserOnboardingState::payloadForUser($user),
        ]);
    }

    public function store(Request $request, string $key): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $normalizedKey = trim($key);
        $currentVersion = UserOnboardingState::currentVersionFor($normalizedKey);
        if (! $currentVersion) {
            throw ValidationException::withMessages([
                'key' => ['The selected onboarding key is invalid.'],
            ]);
        }

        $data = $request->validate([
            'version' => ['required', 'string', Rule::in([$currentVersion])],
            'event' => ['required', 'string', Rule::in(['started', 'completed', 'dismissed', 'snoozed'])],
            'snoozedUntil' => ['required_if:event,snoozed', 'nullable', 'date'],
            'payload' => ['sometimes', 'nullable', 'array'],
        ]);

        $state = UserOnboardingState::query()->firstOrNew([
            'user_id' => $user->id,
            'key' => $normalizedKey,
            'version' => $data['version'],
        ]);

        $now = now();
        if (array_key_exists('payload', $data)) {
            $state->payload = $data['payload'];
        }

        match ($data['event']) {
            'started' => $state->last_started_at = $now,
            'completed' => $state->forceFill([
                'completed_at' => $now,
                'dismissed_at' => null,
                'snoozed_until' => null,
            ]),
            'dismissed' => $state->forceFill([
                'dismissed_at' => $now,
                'snoozed_until' => null,
            ]),
            'snoozed' => $state->snoozed_until = $data['snoozedUntil'],
        };

        $state->save();

        return response()->json([
            'data' => [
                $state->key => $state->toPayload(),
            ],
        ]);
    }
}
