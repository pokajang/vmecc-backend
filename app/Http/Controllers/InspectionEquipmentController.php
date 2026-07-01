<?php

namespace App\Http\Controllers;

use App\Models\InspectionEquipment;
use App\Models\InspectionLocation;
use App\Services\AssignmentAuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InspectionEquipmentController extends Controller
{
    private const DEFAULT_INSPECTION_TYPE = 'Hydraulic Rescue Tools Inspection';

    public function __construct(
        private readonly AssignmentAuthorizationService $authorizationService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->ensureInspectionPermission($request);

        $type = $this->resolveInspectionType($request);
        $mainLocationName = Str::of((string) $request->query('mainLocation', ''))->squish()->toString();
        $query = InspectionEquipment::query()
            ->where('inspection_type_key', $type['key'])
            ->where('is_active', true);

        if ($mainLocationName !== '') {
            $query->where('main_location_name', $mainLocationName);
        }

        $rows = $query
            ->orderBy('main_location_name')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $version = InspectionEquipment::query()->max('updated_at');

        return response()->json([
            'data' => $rows->map(fn (InspectionEquipment $row) => $this->formatEquipment($row, $request))->values(),
            'meta' => [
                'inspectionTypeKey' => $type['key'],
                'inspectionTypeLabel' => $type['label'],
                'mainLocation' => $mainLocationName,
                'version' => $version ? Carbon::parse($version)->toISOString() : null,
                'source' => 'database',
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->ensureInspectionPermission($request);

        $data = $request->validate([
            'inspectionType' => ['nullable', 'string', 'max:190'],
            'inspectionTypeKey' => ['nullable', 'string', 'max:120'],
            'mainLocationId' => ['nullable', 'integer'],
            'main_location_id' => ['nullable', 'integer'],
            'mainLocation' => ['nullable', 'string', 'max:190'],
            'main_location' => ['nullable', 'string', 'max:190'],
            'name' => ['required', 'string', 'max:190'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        $type = $this->resolveInspectionType($request, $data);
        $mainLocationName = Str::of((string) ($data['mainLocation'] ?? $data['main_location'] ?? ''))->squish()->toString();
        $mainLocationId = (int) ($data['mainLocationId'] ?? $data['main_location_id'] ?? 0) ?: null;
        if ($mainLocationName === '') {
            throw ValidationException::withMessages([
                'mainLocation' => ['Main location is required.'],
            ]);
        }
        if ($mainLocationId && ! InspectionLocation::query()->where('is_active', true)->whereKey($mainLocationId)->exists()) {
            throw ValidationException::withMessages([
                'mainLocationId' => ['Main location is not available.'],
            ]);
        }

        $name = Str::of((string) $data['name'])->squish()->toString();
        $normalized = $this->normalizeName($name);
        $duplicate = InspectionEquipment::query()
            ->where('inspection_type_key', $type['key'])
            ->where('main_location_name', $mainLocationName)
            ->where('normalized_name', $normalized)
            ->where('is_active', true)
            ->first();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'name' => ['This equipment already exists for the selected main location.'],
            ]);
        }

        $equipment = InspectionEquipment::query()->create([
            'inspection_type_key' => $type['key'],
            'inspection_type_label' => $type['label'],
            'main_location_id' => $mainLocationId,
            'main_location_name' => $mainLocationName,
            'name' => $name,
            'normalized_name' => $normalized,
            'description' => trim((string) ($data['description'] ?? '')) ?: null,
            'source' => 'custom',
            'created_by' => $request->user()?->id,
            'is_active' => true,
            'sort_order' => $this->nextSortOrder($type['key'], $mainLocationName),
        ]);

        return response()->json(['data' => $this->formatEquipment($equipment, $request)], 201);
    }

    public function update(Request $request, int $equipmentId): JsonResponse
    {
        $this->ensureInspectionPermission($request);
        $equipment = $this->findActiveEquipment($equipmentId);
        if ($equipment->source === 'seed' && ! $this->canManageSeedEquipment($request)) {
            return response()->json([
                'message' => 'Seeded inspection equipment can only be changed by report managers.',
                'code' => 'INSPECTION_EQUIPMENT_SEED_PROTECTED',
            ], 403);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:190'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        $name = Str::of((string) $data['name'])->squish()->toString();
        $normalized = $this->normalizeName($name);
        $duplicate = InspectionEquipment::query()
            ->where('inspection_type_key', $equipment->inspection_type_key)
            ->where('main_location_name', $equipment->main_location_name)
            ->where('normalized_name', $normalized)
            ->where('is_active', true)
            ->whereKeyNot($equipment->id)
            ->first();
        if ($duplicate) {
            throw ValidationException::withMessages([
                'name' => ['This equipment already exists for the selected main location.'],
            ]);
        }

        $equipment->fill([
            'name' => $name,
            'normalized_name' => $normalized,
            'description' => trim((string) ($data['description'] ?? '')) ?: null,
        ])->save();

        return response()->json(['data' => $this->formatEquipment($equipment, $request)]);
    }

    public function destroy(Request $request, int $equipmentId): JsonResponse|Response
    {
        $this->ensureInspectionPermission($request);
        $equipment = $this->findActiveEquipment($equipmentId);
        if ($equipment->source === 'seed' && ! $this->canManageSeedEquipment($request)) {
            return response()->json([
                'message' => 'Seeded inspection equipment can only be archived by report managers.',
                'code' => 'INSPECTION_EQUIPMENT_SEED_PROTECTED',
            ], 403);
        }

        $equipment->update(['is_active' => false]);

        return response()->noContent();
    }

    private function findActiveEquipment(int $id): InspectionEquipment
    {
        return InspectionEquipment::query()
            ->where('is_active', true)
            ->findOrFail($id);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{key: string, label: string}
     */
    private function resolveInspectionType(Request $request, array $payload = []): array
    {
        $label = trim((string) (
            $payload['inspectionType'] ??
            $request->query('inspectionType') ??
            $request->input('inspectionType') ??
            ''
        ));
        $key = trim((string) (
            $payload['inspectionTypeKey'] ??
            $request->query('inspectionTypeKey') ??
            $request->input('inspectionTypeKey') ??
            ''
        ));
        if ($label === '') {
            $label = $key !== ''
                ? Str::headline(str_replace('-', ' ', $key))
                : self::DEFAULT_INSPECTION_TYPE;
        }
        if ($key === '') {
            $key = $this->normalizeTypeKey($label);
        }

        return ['key' => $key, 'label' => $label];
    }

    private function nextSortOrder(string $typeKey, string $mainLocationName): int
    {
        return ((int) InspectionEquipment::query()
            ->where('inspection_type_key', $typeKey)
            ->where('main_location_name', $mainLocationName)
            ->max('sort_order')) + 1;
    }

    private function formatEquipment(InspectionEquipment $equipment, Request $request): array
    {
        $canManageSeed = $this->canManageSeedEquipment($request);
        $canManageRow = $equipment->source !== 'seed' || $canManageSeed;

        return [
            'id' => $equipment->id,
            'equipmentId' => $equipment->id,
            'equipmentKey' => $this->normalizeTypeKey($equipment->name),
            'value' => $equipment->name,
            'title' => $equipment->name,
            'equipment' => $equipment->name,
            'description' => (string) ($equipment->description ?? ''),
            'inspectionTypeKey' => $equipment->inspection_type_key,
            'inspectionType' => $equipment->inspection_type_label,
            'mainLocationId' => $equipment->main_location_id,
            'mainLocation' => $equipment->main_location_name,
            'location' => $equipment->main_location_name,
            'source' => $equipment->source,
            'equipmentSource' => $equipment->source,
            'custom' => $equipment->source !== 'seed',
            'isCustomEquipment' => $equipment->source !== 'seed',
            'canEdit' => $canManageRow,
            'canDelete' => $canManageRow,
        ];
    }

    private function ensureInspectionPermission(Request $request): void
    {
        $user = $request->user();
        if (! $user || ! $this->authorizationService->hasPermission($user, 'reports.manage|reports.inspection.view')) {
            abort(403, 'Missing inspection report permission.');
        }
    }

    private function canManageSeedEquipment(Request $request): bool
    {
        $user = $request->user();
        return (bool) ($user && $this->authorizationService->hasPermission($user, 'reports.manage'));
    }

    private function normalizeTypeKey(string $value): string
    {
        return Str::slug(Str::of($value)->squish()->lower()->toString());
    }

    private function normalizeName(string $value): string
    {
        return Str::of($value)->squish()->lower()->toString();
    }
}
