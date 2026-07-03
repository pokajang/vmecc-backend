<?php

namespace App\Http\Controllers;

use App\Models\InspectionScbaCatalogItem;
use App\Models\InspectionScbaCatalogSection;
use App\Services\AssignmentAuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InspectionScbaCatalogController extends Controller
{
    public function __construct(
        private readonly AssignmentAuthorizationService $authorizationService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->ensureInspectionPermission($request);
        $mainLocation = $this->text($request->query('mainLocation', ''));

        $sections = InspectionScbaCatalogSection::query()
            ->where('is_active', true)
            ->with(['items' => function ($query) use ($mainLocation) {
                $query->where('is_active', true)
                    ->when($mainLocation !== '', function ($itemQuery) use ($mainLocation) {
                        $itemQuery->where(function ($locationQuery) use ($mainLocation) {
                            $locationQuery
                                ->whereNull('main_location')
                                ->orWhere('main_location', '')
                                ->orWhere('main_location', $mainLocation)
                                ->orWhere('location', $mainLocation);
                        });
                    })
                    ->orderBy('sort_order')
                    ->orderBy('brand')
                    ->orderBy('serial_no');
            }])
            ->orderBy('sort_order')
            ->orderBy('title')
            ->get();

        $version = max(
            (string) (InspectionScbaCatalogSection::query()->max('updated_at') ?? ''),
            (string) (InspectionScbaCatalogItem::query()->max('updated_at') ?? ''),
        );

        return response()->json([
            'data' => $sections->map(fn (InspectionScbaCatalogSection $section) => $this->formatSection($section))->values(),
            'meta' => [
                'version' => $version !== '' ? Carbon::parse($version)->toISOString() : null,
                'source' => 'database',
            ],
        ]);
    }

    public function storeSection(Request $request): JsonResponse
    {
        $this->ensureInspectionPermission($request);
        $data = $request->validate($this->sectionRules());
        $fields = $this->normalizeFields($data['fields'] ?? $data['checks'] ?? []);
        $title = $this->text($data['title'] ?? '');
        if ($title === '') {
            throw ValidationException::withMessages(['title' => ['SCBA section title is required.']]);
        }

        $section = InspectionScbaCatalogSection::query()->create([
            'key' => $this->uniqueSectionKey($title),
            'title' => $title,
            'short_label' => $this->text($data['shortLabel'] ?? $data['short_label'] ?? '') ?: $title,
            'fields' => $fields,
            'source' => 'custom',
            'is_active' => true,
            'sort_order' => $this->nextSectionSortOrder(),
            'created_by' => $request->user()?->id,
            'updated_by' => $request->user()?->id,
        ]);

        $section->setRelation('items', collect());

        return response()->json(['data' => $this->formatSection($section)], 201);
    }

    public function updateSection(Request $request, int $sectionId): JsonResponse
    {
        $this->ensureInspectionPermission($request);
        $section = $this->findActiveSection($sectionId);
        $data = $request->validate($this->sectionRules());
        $fields = $this->normalizeFields($data['fields'] ?? $data['checks'] ?? []);
        $title = $this->text($data['title'] ?? '');
        if ($title === '') {
            throw ValidationException::withMessages(['title' => ['SCBA section title is required.']]);
        }

        $section->fill([
            'title' => $title,
            'short_label' => $this->text($data['shortLabel'] ?? $data['short_label'] ?? '') ?: $title,
            'fields' => $fields,
            'updated_by' => $request->user()?->id,
        ])->save();
        $section->load(['items' => fn ($query) => $query->where('is_active', true)->orderBy('sort_order')]);

        return response()->json(['data' => $this->formatSection($section)]);
    }

    public function destroySection(Request $request, int $sectionId): JsonResponse|Response
    {
        $this->ensureInspectionPermission($request);
        $section = $this->findActiveSection($sectionId);

        DB::transaction(function () use ($section, $request) {
            $section->update([
                'is_active' => false,
                'updated_by' => $request->user()?->id,
            ]);
            $section->items()->update([
                'is_active' => false,
                'updated_by' => $request->user()?->id,
            ]);
        });

        return response()->noContent();
    }

    public function storeItem(Request $request, int $sectionId): JsonResponse
    {
        $this->ensureInspectionPermission($request);
        $section = $this->findActiveSection($sectionId);
        $data = $request->validate($this->itemRules());
        $attributes = $this->itemAttributes($data);
        if (($attributes['brand'] ?? '') === '' && ($attributes['serial_no'] ?? '') === '' && ($attributes['display_name'] ?? '') === '') {
            throw ValidationException::withMessages(['serialNo' => ['SCBA item brand, serial number, or display name is required.']]);
        }

        $item = $section->items()->create(array_merge($attributes, [
            'source' => 'custom',
            'is_active' => true,
            'sort_order' => $this->nextItemSortOrder($section->id),
            'created_by' => $request->user()?->id,
            'updated_by' => $request->user()?->id,
        ]));

        return response()->json(['data' => $this->formatItem($item, $section)], 201);
    }

    public function updateItem(Request $request, int $itemId): JsonResponse
    {
        $this->ensureInspectionPermission($request);
        $item = $this->findActiveItem($itemId);
        $data = $request->validate($this->itemRules());
        $item->fill(array_merge($this->itemAttributes($data), [
            'updated_by' => $request->user()?->id,
        ]))->save();
        $item->load('section');

        return response()->json(['data' => $this->formatItem($item, $item->section)]);
    }

    public function destroyItem(Request $request, int $itemId): JsonResponse|Response
    {
        $this->ensureInspectionPermission($request);
        $item = $this->findActiveItem($itemId);
        $item->update([
            'is_active' => false,
            'updated_by' => $request->user()?->id,
        ]);

        return response()->noContent();
    }

    private function sectionRules(): array
    {
        return [
            'title' => ['required', 'string', 'max:190'],
            'shortLabel' => ['nullable', 'string', 'max:80'],
            'short_label' => ['nullable', 'string', 'max:80'],
            'fields' => ['required_without:checks', 'array', 'min:1'],
            'checks' => ['nullable', 'array', 'min:1'],
        ];
    }

    private function itemRules(): array
    {
        return [
            'location' => ['nullable', 'string', 'max:190'],
            'mainLocation' => ['nullable', 'string', 'max:190'],
            'main_location' => ['nullable', 'string', 'max:190'],
            'brand' => ['nullable', 'string', 'max:120'],
            'serialNo' => ['nullable', 'string', 'max:120'],
            'serial_no' => ['nullable', 'string', 'max:120'],
            'displayName' => ['nullable', 'string', 'max:190'],
            'display_name' => ['nullable', 'string', 'max:190'],
            'equipmentDescription' => ['nullable', 'string', 'max:1000'],
            'equipment_description' => ['nullable', 'string', 'max:1000'],
            'details' => ['nullable', 'string', 'max:1000'],
        ];
    }

    private function normalizeFields(mixed $fields): array
    {
        if (! is_array($fields) || count($fields) === 0) {
            throw ValidationException::withMessages(['fields' => ['At least one SCBA check is required.']]);
        }

        $rows = [];
        $usedKeys = [];
        foreach ($fields as $index => $field) {
            $label = is_array($field)
                ? $this->text($field['label'] ?? $field['name'] ?? '')
                : $this->text($field);
            if ($label === '') {
                throw ValidationException::withMessages(["fields.{$index}.label" => ['SCBA check label is required.']]);
            }

            $providedKey = is_array($field) ? $this->text($field['key'] ?? '') : '';
            $base = $providedKey !== '' && preg_match('/^[a-z][A-Za-z0-9]*$/', $providedKey)
                ? $providedKey
                : Str::camel(Str::slug($label) ?: 'check');
            $key = $base;
            $suffix = 2;
            while (isset($usedKeys[$key])) {
                $key = $base.$suffix;
                $suffix++;
            }
            $usedKeys[$key] = true;

            $rows[] = [
                'key' => $key,
                'label' => $label,
                'kind' => 'status',
            ];
        }

        return $rows;
    }

    private function itemAttributes(array $data): array
    {
        $mainLocation = $this->text($data['mainLocation'] ?? $data['main_location'] ?? $data['location'] ?? '');

        return [
            'location' => $this->text($data['location'] ?? $mainLocation) ?: null,
            'main_location' => $mainLocation ?: null,
            'brand' => $this->text($data['brand'] ?? '') ?: null,
            'serial_no' => $this->text($data['serialNo'] ?? $data['serial_no'] ?? '') ?: null,
            'display_name' => $this->text($data['displayName'] ?? $data['display_name'] ?? '') ?: null,
            'details' => $this->text($data['equipmentDescription'] ?? $data['equipment_description'] ?? $data['details'] ?? '') ?: null,
        ];
    }

    private function formatSection(InspectionScbaCatalogSection $section): array
    {
        return [
            'id' => (string) $section->id,
            'catalogSectionId' => $section->id,
            'key' => $section->key,
            'title' => $section->title,
            'shortLabel' => $section->short_label ?: $section->title,
            'fields' => array_values($section->fields ?? []),
            'isCustomSection' => true,
            'source' => 'custom',
            'isActive' => $section->is_active,
            'sortOrder' => $section->sort_order,
            'canEdit' => true,
            'canDelete' => true,
            'rows' => $section->items->map(fn (InspectionScbaCatalogItem $item) => $this->formatItem($item, $section))->values(),
        ];
    }

    private function formatItem(InspectionScbaCatalogItem $item, InspectionScbaCatalogSection $section): array
    {
        $brand = (string) ($item->brand ?? '');
        $serialNo = (string) ($item->serial_no ?? '');
        $displayName = (string) ($item->display_name ?? trim($brand.' '.$serialNo));

        return [
            'id' => (string) $item->id,
            'catalogItemId' => $item->id,
            'catalogSectionId' => $section->id,
            'sectionKey' => $section->key,
            'location' => (string) ($item->location ?? $item->main_location ?? ''),
            'mainLocation' => (string) ($item->main_location ?? $item->location ?? ''),
            'brand' => $brand,
            'serialNo' => $serialNo,
            'displayName' => $displayName,
            'equipmentDescription' => (string) ($item->details ?? ''),
            'equipmentSource' => 'custom',
            'isCustomEquipment' => true,
            'source' => 'custom',
            'canEdit' => true,
            'canDelete' => true,
        ];
    }

    private function findActiveSection(int $id): InspectionScbaCatalogSection
    {
        return InspectionScbaCatalogSection::query()->where('is_active', true)->findOrFail($id);
    }

    private function findActiveItem(int $id): InspectionScbaCatalogItem
    {
        return InspectionScbaCatalogItem::query()->where('is_active', true)->findOrFail($id);
    }

    private function uniqueSectionKey(string $title): string
    {
        $base = 'customScba-'.(Str::slug($title) ?: 'section');
        $key = $base;
        $suffix = 2;
        while (InspectionScbaCatalogSection::query()->where('key', $key)->exists()) {
            $key = "{$base}-{$suffix}";
            $suffix++;
        }

        return $key;
    }

    private function nextSectionSortOrder(): int
    {
        return ((int) InspectionScbaCatalogSection::query()->max('sort_order')) + 1;
    }

    private function nextItemSortOrder(int $sectionId): int
    {
        return ((int) InspectionScbaCatalogItem::query()->where('section_id', $sectionId)->max('sort_order')) + 1;
    }

    private function ensureInspectionPermission(Request $request): void
    {
        $user = $request->user();
        if (! $user || ! $this->authorizationService->hasPermission($user, 'reports.manage|reports.inspection.view')) {
            abort(403, 'Missing inspection report permission.');
        }
    }

    private function text(mixed $value): string
    {
        return Str::of((string) $value)->squish()->toString();
    }
}
