<?php

namespace App\Http\Controllers;

use App\Models\LeaveDraft;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LeaveDraftController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user  = $request->user();
        $draft = LeaveDraft::where('user_id', $user->id)->first();

        if (!$draft) {
            return response()->json(['data' => null]);
        }

        return response()->json(['data' => $this->formatDraft($draft)]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'draft_data' => ['required', 'array'],
        ]);

        $draft = LeaveDraft::updateOrCreate(
            ['user_id' => $user->id],
            [
                'draft_data' => $data['draft_data'],
                'saved_at'   => now(),
            ]
        );

        return response()->json(['data' => $this->formatDraft($draft)]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $user = $request->user();

        LeaveDraft::where('user_id', $user->id)->delete();

        return response()->json(['message' => 'Draft cleared.']);
    }

    private function formatDraft(LeaveDraft $draft): array
    {
        return [
            'id'         => $draft->id,
            'user_id'    => $draft->user_id,
            'draft_data' => $draft->draft_data,
            'saved_at'   => optional($draft->saved_at)->toIso8601String(),
        ];
    }
}
