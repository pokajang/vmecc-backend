<?php

namespace App\Http\Controllers;

use App\Models\ReportDraft;
use App\Services\AssignmentAuthorizationService;
use App\Services\InspectionPayloadService;
use App\Services\RoleCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ReportDraftController extends Controller
{
    private const ERCO_TYPE = 'erco';

    private const ERCO_DRAFT_CAP = 50;

    private const INSPECTION_TYPE = 'inspection';

    public function __construct(
        private readonly AssignmentAuthorizationService $authorizationService,
        private readonly InspectionPayloadService $inspectionPayloadService,
    ) {}

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

        if (! $row) {
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
            ->first();

        if (! $row) {
            return response()->json(['message' => 'Draft not found.'], 404);
        }

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
            $data['payload'] = $this->applyInspectionSessionInspector(
                (array) $data['payload'],
                $request
            );
            $this->inspectionPayloadService->validateForDraft((array) $data['payload']);
            $data['payload'] = $this->inspectionPayloadService->normalize((array) $data['payload']);
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

        if (! $row) {
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
            ->first();

        if (! $row) {
            return response()->json(['message' => 'Draft not found.'], 404);
        }

        if ($this->normalizeReportType((string) ($row->report_type ?? '')) === self::INSPECTION_TYPE) {
            $data['payload'] = $this->applyInspectionSessionInspector(
                (array) $data['payload'],
                $request
            );
            $this->inspectionPayloadService->validateForDraft((array) $data['payload']);
            $data['payload'] = $this->inspectionPayloadService->normalize((array) $data['payload']);
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
            'draft_id' => 'drf_'.Str::lower(Str::random(20)),
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

    private function inspectionSessionActor(Request $request): string
    {
        $user = $request->user();
        foreach (['name', 'full_name', 'fullName', 'display_name', 'displayName', 'email'] as $field) {
            $value = trim((string) ($user?->{$field} ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function inspectionSessionActorSnapshot(Request $request): array
    {
        $user = $request->user();
        $role = $user ? $this->authorizationService->getPrimaryRoleName($user) : null;

        return [
            'userId' => $user?->id ?? null,
            'name' => $this->inspectionSessionActor($request),
            'email' => trim((string) ($user?->email ?? '')),
            'role' => trim((string) ($role ?? '')),
            'roleCode' => trim((string) (RoleCatalog::abbreviationForRole($role) ?? '')),
        ];
    }

    private function applyInspectionSessionInspector(array $payload, Request $request): array
    {
        $field = $this->inspectionPayloadService->inspectorField($payload);
        $actor = $this->inspectionSessionActorSnapshot($request);
        if ($actor['name'] === '') {
            $fieldPath = $field !== null ? "payload.{$field}" : 'payload.inspectionActor';
            throw ValidationException::withMessages([
                $fieldPath => ['Unable to identify current user. Please sign in again.'],
            ]);
        }

        if ($field !== null) {
            $payload[$field] = $actor['name'];
            unset($payload[Str::snake($field)]);
        }

        $payload['inspectionActor'] = $actor;
        $payload['submittedByRole'] = $actor['role'];
        $payload['submittedByRoleCode'] = $actor['roleCode'];
        unset($payload['inspection_actor'], $payload['submitted_by_role'], $payload['submitted_by_role_code']);

        return $payload;
    }
}
