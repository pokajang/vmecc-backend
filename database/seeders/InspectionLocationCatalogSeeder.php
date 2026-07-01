<?php

namespace Database\Seeders;

use App\Models\InspectionLocation;
use App\Models\InspectionLocationTypeLink;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class InspectionLocationCatalogSeeder extends Seeder
{
    private const HIGH_ANGLE_KIT_ORDER = [
        'Response Kit #1',
        'Response Kit #2',
        'Response Kit #3',
        'Stretcher Response Kit',
        'PPE and Auxillary Kit',
        'Arizona Vortex Tripod Kits',
        'Rescue Rope',
    ];

    private const TYPE_LABELS = [
        'er-aux-equipment-inspection' => 'ER Aux Equipment Inspection',
        'fire-extinguisher-inspection' => 'Fire Extinguisher Inspection',
        'frt-daily-inspection' => 'FRT Daily Inspection',
        'general-inspection' => 'General Inspection',
        'health-safety-environment-inspection' => 'Health Safety Environment Inspection',
        'high-angle-rescue-equipment-inspection' => 'High Angle Rescue Equipment Inspection',
        'hydraulic-rescue-tools-inspection' => 'Hydraulic Rescue Tools Inspection',
        'scba-inspection' => 'SCBA Inspection',
    ];

    public function run(): void
    {
        $source = $this->loadReportReferenceLocations();
        $fireLocations = $source['fire'] ?: $this->fallbackFireLocations();
        $sharedLocations = $this->mergeLocationTrees(
            $fireLocations,
            $this->simpleLocations(['Store', 'Office', 'FRT', 'FRT (Spare)', 'FIRE TRUCK'])
        );

        $catalog = [
            'er-aux-equipment-inspection' => $this->simpleLocations($source['erAux'] ?: ['Store', 'Office']),
            'fire-extinguisher-inspection' => $fireLocations,
            'frt-daily-inspection' => [
                [
                    'name' => 'FIRE TRUCK',
                    'description' => 'Fire truck daily inspection.',
                    'children' => [],
                ],
            ],
            'general-inspection' => $sharedLocations,
            'health-safety-environment-inspection' => $sharedLocations,
            'high-angle-rescue-equipment-inspection' => $this->simpleLocations($source['highAngleKits'] ?: [
                'Response Kit #1',
                'Response Kit #2',
                'Response Kit #3',
                'Stretcher Response Kit',
                'PPE and Auxillary Kit',
                'Arizona Vortex Tripod Kits',
                'Rescue Rope',
            ]),
            'hydraulic-rescue-tools-inspection' => $this->simpleLocations($source['hydraulic'] ?: ['FRT', 'Store']),
            'scba-inspection' => $this->simpleLocations($source['scba'] ?: ['FRT', 'FRT (Spare)', 'Store']),
        ];

        foreach ($catalog as $typeKey => $locations) {
            $typeLabel = self::TYPE_LABELS[$typeKey] ?? Str::headline(str_replace('-', ' ', $typeKey));
            $seededLocationIds = [];
            foreach (array_values($locations) as $index => $location) {
                $row = $this->upsertLocation($location, null, $index + 1);
                $this->linkLocationToType($row, $typeKey, $typeLabel, $index + 1);
                $seededLocationIds[] = $row->id;

                foreach (array_values($location['children'] ?? []) as $childIndex => $child) {
                    $childRow = $this->upsertLocation($child, $row->id, $childIndex + 1);
                    $this->linkLocationToType($childRow, $typeKey, $typeLabel, $childIndex + 1);
                    $seededLocationIds[] = $childRow->id;
                }
            }

            $this->pruneMissingSeedLinks($typeKey, $seededLocationIds);
        }
    }

    /**
     * @return array{erAux: array<int, string>, fire: array<int, array<string, mixed>>, frtSections: array<int, string>, highAngleKits: array<int, string>, hydraulic: array<int, string>, scba: array<int, string>}
     */
    private function loadReportReferenceLocations(): array
    {
        $path = base_path('../report-reference/LOCATIONS.md');
        $content = is_file($path) ? (string) file_get_contents($path) : '';

        return [
            'erAux' => $this->extractList($content, '## VMM ER Aux Equipment Inspection Checklist.xlsx', '### Main locations'),
            'fire' => $this->extractFireLocationTree($content),
            'frtSections' => $this->extractList($content, '## VMM FRT Daily Inspection Checklist.xlsx', '### FRT sections / compartments'),
            'highAngleKits' => $this->orderByPreferredSequence(
                $this->extractList($content, '## VMM High Angle Rescue Equipment Inspection Checklist.xlsx', '### Kits / equipment containers'),
                self::HIGH_ANGLE_KIT_ORDER,
            ),
            'hydraulic' => $this->extractList($content, '## VMM Hydraulic Rescue Tools Inspection Checklist.xlsx', '### Main locations'),
            'scba' => $this->extractList($content, '## VMM SCBA Inspection Checklist.xlsx', '### Main locations'),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function extractFireLocationTree(string $content): array
    {
        $mainLocations = $this->extractList(
            $content,
            '## VMM Fire Extinguisher Inspection Checklist.xlsx',
            '### Main locations'
        );
        $childrenByParent = $this->extractColonListMap(
            $content,
            '## VMM Fire Extinguisher Inspection Checklist.xlsx',
            '### Sub-locations by parent location'
        );

        return array_map(function (string $name) use ($childrenByParent): array {
            $children = $childrenByParent[$name] ?? [];
            return [
                'name' => $name,
                'description' => count($children) > 0
                    ? count($children).' report sub-location'.(count($children) === 1 ? '' : 's').'.'
                    : '',
                'children' => $this->simpleLocations($children),
            ];
        }, $mainLocations);
    }

    /**
     * @return array<int, string>
     */
    private function extractList(string $content, string $sectionHeading, string $listHeading): array
    {
        $section = $this->sliceAfter($content, $sectionHeading, "\n## ");
        $block = $this->sliceAfter($section, $listHeading, "\n### ");

        return $this->dedupeStrings(
            collect(preg_split('/\R/', $block) ?: [])
                ->map(fn (string $line): string => trim($line))
                ->filter(fn (string $line): bool => str_starts_with($line, '- '))
                ->map(fn (string $line): string => trim(substr($line, 2)))
                ->all()
        );
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function extractColonListMap(string $content, string $sectionHeading, string $listHeading): array
    {
        $section = $this->sliceAfter($content, $sectionHeading, "\n## ");
        $block = $this->sliceAfter($section, $listHeading, "\n### ");
        $rows = [];

        foreach (preg_split('/\R/', $block) ?: [] as $line) {
            $line = trim((string) $line);
            if (! str_starts_with($line, '- ') || ! str_contains($line, ': ')) {
                continue;
            }
            [$parent, $children] = explode(': ', substr($line, 2), 2);
            $parent = trim($parent);
            if ($parent === '') {
                continue;
            }
            $rows[$parent] = $this->dedupeStrings(array_map('trim', explode(',', $children)));
        }

        return $rows;
    }

    private function sliceAfter(string $content, string $start, string $nextMarker): string
    {
        $startPos = strpos($content, $start);
        if ($startPos === false) {
            return '';
        }
        $slice = substr($content, $startPos + strlen($start));
        $endPos = strpos($slice, $nextMarker);
        return $endPos === false ? $slice : substr($slice, 0, $endPos);
    }

    /**
     * @param array<int, string> $values
     * @return array<int, array<string, mixed>>
     */
    private function simpleLocations(array $values): array
    {
        return array_map(
            fn (string $name): array => [
                'name' => trim($name),
                'description' => '',
                'children' => [],
            ],
            $this->dedupeStrings($values)
        );
    }

    /**
     * @param array<int, array<string, mixed>> $left
     * @param array<int, array<string, mixed>> $right
     * @return array<int, array<string, mixed>>
     */
    private function mergeLocationTrees(array $left, array $right): array
    {
        $rows = [];
        foreach ([...$left, ...$right] as $row) {
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $key = $this->normalizeName($name);
            if (! isset($rows[$key])) {
                $rows[$key] = $row;
                continue;
            }
            $rows[$key]['children'] = $this->mergeLocationTrees(
                $rows[$key]['children'] ?? [],
                $row['children'] ?? []
            );
        }

        return array_values($rows);
    }

    /**
     * @param array<int, string> $values
     * @return array<int, string>
     */
    private function dedupeStrings(array $values): array
    {
        $seen = [];
        $next = [];
        foreach ($values as $value) {
            $text = trim((string) $value);
            if ($text === '') {
                continue;
            }
            $key = $this->normalizeName($text);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $next[] = $text;
        }

        return $next;
    }

    /**
     * @param array<int, string> $values
     * @param array<int, string> $preferredOrder
     * @return array<int, string>
     */
    private function orderByPreferredSequence(array $values, array $preferredOrder): array
    {
        $deduped = $this->dedupeStrings($values);
        $rankByKey = [];

        foreach ($preferredOrder as $index => $value) {
            $rankByKey[$this->normalizeName($value)] = $index;
        }

        usort($deduped, function (string $left, string $right) use ($rankByKey): int {
            $leftKey = $this->normalizeName($left);
            $rightKey = $this->normalizeName($right);
            $leftRank = $rankByKey[$leftKey] ?? PHP_INT_MAX;
            $rightRank = $rankByKey[$rightKey] ?? PHP_INT_MAX;

            if ($leftRank !== $rightRank) {
                return $leftRank <=> $rightRank;
            }

            return strcmp($left, $right);
        });

        return $deduped;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function upsertLocation(array $row, ?int $parentId, int $sortOrder): InspectionLocation
    {
        $name = trim((string) ($row['name'] ?? ''));
        $normalized = $this->normalizeName($name);
        $location = InspectionLocation::query()
            ->where('parent_id', $parentId)
            ->where('normalized_name', $normalized)
            ->first();

        if (! $location) {
            $location = new InspectionLocation([
                'parent_id' => $parentId,
                'normalized_name' => $normalized,
                'source' => 'seed',
                'is_active' => true,
            ]);
        }

        if ($location->source === 'seed' || ! $location->exists) {
            $location->fill([
                'name' => $name,
                'description' => trim((string) ($row['description'] ?? '')) ?: null,
                'icon_key' => trim((string) ($row['iconKey'] ?? '')) ?: null,
                'sort_order' => $sortOrder,
            ]);
        }
        if (! $location->exists || $location->source === 'seed') {
            $location->source = 'seed';
            $location->is_active = true;
        }
        $location->save();

        return $location;
    }

    private function linkLocationToType(
        InspectionLocation $location,
        string $typeKey,
        string $typeLabel,
        int $sortOrder
    ): void {
        InspectionLocationTypeLink::query()->updateOrCreate(
            [
                'inspection_location_id' => $location->id,
                'inspection_type_key' => $typeKey,
            ],
            [
                'inspection_type_label' => $typeLabel,
                'is_default' => true,
                'sort_order' => $sortOrder,
            ]
        );
    }

    /**
     * @param array<int, int> $seededLocationIds
     */
    private function pruneMissingSeedLinks(string $typeKey, array $seededLocationIds): void
    {
        InspectionLocationTypeLink::query()
            ->where('inspection_type_key', $typeKey)
            ->whereHas('location', fn ($query) => $query->where('source', 'seed'))
            ->when(
                count($seededLocationIds) > 0,
                fn ($query) => $query->whereNotIn('inspection_location_id', $seededLocationIds)
            )
            ->delete();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fallbackFireLocations(): array
    {
        return [
            ['name' => 'Manjung Hub', 'description' => '', 'children' => $this->simpleLocations(['Reception', 'Cafeteria'])],
            ['name' => 'Fire Station', 'description' => '', 'children' => $this->simpleLocations(['Bay', 'FRT'])],
            ['name' => 'Workshop and Warehouse', 'description' => '', 'children' => $this->simpleLocations(['Warehouse Office', 'Warehouse Yard'])],
        ];
    }

    private function normalizeName(string $value): string
    {
        return Str::of($value)->squish()->lower()->toString();
    }
}
