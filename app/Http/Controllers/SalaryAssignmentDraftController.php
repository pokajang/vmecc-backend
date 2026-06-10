<?php

namespace App\Http\Controllers;

use App\Models\SalaryAssignmentDraft;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SalaryAssignmentDraftController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $rows = SalaryAssignmentDraft::query()
            ->where('user_id', $user->id)
            ->orderByDesc('saved_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (SalaryAssignmentDraft $row) => $this->formatRow($row));

        return response()->json(['data' => $rows]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'draft_name' => ['nullable', 'string', 'max:255'],
            'source_assignment_id' => ['nullable', 'integer', 'exists:salary_assignments,id'],
            'payload' => ['required', 'array'],
        ]);

        $row = SalaryAssignmentDraft::query()->create([
            'user_id' => $user->id,
            'draft_name' => trim((string) ($data['draft_name'] ?? '')),
            'source_assignment_id' => $data['source_assignment_id'] ?? null,
            'payload' => $data['payload'],
            'saved_at' => now(),
        ]);

        return response()->json(['data' => $this->formatRow($row)], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $row = SalaryAssignmentDraft::query()->where('user_id', $user->id)->findOrFail($id);

        $data = $request->validate([
            'draft_name' => ['nullable', 'string', 'max:255'],
            'payload' => ['nullable', 'array'],
        ]);

        $row->update([
            'draft_name' => array_key_exists('draft_name', $data)
                ? trim((string) ($data['draft_name'] ?? ''))
                : $row->draft_name,
            'payload' => array_key_exists('payload', $data) ? $data['payload'] : $row->payload,
            'saved_at' => now(),
        ]);

        return response()->json(['data' => $this->formatRow($row->fresh())]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        SalaryAssignmentDraft::query()->where('user_id', $user->id)->where('id', $id)->delete();

        return response()->json(null, 204);
    }

    private function formatRow(SalaryAssignmentDraft $row): array
    {
        return [
            'id' => $row->id,
            'draft_name' => $row->draft_name,
            'source_assignment_id' => $row->source_assignment_id,
            'payload' => $row->payload,
            'saved_at' => optional($row->saved_at)->toIso8601String(),
            'updated_at' => optional($row->updated_at)->toIso8601String(),
        ];
    }
}
