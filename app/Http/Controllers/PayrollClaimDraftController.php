<?php

namespace App\Http\Controllers;

use App\Models\PayrollClaimDraft;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PayrollClaimDraftController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $claimType = $this->normalizeClaimType($request->input('claim_type'));

        $query = PayrollClaimDraft::query()->where('user_id', $user->id)->orderByDesc('saved_at')->orderByDesc('id');
        if ($claimType) {
            $query->where('claim_type', $claimType);
        }

        $rows = $query->get()->map(function (PayrollClaimDraft $row) {
            [$sanitizedPayload, $didMutate] = $this->sanitizeDraftPayload(
                payload: is_array($row->payload) ? $row->payload : [],
                preserveLegacyBinaryForMigration: true,
            );

            if ($didMutate) {
                $row->payload = $sanitizedPayload;
                $row->saved_at = now();
                $row->save();
            }

            return [
                'id' => $row->id,
                'draft_id' => $row->draft_id,
                'claim_type' => $row->claim_type,
                'payload' => $sanitizedPayload,
                'saved_at' => optional($row->saved_at)->toIso8601String(),
                'updated_at' => optional($row->updated_at)->toIso8601String(),
            ];
        });

        return response()->json(['data' => $rows]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $payload = $request->validate([
            'claim_type' => ['required', Rule::in(['expense', 'salary', 'exceptional', 'other'])],
            'draft_id' => ['nullable', 'string', 'max:120'],
            'payload' => ['required', 'array'],
        ]);

        $claimType = $this->normalizeClaimType($payload['claim_type']) ?? 'expense';
        $draftId = $this->resolveDraftId(
            value: $payload['draft_id'] ?? null,
            claimType: $claimType,
            userId: (int) $user->id,
        );
        [$sanitizedPayload] = $this->sanitizeDraftPayload(
            payload: is_array($payload['payload']) ? $payload['payload'] : [],
            preserveLegacyBinaryForMigration: false,
        );

        $row = PayrollClaimDraft::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'claim_type' => $claimType,
                'draft_id' => $draftId,
            ],
            [
                'payload' => $sanitizedPayload,
                'saved_at' => now(),
            ],
        );

        return response()->json([
            'data' => [
                'id' => $row->id,
                'draft_id' => $row->draft_id,
                'claim_type' => $row->claim_type,
                'payload' => $row->payload,
                'saved_at' => optional($row->saved_at)->toIso8601String(),
                'updated_at' => optional($row->updated_at)->toIso8601String(),
            ],
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        PayrollClaimDraft::query()->where('user_id', $user->id)->where('id', $id)->delete();

        return response()->json(null, 204);
    }

    private function normalizeClaimType(mixed $value): ?string
    {
        $normalized = strtolower(trim((string) $value));
        if ($normalized === '') {
            return null;
        }
        if ($normalized === 'other') {
            return 'exceptional';
        }

        return in_array($normalized, ['expense', 'salary', 'exceptional'], true) ? $normalized : null;
    }

    private function resolveDraftId(mixed $value, string $claimType, int $userId): string
    {
        $candidate = trim((string) ($value ?? ''));
        if ($candidate !== '') {
            return $candidate;
        }

        $existingDraftId = PayrollClaimDraft::query()
            ->where('user_id', $userId)
            ->where('claim_type', $claimType)
            ->whereNotNull('draft_id')
            ->where('draft_id', '!=', '')
            ->orderByDesc('saved_at')
            ->orderByDesc('id')
            ->value('draft_id');
        if (is_string($existingDraftId) && trim($existingDraftId) !== '') {
            return trim($existingDraftId);
        }

        return sprintf('%s-%s', $claimType, strtolower((string) Str::uuid()));
    }

    private function sanitizeDraftPayload(array $payload, bool $preserveLegacyBinaryForMigration): array
    {
        $didMutate = false;

        foreach ([
            'status',
            'submittedBy',
            'submittedByName',
            'updatedBy',
            'updatedByName',
            'workflowStage',
            'workflowSnapshot',
            'nextActionRole',
            'approvalHistory',
            'submittedAt',
            'createdAt',
        ] as $serverOwnedKey) {
            if (array_key_exists($serverOwnedKey, $payload)) {
                unset($payload[$serverOwnedKey]);
                $didMutate = true;
            }
        }

        if (array_key_exists('savedItems', $payload)) {
            $savedItems = is_array($payload['savedItems']) ? $payload['savedItems'] : [];
            $payload['savedItems'] = collect($savedItems)
                ->map(function ($item) use ($preserveLegacyBinaryForMigration, &$didMutate) {
                    return $this->sanitizeDraftItem(
                        value: is_array($item) ? $item : [],
                        preserveLegacyBinaryForMigration: $preserveLegacyBinaryForMigration,
                        didMutate: $didMutate,
                    );
                })
                ->values()
                ->all();
        }

        if (array_key_exists('draftItem', $payload)) {
            $payload['draftItem'] = $this->sanitizeDraftItem(
                value: is_array($payload['draftItem']) ? $payload['draftItem'] : [],
                preserveLegacyBinaryForMigration: $preserveLegacyBinaryForMigration,
                didMutate: $didMutate,
            );
        }

        return [$payload, $didMutate];
    }

    private function sanitizeDraftItem(
        array $value,
        bool $preserveLegacyBinaryForMigration,
        bool &$didMutate,
    ): array {
        $attachmentId = $this->normalizeAttachmentId($value['attachmentId'] ?? $value['attachment_id'] ?? null);
        $value['attachmentId'] = $attachmentId;

        $rawLegacyPayload = trim((string) ($value['legacyAttachmentDataUrl'] ?? $value['attachmentDataUrl'] ?? ''));
        $hasLegacyBinary = $rawLegacyPayload !== '';
        $migrationAttempted = ($value['attachmentMigrationAttempted'] ?? false) === true;
        $hasAttachmentReference = $attachmentId !== null;

        if (array_key_exists('attachmentDataUrl', $value)) {
            unset($value['attachmentDataUrl']);
            $didMutate = true;
        }

        if ($hasLegacyBinary && $preserveLegacyBinaryForMigration && ! $migrationAttempted && ! $hasAttachmentReference) {
            if (($value['legacyAttachmentDataUrl'] ?? null) !== $rawLegacyPayload) {
                $didMutate = true;
            }
            $value['legacyAttachmentDataUrl'] = $rawLegacyPayload;
        } else {
            if (array_key_exists('legacyAttachmentDataUrl', $value)) {
                unset($value['legacyAttachmentDataUrl']);
                $didMutate = true;
            }

            if ($hasLegacyBinary && ! $hasAttachmentReference) {
                $value['needsReattach'] = true;
                $value['attachmentMigrationAttempted'] = true;
                $value['attachmentUploadState'] = 'failed';
                $didMutate = true;
            }
        }

        $value['attachmentSizeBytes'] = max(
            0,
            (int) ($value['attachmentSizeBytes'] ?? $value['attachment_size_bytes'] ?? 0),
        );

        $needsReattach = ($value['needsReattach'] ?? false) === true;
        $value['needsReattach'] = $needsReattach;
        $value['attachmentMigrationAttempted'] = ($value['attachmentMigrationAttempted'] ?? false) === true;

        $resolvedUploadState = $this->normalizeUploadState($value['attachmentUploadState'] ?? null, $attachmentId, $needsReattach);
        if (($value['attachmentUploadState'] ?? null) !== $resolvedUploadState) {
            $didMutate = true;
        }
        $value['attachmentUploadState'] = $resolvedUploadState;

        return $value;
    }

    private function normalizeUploadState(mixed $value, ?int $attachmentId, bool $needsReattach): string
    {
        $normalized = strtolower(trim((string) ($value ?? '')));
        if (in_array($normalized, ['idle', 'uploading', 'uploaded', 'failed'], true)) {
            return $normalized;
        }

        if ($needsReattach) {
            return 'failed';
        }

        return $attachmentId ? 'uploaded' : 'idle';
    }

    private function normalizeAttachmentId(mixed $value): ?int
    {
        if (is_string($value)) {
            $value = trim($value);
        }

        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        $id = (int) $value;

        return $id > 0 ? $id : null;
    }
}
