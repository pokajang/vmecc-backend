<?php

namespace App\Http\Controllers;

use App\Models\InspectionFireExtinguisher;
use App\Services\AssignmentAuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class InspectionFireExtinguisherController extends Controller
{
    public function __construct(
        private readonly AssignmentAuthorizationService $authorizationService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->ensureInspectionPermission($request);

        $mainLocation = Str::of((string) $request->query('mainLocation', ''))->squish()->toString();
        $subLocation = Str::of((string) $request->query('subLocation', ''))->squish()->toString();
        $search = Str::of((string) $request->query('search', ''))->squish()->toString();

        $query = InspectionFireExtinguisher::query()->where('is_active', true);
        if ($mainLocation !== '') {
            $query->where('main_location_name', $mainLocation);
        }
        if ($subLocation !== '') {
            $query->where('sub_location_name', $subLocation);
        }
        if ($search !== '') {
            $query->where(function ($builder) use ($search) {
                $like = "%{$search}%";
                $builder
                    ->where('zone', 'like', $like)
                    ->orWhere('main_location_name', 'like', $like)
                    ->orWhere('sub_location_name', 'like', $like)
                    ->orWhere('id_loc_no', 'like', $like)
                    ->orWhere('barcode_no', 'like', $like)
                    ->orWhere('fe_type', 'like', $like)
                    ->orWhere('certification_validity_raw', 'like', $like)
                    ->orWhere('days_left_to_expire', 'like', $like);
            });
        }

        $rows = $query
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $version = InspectionFireExtinguisher::query()->max('updated_at');

        return response()->json([
            'data' => $rows->map(fn (InspectionFireExtinguisher $row) => $this->formatRow($row, $request))->values(),
            'meta' => [
                'mainLocation' => $mainLocation,
                'subLocation' => $subLocation,
                'search' => $search,
                'version' => $version ? Carbon::parse($version)->toISOString() : null,
                'source' => 'database',
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->ensureInspectionPermission($request);

        $data = $request->validate($this->rules());
        $row = InspectionFireExtinguisher::query()->create($this->payloadToAttributes($data, [
            'source' => 'custom',
            'created_by' => $request->user()?->id,
            'is_active' => true,
            'sort_order' => $this->nextSortOrder((string) ($data['mainLocation'] ?? $data['main_location'] ?? '')),
        ]));

        return response()->json(['data' => $this->formatRow($row, $request)], 201);
    }

    public function update(Request $request, int $extinguisherId): JsonResponse
    {
        $this->ensureInspectionPermission($request);
        $row = $this->findActiveRow($extinguisherId);
        if ($row->source === 'seed' && ! $this->canManageSeedRows($request)) {
            return response()->json([
                'message' => 'Seeded fire extinguisher rows can only be changed by report managers.',
                'code' => 'INSPECTION_FIRE_EXTINGUISHER_SEED_PROTECTED',
            ], 403);
        }

        $data = $request->validate($this->rules());
        $row->fill($this->payloadToAttributes($data))->save();

        return response()->json(['data' => $this->formatRow($row, $request)]);
    }

    public function destroy(Request $request, int $extinguisherId): JsonResponse|Response
    {
        $this->ensureInspectionPermission($request);
        $row = $this->findActiveRow($extinguisherId);
        if ($row->source === 'seed' && ! $this->canManageSeedRows($request)) {
            return response()->json([
                'message' => 'Seeded fire extinguisher rows can only be archived by report managers.',
                'code' => 'INSPECTION_FIRE_EXTINGUISHER_SEED_PROTECTED',
            ], 403);
        }

        $row->update(['is_active' => false]);

        return response()->noContent();
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function rules(): array
    {
        return [
            'zone' => ['nullable', 'string', 'max:80'],
            'mainLocation' => ['required_without:main_location', 'string', 'max:190'],
            'main_location' => ['nullable', 'string', 'max:190'],
            'subLocation' => ['nullable', 'string', 'max:190'],
            'sub_location' => ['nullable', 'string', 'max:190'],
            'idLocNo' => ['nullable', 'string', 'max:190'],
            'id_loc_no' => ['nullable', 'string', 'max:190'],
            'barcodeNo' => ['nullable', 'string', 'max:190'],
            'barcode_no' => ['nullable', 'string', 'max:190'],
            'feType' => ['nullable', 'string', 'max:120'],
            'fe_type' => ['nullable', 'string', 'max:120'],
            'certificationValidity' => ['nullable', 'date'],
            'certification_validity' => ['nullable', 'date'],
            'certificationValidityRaw' => ['nullable', 'string', 'max:120'],
            'certification_validity_raw' => ['nullable', 'string', 'max:120'],
            'daysLeftToExpire' => ['nullable', 'string', 'max:60'],
            'days_left_to_expire' => ['nullable', 'string', 'max:60'],
        ];
    }

    private function findActiveRow(int $id): InspectionFireExtinguisher
    {
        return InspectionFireExtinguisher::query()->where('is_active', true)->findOrFail($id);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function payloadToAttributes(array $data, array $extra = []): array
    {
        $validity = trim((string) ($data['certificationValidity'] ?? $data['certification_validity'] ?? ''));

        return array_merge([
            'zone' => $this->text($data['zone'] ?? '') ?: null,
            'main_location_name' => $this->text($data['mainLocation'] ?? $data['main_location'] ?? ''),
            'sub_location_name' => $this->text($data['subLocation'] ?? $data['sub_location'] ?? '') ?: null,
            'id_loc_no' => $this->text($data['idLocNo'] ?? $data['id_loc_no'] ?? '') ?: null,
            'barcode_no' => $this->text($data['barcodeNo'] ?? $data['barcode_no'] ?? '') ?: null,
            'fe_type' => $this->normalizeFeType($data['feType'] ?? $data['fe_type'] ?? '') ?: null,
            'certification_validity' => $validity !== '' ? $validity : null,
            'certification_validity_raw' => $this->text($data['certificationValidityRaw'] ?? $data['certification_validity_raw'] ?? $validity) ?: null,
            'days_left_to_expire' => $this->text($data['daysLeftToExpire'] ?? $data['days_left_to_expire'] ?? '') ?: null,
        ], $extra);
    }

    private function formatRow(InspectionFireExtinguisher $row, Request $request): array
    {
        $canManageSeed = $this->canManageSeedRows($request);
        $canManageRow = $row->source !== 'seed' || $canManageSeed;
        $validity = $row->certification_validity;

        return [
            'id' => $row->id,
            'catalogId' => $row->id,
            'sourceRowNumber' => $row->source_row_number,
            'source' => $row->source,
            'equipmentSource' => $row->source,
            'zone' => (string) ($row->zone ?? ''),
            'mainLocation' => $row->main_location_name,
            'location' => $row->main_location_name,
            'subLocation' => (string) ($row->sub_location_name ?? ''),
            'idLocNo' => (string) ($row->id_loc_no ?? ''),
            'barcodeNo' => (string) ($row->barcode_no ?? ''),
            'feType' => $this->normalizeFeType($row->fe_type ?? ''),
            'certificationValidity' => $validity ? $validity->format('Y-m-d') : '',
            'certificationValidityRaw' => (string) ($row->certification_validity_raw ?? ''),
            'daysLeftToExpire' => (string) ($row->days_left_to_expire ?? ''),
            'sortOrder' => $row->sort_order,
            'isActive' => $row->is_active,
            'canEdit' => $canManageRow,
            'canDelete' => $canManageRow,
        ];
    }

    private function nextSortOrder(string $mainLocation): int
    {
        return ((int) InspectionFireExtinguisher::query()
            ->where('main_location_name', $this->text($mainLocation))
            ->max('sort_order')) + 1;
    }

    private function ensureInspectionPermission(Request $request): void
    {
        $user = $request->user();
        if (! $user || ! $this->authorizationService->hasPermission($user, 'reports.manage|reports.inspection.view')) {
            abort(403, 'Missing inspection report permission.');
        }
    }

    private function canManageSeedRows(Request $request): bool
    {
        $user = $request->user();
        return (bool) ($user && $this->authorizationService->hasPermission($user, 'reports.manage'));
    }

    private function text(mixed $value): string
    {
        return Str::of((string) $value)->squish()->toString();
    }

    private function normalizeFeType(mixed $value): string
    {
        return str_replace(["CO\u{00B2}", "CO\u{FFFD}"], 'CO2', $this->text($value));
    }
}
