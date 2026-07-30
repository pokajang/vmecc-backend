<?php

namespace App\Http\Controllers;

use App\Models\OvertimeDraft;
use App\Models\OvertimeRecord;
use App\Models\User;
use App\Models\WorkflowAttachment;
use App\Services\OvertimeEligibilityService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OvertimeDraftController extends Controller
{
    public function __construct(
        private readonly OvertimeEligibilityService $overtimeEligibilityService,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $draft = OvertimeDraft::query()->where('user_id', $user->id)->first();

        return response()->json(['data' => $draft ? $this->formatDraft($draft) : null]);
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
            'expected_version' => ['nullable', 'integer', 'min:1'],
        ]);
        $sanitizedPayload = $this->sanitizePayload($payload['payload'], (int) $user->id);
        if (strlen((string) json_encode($sanitizedPayload)) > 65536) {
            throw ValidationException::withMessages([
                'payload' => ['Overtime draft payload must not exceed 64 KB.'],
            ]);
        }

        $draft = DB::transaction(function () use ($user, $payload, $sanitizedPayload) {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $draft = OvertimeDraft::query()
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();
            if (! $draft) {
                if (isset($payload['expected_version'])) {
                    $this->throwVersionConflict();
                }

                return OvertimeDraft::query()->create([
                    'user_id' => $user->id,
                    'payload' => $sanitizedPayload,
                    'saved_at' => now(),
                    'version' => 1,
                ]);
            }

            $this->assertExpectedVersion($draft, $payload['expected_version'] ?? null);
            $draft->update([
                'payload' => $sanitizedPayload,
                'saved_at' => now(),
                'version' => ((int) $draft->version) + 1,
            ]);

            return $draft->fresh();
        });

        return response()->json(['data' => $this->formatDraft($draft)]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $user = $request->user();
        $payload = $request->validate([
            'expected_version' => ['nullable', 'integer', 'min:1'],
        ]);
        DB::transaction(function () use ($user, $payload): void {
            $draft = OvertimeDraft::query()
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();
            if (! $draft) {
                return;
            }
            $this->assertExpectedVersion($draft, $payload['expected_version'] ?? null);
            $draft->delete();
        });

        return response()->json(null, 204);
    }

    private function sanitizePayload(array $payload, int $userId): array
    {
        $sanitized = Arr::only($payload, [
            'overtimeType',
            'overtimeTypeConfirmed',
            'claimDate',
            'startTime',
            'endTime',
            'reason',
            'sourceRecordId',
            'sourceRecordServerId',
            'attachmentId',
            'attachment',
            'savedAt',
        ]);
        $sourceRecordId = (int) ($sanitized['sourceRecordServerId'] ?? 0);
        if ($sourceRecordId > 0 && ! OvertimeRecord::query()
            ->whereKey($sourceRecordId)
            ->where('user_id', $userId)
            ->exists()) {
            throw ValidationException::withMessages([
                'payload.sourceRecordServerId' => ['The source overtime record is unavailable.'],
            ]);
        }

        if (isset($sanitized['attachment']) && is_array($sanitized['attachment'])) {
            $sanitized['attachment'] = Arr::only($sanitized['attachment'], [
                'id',
                'originalName',
                'mimeType',
                'size',
            ]);
        }
        $attachmentId = (int) ($sanitized['attachmentId']
            ?? $sanitized['attachment']['id']
            ?? 0);
        if ($attachmentId > 0 && ! WorkflowAttachment::query()
            ->whereKey($attachmentId)
            ->where('owner_user_id', $userId)
            ->exists()) {
            throw ValidationException::withMessages([
                'payload.attachmentId' => ['The selected attachment is unavailable.'],
            ]);
        }

        return $sanitized;
    }

    private function formatDraft(OvertimeDraft $draft): array
    {
        return [
            ...(is_array($draft->payload) ? $draft->payload : []),
            'draftVersion' => (int) ($draft->version ?: 1),
        ];
    }

    private function assertExpectedVersion(OvertimeDraft $draft, ?int $expectedVersion): void
    {
        if ($expectedVersion !== null && $expectedVersion === (int) $draft->version) {
            return;
        }

        $this->throwVersionConflict($draft);
    }

    private function throwVersionConflict(?OvertimeDraft $draft = null): never
    {
        throw new HttpResponseException(response()->json([
            'code' => 'OT_DRAFT_VERSION_CONFLICT',
            'message' => 'This overtime draft changed. Reload it before saving or deleting.',
            'currentVersion' => (int) ($draft?->version ?? 0),
            'currentDraft' => $draft ? $this->formatDraft($draft) : null,
        ], 409));
    }
}
