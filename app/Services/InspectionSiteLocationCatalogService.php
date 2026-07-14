<?php

namespace App\Services;

use App\Models\InspectionLocation;
use App\Models\InspectionLocationTypeLink;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InspectionSiteLocationCatalogService
{
    public const FIRE_TYPE_KEY = 'fire-extinguisher-inspection';

    public const SITE_TYPES = [
        'fire-extinguisher-inspection' => 'Fire Extinguisher Inspection',
        'general-inspection' => 'General Inspection',
        'health-safety-environment-inspection' => 'Health Safety Environment Inspection',
    ];

    public const LEVELS = ['zone', 'area', 'location'];

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function hierarchy(): Collection
    {
        $locations = $this->canonicalLocations();
        $rowsByParent = $locations->groupBy(
            fn (InspectionLocation $location): string => (string) ($location->parent_id ?? 0)
        );

        return $rowsByParent
            ->get('0', collect())
            ->values()
            ->map(fn (InspectionLocation $row): array => $this->formatTree($row, $rowsByParent));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{row: InspectionLocation, created: bool, level: string, scopeConflict?: bool}
     */
    public function create(array $data, ?int $userId): array
    {
        try {
            return DB::transaction(function () use ($data, $userId): array {
                $level = (string) $data['level'];
                $parent = $this->validatedParent($level, $data['parentId'] ?? null, lock: true);
                $parentId = $parent?->id;
                $name = $this->canonicalName((string) $data['name'], $level);
                $normalized = $this->normalizeName($name);
                $existing = InspectionLocation::query()
                    ->where('active_identity_key', InspectionLocation::activeIdentityKey($parentId, $normalized))
                    ->where('is_active', true)
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    if (! $this->isCanonical($existing)) {
                        return [
                            'row' => $existing,
                            'created' => false,
                            'level' => $level,
                            'scopeConflict' => true,
                        ];
                    }
                    $this->linkToSiteTypes($existing);

                    return ['row' => $existing, 'created' => false, 'level' => $level];
                }

                $row = InspectionLocation::query()->create([
                    'parent_id' => $parentId,
                    'name' => $name,
                    'normalized_name' => $normalized,
                    'description' => trim((string) ($data['description'] ?? '')) ?: null,
                    'icon_key' => trim((string) ($data['iconKey'] ?? '')) ?: null,
                    'source' => 'custom',
                    'created_by' => $userId,
                    'is_active' => true,
                    'sort_order' => $this->nextSortOrder($parentId),
                ]);
                $this->linkToSiteTypes($row);

                return ['row' => $row, 'created' => true, 'level' => $level];
            });
        } catch (QueryException $error) {
            $parentId = ($data['level'] ?? '') === 'zone' ? null : (int) ($data['parentId'] ?? 0);
            $normalized = $this->normalizeName((string) ($data['name'] ?? ''));
            $existing = InspectionLocation::query()
                ->where('active_identity_key', InspectionLocation::activeIdentityKey($parentId, $normalized))
                ->where('is_active', true)
                ->first();
            if (! $existing) {
                throw $error;
            }

            if (! $this->isCanonical($existing)) {
                return [
                    'row' => $existing,
                    'created' => false,
                    'level' => (string) $data['level'],
                    'scopeConflict' => true,
                ];
            }

            DB::transaction(fn () => $this->linkToSiteTypes($existing));

            return [
                'row' => $existing,
                'created' => false,
                'level' => (string) $data['level'],
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{row: InspectionLocation, duplicate: InspectionLocation|null, level: string}
     */
    public function update(int $id, array $data): array
    {
        try {
            return DB::transaction(function () use ($id, $data): array {
                $row = $this->findCanonical($id, lock: true);
                $level = $this->levelFor($row);
                $name = $this->canonicalName((string) $data['name'], $level);
                $normalized = $this->normalizeName($name);
                $duplicate = InspectionLocation::query()
                    ->where('active_identity_key', InspectionLocation::activeIdentityKey($row->parent_id, $normalized))
                    ->where('is_active', true)
                    ->whereKeyNot($row->id)
                    ->lockForUpdate()
                    ->first();
                if ($duplicate) {
                    return ['row' => $row, 'duplicate' => $duplicate, 'level' => $level];
                }

                $attributes = [
                    'name' => $name,
                    'normalized_name' => $normalized,
                ];
                if (array_key_exists('description', $data)) {
                    $attributes['description'] = trim((string) $data['description']) ?: null;
                }
                if (array_key_exists('iconKey', $data)) {
                    $attributes['icon_key'] = trim((string) $data['iconKey']) ?: null;
                }
                $row->fill($attributes)->save();

                return ['row' => $row, 'duplicate' => null, 'level' => $level];
            });
        } catch (QueryException $error) {
            $row = $this->findCanonical($id);
            $normalized = $this->normalizeName((string) ($data['name'] ?? ''));
            $duplicate = InspectionLocation::query()
                ->where('active_identity_key', InspectionLocation::activeIdentityKey($row->parent_id, $normalized))
                ->whereKeyNot($row->id)
                ->where('is_active', true)
                ->first();
            if (! $duplicate) {
                throw $error;
            }

            return ['row' => $row, 'duplicate' => $duplicate, 'level' => $this->levelFor($row)];
        }
    }

    public function archive(int $id): void
    {
        DB::transaction(function () use ($id): void {
            $this->archiveTree($this->findCanonical($id, lock: true));
        });
    }

    /**
     * @param  array<string, mixed>  $selection
     */
    public function assertCompletePath(array $selection, bool $lock = false): void
    {
        $zoneName = preg_replace('/^zone\s+/i', '', Str::of((string) ($selection['zone'] ?? ''))->squish()->toString());
        $areaName = Str::of((string) ($selection['mainLocation'] ?? ''))->squish()->toString();
        $locationName = Str::of((string) ($selection['subLocation'] ?? ''))->squish()->toString();

        $zone = $this->findCanonicalChild(
            null,
            $zoneName,
            $selection['zoneId'] ?? null,
            $lock,
        );
        if (! $zone) {
            throw new \InvalidArgumentException('Choose a registered Zone.');
        }

        $area = $this->findCanonicalChild(
            $zone->id,
            $areaName,
            $selection['mainLocationId'] ?? null,
            $lock,
        );
        if (! $area) {
            throw new \InvalidArgumentException('Choose a registered Main Location under the selected Zone.');
        }

        $location = $this->findCanonicalChild(
            $area->id,
            $locationName,
            $selection['subLocationId'] ?? null,
            $lock,
        );
        if (! $location) {
            throw new \InvalidArgumentException('Choose a registered Sub-location under the selected Main Location.');
        }
    }

    public function childLevel(?int $parentId): string
    {
        if ($parentId === null) {
            return 'zone';
        }

        try {
            $parent = $this->findCanonical($parentId);
        } catch (ModelNotFoundException) {
            throw new \InvalidArgumentException('Choose an available site-location parent.');
        }

        return match ($this->levelFor($parent)) {
            'zone' => 'area',
            'area' => 'location',
            default => throw new \InvalidArgumentException('Site location hierarchy is limited to three levels.'),
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function formatNode(InspectionLocation $row, ?string $level = null): array
    {
        return [
            'id' => (string) $row->id,
            'parentId' => $row->parent_id === null ? null : (string) $row->parent_id,
            'level' => $level ?? $this->levelFor($row),
            'name' => $row->name,
            'displayName' => $this->displayName($row, $level ?? $this->levelFor($row)),
            'description' => (string) ($row->description ?? ''),
            'iconKey' => (string) ($row->icon_key ?? ''),
            'source' => $row->source,
            'permissions' => ['canEdit' => true, 'canDelete' => true],
            'children' => [],
        ];
    }

    /**
     * @return EloquentCollection<int, InspectionLocation>
     */
    private function canonicalLocations(): EloquentCollection
    {
        return InspectionLocation::query()
            ->where('is_active', true)
            ->whereHas('typeLinks', fn ($query) => $query->where('inspection_type_key', self::FIRE_TYPE_KEY))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    private function validatedParent(string $level, mixed $parentId, bool $lock = false): ?InspectionLocation
    {
        if ($level === 'zone') {
            if ($parentId !== null && $parentId !== '') {
                throw new \InvalidArgumentException('A Zone cannot have a parent.');
            }

            return null;
        }

        if (! is_numeric($parentId)) {
            throw new \InvalidArgumentException(
                $level === 'area' ? 'Choose a saved Zone first.' : 'Choose a saved Area first.'
            );
        }

        try {
            $parent = $this->findCanonical((int) $parentId, $lock);
        } catch (ModelNotFoundException) {
            throw new \InvalidArgumentException(
                $level === 'area' ? 'Choose an available Zone.' : 'Choose an available Area.'
            );
        }
        $expected = $level === 'area' ? 'zone' : 'area';
        if ($this->levelFor($parent) !== $expected) {
            throw new \InvalidArgumentException(
                $level === 'area' ? 'An Area must belong to a Zone.' : 'A Location must belong to an Area.'
            );
        }

        return $parent;
    }

    private function findCanonical(int $id, bool $lock = false): InspectionLocation
    {
        return InspectionLocation::query()
            ->where('is_active', true)
            ->whereHas('typeLinks', fn ($query) => $query->where('inspection_type_key', self::FIRE_TYPE_KEY))
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->findOrFail($id);
    }

    private function findCanonicalChild(?int $parentId, string $name, mixed $id, bool $lock = false): ?InspectionLocation
    {
        $normalized = $this->normalizeName(
            $parentId === null ? $this->canonicalName($name, 'zone') : $name
        );
        $query = InspectionLocation::query()
            ->where('is_active', true)
            ->where('parent_id', $parentId)
            ->where('active_identity_key', InspectionLocation::activeIdentityKey($parentId, $normalized))
            ->whereHas('typeLinks', fn ($linkQuery) => $linkQuery->where('inspection_type_key', self::FIRE_TYPE_KEY))
            ->when($lock, fn ($builder) => $builder->lockForUpdate());

        if ($id !== null && $id !== '') {
            if (! is_numeric($id)) {
                return null;
            }
            $query->whereKey((int) $id);
        }

        return $query->first();
    }

    private function levelFor(InspectionLocation $row): string
    {
        if ($row->parent_id === null) {
            return 'zone';
        }
        $parent = InspectionLocation::query()->find($row->parent_id);
        if (! $parent || $parent->parent_id === null) {
            return 'area';
        }
        if (InspectionLocation::query()->whereKey($parent->parent_id)->whereNotNull('parent_id')->exists()) {
            throw new \DomainException('Site location hierarchy exceeds three levels.');
        }

        return 'location';
    }

    private function linkToSiteTypes(InspectionLocation $row): void
    {
        foreach (self::SITE_TYPES as $typeKey => $typeLabel) {
            InspectionLocationTypeLink::query()->updateOrCreate(
                ['inspection_location_id' => $row->id, 'inspection_type_key' => $typeKey],
                [
                    'inspection_type_label' => $typeLabel,
                    'is_default' => true,
                    'sort_order' => $row->sort_order,
                ]
            );
        }
    }

    private function isCanonical(InspectionLocation $row): bool
    {
        return $row->typeLinks()
            ->where('inspection_type_key', self::FIRE_TYPE_KEY)
            ->exists();
    }

    private function nextSortOrder(?int $parentId): int
    {
        return ((int) InspectionLocation::query()->where('parent_id', $parentId)->max('sort_order')) + 1;
    }

    private function archiveTree(InspectionLocation $row): void
    {
        InspectionLocation::query()
            ->where('parent_id', $row->id)
            ->where('is_active', true)
            ->lockForUpdate()
            ->get()
            ->each(fn (InspectionLocation $child) => $this->archiveTree($child));
        $row->fill(['is_active' => false])->save();
    }

    /**
     * @param  Collection<string, Collection<int, InspectionLocation>>  $rowsByParent
     * @return array<string, mixed>
     */
    private function formatTree(InspectionLocation $row, Collection $rowsByParent, int $depth = 0): array
    {
        if ($depth > 2) {
            throw new \DomainException('Site location hierarchy exceeds three levels.');
        }
        $node = $this->formatNode($row, self::LEVELS[$depth]);
        $node['children'] = ($rowsByParent->get((string) $row->id, collect()) ?? collect())
            ->values()
            ->map(fn (InspectionLocation $child): array => $this->formatTree($child, $rowsByParent, $depth + 1))
            ->all();

        return $node;
    }

    private function displayName(InspectionLocation $row, string $level): string
    {
        if ($level !== 'zone' || ! preg_match('/^\d/', $row->name)) {
            return $row->name;
        }

        return 'Zone '.$row->name;
    }

    private function normalizeName(string $value): string
    {
        return Str::of($value)->squish()->lower()->toString();
    }

    private function canonicalName(string $value, string $level): string
    {
        $name = Str::of($value)->squish()->toString();
        if ($level === 'zone') {
            $name = preg_replace('/^zone\s+/i', '', $name) ?? $name;
        }

        return trim($name);
    }
}
