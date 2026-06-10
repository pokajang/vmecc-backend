<?php

namespace App\Http\Controllers;

use App\Models\ReportDraft;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ReportDraftController extends Controller
{
    private const ERCO_TYPE = 'erco';
    private const ERCO_DRAFT_CAP = 50;
    private const INSPECTION_TYPE = 'inspection';
    private const INSPECTION_MAX_PHOTO_COUNT = 10;
    private const INSPECTION_MAX_PHOTO_BYTES = 1572864; // 1.5 MB
    private const INSPECTION_MAX_TOTAL_PHOTO_BYTES = 12582912; // 12 MB
    private const INSPECTION_ALLOWED_IMAGE_MIMES = ['jpeg', 'jpg', 'png', 'webp'];

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $reportType = $this->normalizeReportType((string) $request->query('report_type', ''));
        if ($reportType === '') {
            return response()->json(['message' => 'report_type is required.'], 422);
        }

        $limit = min(100, max(1, (int) $request->query('limit', 50)));
        $page = max(1, (int) $request->query('page', 1));

        $query = ReportDraft::query()
            ->where('user_id', $user->id)
            ->where('report_type', $reportType)
            ->orderByDesc('saved_at')
            ->orderByDesc('id');

        $total = (clone $query)->count();
        $rows = $query->forPage($page, $limit)->get();

        return response()->json([
            'data' => $rows->map(fn (ReportDraft $row) => $this->formatRow($row))->values()->all(),
            'meta' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
            ],
        ]);
    }

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $reportType = $this->normalizeReportType((string) $request->query('report_type', ''));
        if ($reportType === '') {
            return response()->json(['message' => 'report_type is required.'], 422);
        }

        $row = ReportDraft::query()
            ->where('user_id', $user->id)
            ->where('report_type', $reportType)
            ->orderByDesc('saved_at')
            ->orderByDesc('id')
            ->first();

        if (!$row) {
            return response()->json(['data' => null]);
        }

        return response()->json(['data' => $this->formatRow($row)]);
    }

    public function showById(Request $request, string $draftId): JsonResponse
    {
        $user = $request->user();
        $row = ReportDraft::query()
            ->where('user_id', $user->id)
            ->where('draft_id', trim((string) $draftId))
            ->firstOrFail();

        return response()->json(['data' => $this->formatRow($row)]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'report_type' => ['required', 'string', 'max:60'],
            'payload' => ['required', 'array'],
            'title' => ['nullable', 'string', 'max:190'],
            'origin_mode' => ['nullable', 'string', 'in:new,edit'],
            'source_report_uid' => ['nullable', 'string', 'max:190'],
            'draft_id' => ['nullable', 'string', 'max:80'],
        ]);

        $reportType = $this->normalizeReportType((string) $data['report_type']);
        if ($reportType === '') {
            return response()->json(['message' => 'report_type is required.'], 422);
        }
        if ($reportType === self::INSPECTION_TYPE) {
            $this->validateInspectionPayload((array) $data['payload']);
        }

        $incomingDraftId = trim((string) ($data['draft_id'] ?? ''));
        $row = null;

        if ($incomingDraftId !== '') {
            $row = ReportDraft::query()
                ->where('user_id', $user->id)
                ->where('draft_id', $incomingDraftId)
                ->first();
        } else {
            $row = ReportDraft::query()
                ->where('user_id', $user->id)
                ->where('report_type', $reportType)
                ->orderByDesc('saved_at')
                ->orderByDesc('id')
                ->first();
        }

        if (!$row) {
            $row = $this->createDraft($user->id, $data, $reportType);
            return response()->json(['data' => $this->formatRow($row)], 201);
        }

        $row->fill([
            'payload' => $data['payload'],
            'title' => $this->normalizeNullableString($data['title'] ?? null),
            'origin_mode' => $this->normalizeOriginMode($data['origin_mode'] ?? null),
            'source_report_uid' => $this->normalizeNullableString($data['source_report_uid'] ?? null),
            'saved_at' => now(),
        ]);
        $row->save();

        return response()->json(['data' => $this->formatRow($row)]);
    }

    public function updateById(Request $request, string $draftId): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'payload' => ['required', 'array'],
            'title' => ['nullable', 'string', 'max:190'],
            'origin_mode' => ['nullable', 'string', 'in:new,edit'],
            'source_report_uid' => ['nullable', 'string', 'max:190'],
        ]);

        $row = ReportDraft::query()
            ->where('user_id', $user->id)
            ->where('draft_id', trim((string) $draftId))
            ->firstOrFail();
        if ($this->normalizeReportType((string) ($row->report_type ?? '')) === self::INSPECTION_TYPE) {
            $this->validateInspectionPayload((array) $data['payload']);
        }

        $row->fill([
            'payload' => $data['payload'],
            'title' => $this->normalizeNullableString($data['title'] ?? null),
            'origin_mode' => $this->normalizeOriginMode($data['origin_mode'] ?? null),
            'source_report_uid' => $this->normalizeNullableString($data['source_report_uid'] ?? null),
            'saved_at' => now(),
        ]);
        $row->save();

        return response()->json(['data' => $this->formatRow($row)]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $user = $request->user();
        $reportType = $this->normalizeReportType((string) $request->query('report_type', ''));
        if ($reportType === '') {
            return response()->json(['message' => 'report_type is required.'], 422);
        }

        ReportDraft::query()
            ->where('user_id', $user->id)
            ->where('report_type', $reportType)
            ->delete();

        return response()->json(['message' => 'Draft cleared.']);
    }

    public function destroyById(Request $request, string $draftId): JsonResponse
    {
        $user = $request->user();
        ReportDraft::query()
            ->where('user_id', $user->id)
            ->where('draft_id', trim((string) $draftId))
            ->delete();

        return response()->json(['message' => 'Draft deleted.']);
    }

    private function normalizeReportType(string $value): string
    {
        return strtolower(trim($value));
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));
        return $text === '' ? null : $text;
    }

    private function normalizeOriginMode(mixed $value): string
    {
        $text = strtolower(trim((string) ($value ?? '')));
        return $text === 'edit' ? 'edit' : 'new';
    }

    private function createDraft(int $userId, array $data, string $reportType): ReportDraft
    {
        if ($reportType === self::ERCO_TYPE) {
            $count = ReportDraft::query()
                ->where('user_id', $userId)
                ->where('report_type', $reportType)
                ->count();
            if ($count >= self::ERCO_DRAFT_CAP) {
                throw ValidationException::withMessages([
                    'report_type' => ['Draft limit reached. You can only keep up to 50 ERCO drafts.'],
                ]);
            }
        }

        return ReportDraft::query()->create([
            'user_id' => $userId,
            'draft_id' => 'drf_' . Str::lower(Str::random(20)),
            'report_type' => $reportType,
            'title' => $this->normalizeNullableString($data['title'] ?? null),
            'origin_mode' => $this->normalizeOriginMode($data['origin_mode'] ?? null),
            'source_report_uid' => $this->normalizeNullableString($data['source_report_uid'] ?? null),
            'payload' => $data['payload'],
            'saved_at' => now(),
        ]);
    }

    private function formatRow(ReportDraft $row): array
    {
        return [
            'id' => $row->id,
            'draft_id' => $row->draft_id,
            'report_type' => $row->report_type,
            'title' => $row->title,
            'origin_mode' => $row->origin_mode ?: 'new',
            'source_report_uid' => $row->source_report_uid,
            'payload' => is_array($row->payload) ? $row->payload : [],
            'saved_at' => optional($row->saved_at)->toIso8601String(),
            'created_at' => optional($row->created_at)->toIso8601String(),
            'updated_at' => optional($row->updated_at)->toIso8601String(),
        ];
    }

    private function validateInspectionPayload(array $payload): void
    {
        $payloadJson = json_encode($payload);
        if ($payloadJson !== false && strlen($payloadJson) > self::INSPECTION_MAX_TOTAL_PHOTO_BYTES * 2) {
            throw ValidationException::withMessages([
                'payload' => ['Inspection payload is too large. Please reduce photo count/size.'],
            ]);
        }

        $photos = is_array($payload['photos'] ?? null) ? $payload['photos'] : [];
        if (count($photos) > self::INSPECTION_MAX_PHOTO_COUNT) {
            throw ValidationException::withMessages([
                'payload.photos' => ['Maximum 10 photos are allowed for inspection drafts.'],
            ]);
        }

        $totalPhotoBytes = 0;
        foreach ($photos as $index => $photo) {
            if (!is_array($photo)) {
                throw ValidationException::withMessages([
                    "payload.photos.{$index}" => ['Invalid photo payload.'],
                ]);
            }

            $url = trim((string) ($photo['url'] ?? ''));
            if ($url === '') {
                throw ValidationException::withMessages([
                    "payload.photos.{$index}.url" => ['Photo URL is required.'],
                ]);
            }

            if (!preg_match('/^data:image\/([a-z0-9.+-]+);base64,([a-z0-9+\/=\r\n]+)$/i', $url, $match)) {
                throw ValidationException::withMessages([
                    "payload.photos.{$index}.url" => [
                        'Photo must be an inline base64 data URL image.',
                    ],
                ]);
            }

            $imageMime = strtolower(trim((string) ($match[1] ?? '')));
            if (!in_array($imageMime, self::INSPECTION_ALLOWED_IMAGE_MIMES, true)) {
                throw ValidationException::withMessages([
                    "payload.photos.{$index}.url" => [
                        'Only jpeg, png, and webp images are allowed.',
                    ],
                ]);
            }

            $base64Data = preg_replace('/\s+/u', '', (string) ($match[2] ?? ''));
            $decoded = base64_decode($base64Data, true);
            if ($decoded === false) {
                throw ValidationException::withMessages([
                    "payload.photos.{$index}.url" => ['Invalid base64 image data.'],
                ]);
            }

            $photoBytes = strlen($decoded);
            if ($photoBytes > self::INSPECTION_MAX_PHOTO_BYTES) {
                throw ValidationException::withMessages([
                    "payload.photos.{$index}.url" => ['Each photo must be 1.5 MB or smaller.'],
                ]);
            }
            $totalPhotoBytes += $photoBytes;
        }

        if ($totalPhotoBytes > self::INSPECTION_MAX_TOTAL_PHOTO_BYTES) {
            throw ValidationException::withMessages([
                'payload.photos' => ['Total photo size must be 12 MB or smaller.'],
            ]);
        }
    }
}
