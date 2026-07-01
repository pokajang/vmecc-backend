<?php

namespace App\Http\Controllers;

use App\Models\InspectionLocation;
use App\Models\InspectionLocationTypeLink;
use App\Services\AssignmentAuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InspectionLocationController extends Controller
{
    public function __construct(
        private readonly AssignmentAuthorizationService $authorizationService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->ensureInspectionPermission($request);

        $type = $this->resolveInspectionType($request);
        $rows = InspectionLocationTypeLink::query()
            ->where('inspection_type_key', $type['key'])
            ->with([
                'location' => function ($query) use ($type) {
                    $query
                        ->whereNull('parent_id')
                        ->where('is_active', true)
                        ->with([
                            'activeChildren' => function ($childQuery) use ($type) {
                                $childQuery->whereHas(
                                    'typeLinks',
                                    fn ($linkQuery) => $linkQuery->where('inspection_type_key', $type['key'])
                                );
                            },
                        ]);
                },
            ])
            ->orderBy('sort_order')
            ->orderBy('inspection_location_id')
            ->get()
            ->map(fn (InspectionLocationTypeLink $link) => $link->location)
            ->filter()
            ->values();

        $version = InspectionLocation::query()->max('updated_at');

        return response()->json([
            'data' => $rows->map(fn (InspectionLocation $row) => $this->formatLocation($row))->values(),
            'meta' => [
                'inspectionTypeKey' => $type['key'],
                'inspectionTypeLabel' => $type['label'],
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
            'parentId' => ['nullable', 'integer'],
            'parent_id' => ['nullable', 'integer'],
            'name' => ['required', 'string', 'max:190'],
            'description' => ['nullable', 'string', 'max:500'],
            'iconKey' => ['nullable', 'string', 'max:80'],
            'icon_key' => ['nullable', 'string', 'max:80'],
        ]);

        $type = $this->resolveInspectionType($request, $data);
        $parentId = (int) ($data['parentId'] ?? $data['parent_id'] ?? 0) ?: null;
        $parent = $parentId ? $this->findActiveLocation($parentId) : null;
        if ($parent && $parent->parent_id !== null) {
            throw ValidationException::withMessages([
                'parentId' => ['Sub-locations can only be created under a main location.'],
            ]);
        }
        if ($parent && ! $this->isLocationLinkedToType($parent, $type['key'])) {
            throw ValidationException::withMessages([
                'parentId' => ['Parent location is not available for this inspection type.'],
            ]);
        }

        $name = Str::of((string) $data['name'])->squish()->toString();
        $normalized = $this->normalizeName($name);
        $duplicate = InspectionLocation::query()
            ->where('parent_id', $parentId)
            ->where('normalized_name', $normalized)
            ->where('is_active', true)
            ->first();

        if ($duplicate) {
            if (! $this->isLocationLinkedToType($duplicate, $type['key'])) {
                $this->linkLocationToType($duplicate, $type);
                return response()->json(['data' => $this->formatLocation($duplicate->load('activeChildren'))], 201);
            }

            throw ValidationException::withMessages([
                'name' => [$parentId ? 'This sub-location already exists under the selected main location.' : 'This main location already exists.'],
            ]);
        }

        $location = DB::transaction(function () use ($data, $name, $normalized, $parentId, $request, $type) {
            $location = InspectionLocation::query()->create([
                'parent_id' => $parentId,
                'name' => $name,
                'normalized_name' => $normalized,
                'description' => trim((string) ($data['description'] ?? '')) ?: null,
                'icon_key' => trim((string) ($data['iconKey'] ?? $data['icon_key'] ?? '')) ?: null,
                'source' => 'custom',
                'created_by' => $request->user()?->id,
                'is_active' => true,
                'sort_order' => $this->nextSortOrder($parentId),
            ]);

            $this->linkLocationToType($location, $type);

            return $location;
        });

        return response()->json(['data' => $this->formatLocation($location->load('activeChildren'))], 201);
    }

    public function update(Request $request, int $locationId): JsonResponse
    {
        $this->ensureInspectionPermission($request);
        $location = $this->findActiveLocation($locationId);
        if ($location->source === 'seed' && ! $this->canManageSeedLocations($request)) {
            return response()->json([
                'message' => 'Seeded inspection locations can only be changed by report managers.',
                'code' => 'INSPECTION_LOCATION_SEED_PROTECTED',
            ], 403);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:190'],
            'description' => ['nullable', 'string', 'max:500'],
            'iconKey' => ['nullable', 'string', 'max:80'],
            'icon_key' => ['nullable', 'string', 'max:80'],
        ]);

        $name = Str::of((string) $data['name'])->squish()->toString();
        $normalized = $this->normalizeName($name);
        $duplicate = InspectionLocation::query()
            ->where('parent_id', $location->parent_id)
            ->where('normalized_name', $normalized)
            ->where('is_active', true)
            ->whereKeyNot($location->id)
            ->first();
        if ($duplicate) {
            throw ValidationException::withMessages([
                'name' => [$location->parent_id ? 'This sub-location already exists under the selected main location.' : 'This main location already exists.'],
            ]);
        }

        $location->fill([
            'name' => $name,
            'normalized_name' => $normalized,
            'description' => trim((string) ($data['description'] ?? '')) ?: null,
            'icon_key' => trim((string) ($data['iconKey'] ?? $data['icon_key'] ?? '')) ?: null,
        ])->save();

        return response()->json(['data' => $this->formatLocation($location->load('activeChildren'))]);
    }

    public function destroy(Request $request, int $locationId): JsonResponse|Response
    {
        $this->ensureInspectionPermission($request);
        $location = $this->findActiveLocation($locationId);
        if ($location->source === 'seed' && ! $this->canManageSeedLocations($request)) {
            return response()->json([
                'message' => 'Seeded inspection locations can only be archived by report managers.',
                'code' => 'INSPECTION_LOCATION_SEED_PROTECTED',
            ], 403);
        }

        DB::transaction(function () use ($location) {
            $location->update(['is_active' => false]);
            if ($location->parent_id === null) {
                InspectionLocation::query()
                    ->where('parent_id', $location->id)
                    ->update(['is_active' => false]);
            }
        });

        return response()->noContent();
    }

    private function findActiveLocation(int $id): InspectionLocation
    {
        return InspectionLocation::query()
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
            'General Inspection'
        ));
        $key = trim((string) (
            $payload['inspectionTypeKey'] ??
            $request->query('inspectionTypeKey') ??
            $request->input('inspectionTypeKey') ??
            ''
        ));
        if ($key === '') {
            $key = $this->normalizeTypeKey($label);
        }
        if ($label === '') {
            $label = Str::headline(str_replace('-', ' ', $key));
        }

        return ['key' => $key, 'label' => $label];
    }

    private function linkLocationToType(InspectionLocation $location, array $type): void
    {
        InspectionLocationTypeLink::query()->updateOrCreate(
            [
                'inspection_location_id' => $location->id,
                'inspection_type_key' => $type['key'],
            ],
            [
                'inspection_type_label' => $type['label'],
                'is_default' => true,
                'sort_order' => $this->nextTypeSortOrder($type['key']),
            ]
        );
    }

    private function isLocationLinkedToType(InspectionLocation $location, string $typeKey): bool
    {
        return InspectionLocationTypeLink::query()
            ->where('inspection_location_id', $location->id)
            ->where('inspection_type_key', $typeKey)
            ->exists();
    }

    private function nextSortOrder(?int $parentId): int
    {
        return ((int) InspectionLocation::query()
            ->where('parent_id', $parentId)
            ->max('sort_order')) + 1;
    }

    private function nextTypeSortOrder(string $typeKey): int
    {
        return ((int) InspectionLocationTypeLink::query()
            ->where('inspection_type_key', $typeKey)
            ->max('sort_order')) + 1;
    }

    private function formatLocation(InspectionLocation $location): array
    {
        return [
            'id' => $location->id,
            'parentId' => $location->parent_id,
            'value' => $location->name,
            'title' => $location->name,
            'description' => (string) ($location->description ?? ''),
            'iconKey' => (string) ($location->icon_key ?? ''),
            'source' => $location->source,
            'custom' => $location->source !== 'seed',
            'canEdit' => $location->source !== 'seed',
            'canDelete' => $location->source !== 'seed',
            'subLocations' => $location->relationLoaded('activeChildren')
                ? $location->activeChildren
                    ->map(fn (InspectionLocation $child) => $this->formatLocation($child))
                    ->values()
                    ->all()
                : [],
        ];
    }

    private function ensureInspectionPermission(Request $request): void
    {
        $user = $request->user();
        if (! $user || ! $this->authorizationService->hasPermission($user, 'reports.manage|reports.inspection.view')) {
            abort(403, 'Missing inspection report permission.');
        }
    }

    private function canManageSeedLocations(Request $request): bool
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
