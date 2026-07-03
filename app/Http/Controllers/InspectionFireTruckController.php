<?php

namespace App\Http\Controllers;

use App\Models\InspectionFireTruck;
use App\Services\AssignmentAuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InspectionFireTruckController extends Controller
{
    public function __construct(
        private readonly AssignmentAuthorizationService $authorizationService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->ensureInspectionPermission($request);

        $rows = InspectionFireTruck::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('plate_no')
            ->get();

        $version = InspectionFireTruck::query()->max('updated_at');

        return response()->json([
            'data' => $rows->map(fn (InspectionFireTruck $row) => $this->formatRow($row, $request))->values(),
            'meta' => [
                'version' => $version ? Carbon::parse($version)->toISOString() : null,
                'source' => 'database',
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->ensureInspectionPermission($request);

        $data = $request->validate($this->rules());
        $plateNo = $this->plate($data['plateNo'] ?? $data['plate_no'] ?? '');
        $normalizedPlate = $this->normalizePlate($plateNo);

        if ($normalizedPlate === '') {
            throw ValidationException::withMessages([
                'plateNo' => ['Truck plate number is required.'],
            ]);
        }

        if (InspectionFireTruck::query()->where('normalized_plate_no', $normalizedPlate)->where('is_active', true)->exists()) {
            throw ValidationException::withMessages([
                'plateNo' => ['This truck plate number already exists.'],
            ]);
        }

        $row = InspectionFireTruck::query()->create($this->payloadToAttributes($data, [
            'plate_no' => $plateNo,
            'normalized_plate_no' => $normalizedPlate,
            'source' => 'custom',
            'created_by' => $request->user()?->id,
            'is_active' => true,
            'sort_order' => $this->nextSortOrder(),
        ]));

        return response()->json(['data' => $this->formatRow($row, $request)], 201);
    }

    public function update(Request $request, int $truckId): JsonResponse
    {
        $this->ensureInspectionPermission($request);
        $row = $this->findActiveRow($truckId);
        if ($row->source === 'seed' && ! $this->canManageSeedRows($request)) {
            return response()->json([
                'message' => 'Seeded fire trucks can only be changed by report managers.',
                'code' => 'INSPECTION_FIRE_TRUCK_SEED_PROTECTED',
            ], 403);
        }

        $data = $request->validate($this->rules());
        $plateNo = $this->plate($data['plateNo'] ?? $data['plate_no'] ?? '');
        $normalizedPlate = $this->normalizePlate($plateNo);

        $duplicate = InspectionFireTruck::query()
            ->where('normalized_plate_no', $normalizedPlate)
            ->where('is_active', true)
            ->whereKeyNot($row->id)
            ->exists();
        if ($duplicate) {
            throw ValidationException::withMessages([
                'plateNo' => ['This truck plate number already exists.'],
            ]);
        }

        $row->fill($this->payloadToAttributes($data, [
            'plate_no' => $plateNo,
            'normalized_plate_no' => $normalizedPlate,
        ]))->save();

        return response()->json(['data' => $this->formatRow($row, $request)]);
    }

    public function destroy(Request $request, int $truckId): JsonResponse|Response
    {
        $this->ensureInspectionPermission($request);
        $row = $this->findActiveRow($truckId);
        if ($row->source === 'seed' && ! $this->canManageSeedRows($request)) {
            return response()->json([
                'message' => 'Seeded fire trucks can only be archived by report managers.',
                'code' => 'INSPECTION_FIRE_TRUCK_SEED_PROTECTED',
            ], 403);
        }

        $row->update(['is_active' => false]);

        return response()->noContent();
    }

    private function rules(): array
    {
        return [
            'plateNo' => ['required_without:plate_no', 'string', 'max:40'],
            'plate_no' => ['nullable', 'string', 'max:40'],
            'name' => ['nullable', 'string', 'max:190'],
            'roadTaxExpiry' => ['nullable', 'date'],
            'road_tax_expiry' => ['nullable', 'date'],
            'insuranceExpiry' => ['nullable', 'date'],
            'insurance_expiry' => ['nullable', 'date'],
            'puspakomExpiry' => ['nullable', 'date'],
            'puspakom_expiry' => ['nullable', 'date'],
        ];
    }

    private function findActiveRow(int $id): InspectionFireTruck
    {
        return InspectionFireTruck::query()->where('is_active', true)->findOrFail($id);
    }

    private function payloadToAttributes(array $data, array $extra = []): array
    {
        return array_merge([
            'name' => $this->text($data['name'] ?? '') ?: null,
            'road_tax_expiry' => $this->date($data['roadTaxExpiry'] ?? $data['road_tax_expiry'] ?? null),
            'insurance_expiry' => $this->date($data['insuranceExpiry'] ?? $data['insurance_expiry'] ?? null),
            'puspakom_expiry' => $this->date($data['puspakomExpiry'] ?? $data['puspakom_expiry'] ?? null),
        ], $extra);
    }

    private function formatRow(InspectionFireTruck $row, Request $request): array
    {
        $canManageSeed = $this->canManageSeedRows($request);
        $canManageRow = $row->source !== 'seed' || $canManageSeed;

        return [
            'id' => $row->id,
            'truckId' => $row->id,
            'plateNo' => $row->plate_no,
            'value' => $row->plate_no,
            'title' => $row->plate_no,
            'name' => (string) ($row->name ?? ''),
            'description' => (string) ($row->name ?? ''),
            'roadTaxExpiry' => $row->road_tax_expiry ? $row->road_tax_expiry->format('Y-m-d') : '',
            'insuranceExpiry' => $row->insurance_expiry ? $row->insurance_expiry->format('Y-m-d') : '',
            'puspakomExpiry' => $row->puspakom_expiry ? $row->puspakom_expiry->format('Y-m-d') : '',
            'source' => $row->source,
            'sortOrder' => $row->sort_order,
            'isActive' => $row->is_active,
            'canEdit' => $canManageRow,
            'canDelete' => $canManageRow,
        ];
    }

    private function nextSortOrder(): int
    {
        return ((int) InspectionFireTruck::query()->max('sort_order')) + 1;
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

    private function plate(mixed $value): string
    {
        return Str::of((string) $value)->squish()->upper()->toString();
    }

    private function normalizePlate(mixed $value): string
    {
        return Str::of((string) $value)->squish()->upper()->replaceMatches('/[^A-Z0-9]+/', '')->toString();
    }

    private function date(mixed $value): ?string
    {
        $text = trim((string) $value);
        return $text !== '' ? $text : null;
    }
}
