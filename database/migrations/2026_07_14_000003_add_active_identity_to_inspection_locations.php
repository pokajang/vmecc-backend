<?php

use Database\Seeders\InspectionLocationCatalogSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const FIRE_TYPE = 'fire-extinguisher-inspection';

    private const SITE_TYPES = [
        'fire-extinguisher-inspection' => 'Fire Extinguisher Inspection',
        'general-inspection' => 'General Inspection',
        'health-safety-environment-inspection' => 'Health Safety Environment Inspection',
    ];

    public function up(): void
    {
        $this->assertNoUnmappedCustomSiteNodes();
        app(InspectionLocationCatalogSeeder::class)->run();
        $this->linkCanonicalTreeToSiteTypes();
        $this->assertNoDuplicateActiveIdentities();

        Schema::table('inspection_locations', function (Blueprint $table) {
            $table->string('active_identity_key', 64)->nullable()->after('parent_id');
        });

        DB::table('inspection_locations')
            ->where('is_active', true)
            ->orderBy('id')
            ->eachById(function (object $row): void {
                DB::table('inspection_locations')
                    ->where('id', $row->id)
                    ->update([
                        'active_identity_key' => hash(
                            'sha256',
                            (($row->parent_id ?? null) ?: 'root').'|'.$this->identityName($row)
                        ),
                    ]);
            });

        Schema::table('inspection_locations', function (Blueprint $table) {
            $table->unique(
                'active_identity_key',
                'inspection_locations_active_identity_unique'
            );
        });

    }

    public function down(): void
    {
        Schema::table('inspection_locations', function (Blueprint $table) {
            $table->dropUnique('inspection_locations_active_identity_unique');
            $table->dropColumn('active_identity_key');
        });
    }

    private function assertNoUnmappedCustomSiteNodes(): void
    {
        $fireIds = DB::table('inspection_location_type_links as links')
            ->join('inspection_locations as locations', 'locations.id', '=', 'links.inspection_location_id')
            ->where('inspection_type_key', self::FIRE_TYPE)
            ->where('locations.is_active', true)
            ->pluck('links.inspection_location_id');
        $fireIdSet = $fireIds->mapWithKeys(fn ($id): array => [(int) $id => true]);

        $ambiguous = DB::table('inspection_locations as locations')
            ->join(
                'inspection_location_type_links as links',
                'links.inspection_location_id',
                '=',
                'locations.id'
            )
            ->whereIn('links.inspection_type_key', [
                'general-inspection',
                'health-safety-environment-inspection',
            ])
            ->where('locations.source', 'custom')
            ->where('locations.is_active', true)
            ->select(['locations.id', 'locations.parent_id', 'locations.name'])
            ->distinct()
            ->get()
            ->filter(fn (object $row): bool => ! $this->hasCanonicalFirePath($row, $fireIdSet));

        if ($ambiguous->isEmpty()) {
            return;
        }

        $summary = $ambiguous
            ->map(fn (object $row): string => "{$row->id}:".$this->locationPath($row))
            ->implode(', ');

        throw new RuntimeException(
            'Cannot reconcile site locations: custom General/HSE nodes need a canonical Zone path: '.$summary
        );
    }

    private function assertNoDuplicateActiveIdentities(): void
    {
        $duplicates = DB::table('inspection_locations')
            ->where('is_active', true)
            ->get(['id', 'parent_id', 'normalized_name'])
            ->groupBy(fn (object $row): string => (($row->parent_id ?? null) ?: 'root').'|'.$this->identityName($row))
            ->filter(fn (Collection $rows): bool => $rows->count() > 1);

        if ($duplicates->isEmpty()) {
            return;
        }

        $summary = $duplicates
            ->map(fn (Collection $rows, string $identity): string => $identity.' ['.$rows->pluck('id')->implode(', ').']')
            ->values()
            ->implode('; ');

        throw new RuntimeException(
            'Cannot add site-location identity constraint: duplicate active parent/name rows exist: '.$summary
        );
    }

    private function identityName(object $row): string
    {
        $name = trim((string) $row->normalized_name);
        if ($row->parent_id === null) {
            $name = preg_replace('/^zone\s+/i', '', $name) ?? $name;
        }

        return $name;
    }

    private function locationPath(object $row): string
    {
        $parts = [(string) $row->name];
        $parentId = $row->parent_id;
        while ($parentId !== null) {
            $parent = DB::table('inspection_locations')->where('id', $parentId)->first();
            if (! $parent) {
                break;
            }
            array_unshift($parts, (string) $parent->name);
            $parentId = $parent->parent_id;
        }

        return implode(' > ', $parts);
    }

    private function hasCanonicalFirePath(object $row, Collection $fireIdSet): bool
    {
        $current = $row;
        $depth = 0;
        while ($current) {
            if (! $fireIdSet->has((int) $current->id) || $depth > 2) {
                return false;
            }
            if ($current->parent_id === null) {
                return true;
            }
            $current = DB::table('inspection_locations')->where('id', $current->parent_id)->first();
            $depth++;
        }

        return false;
    }

    private function linkCanonicalTreeToSiteTypes(): void
    {
        $canonicalIds = DB::table('inspection_location_type_links as links')
            ->join('inspection_locations as locations', 'locations.id', '=', 'links.inspection_location_id')
            ->where('inspection_type_key', self::FIRE_TYPE)
            ->where('locations.is_active', true)
            ->pluck('links.inspection_location_id');
        $now = now();

        foreach (self::SITE_TYPES as $typeKey => $typeLabel) {
            $existing = DB::table('inspection_location_type_links')
                ->where('inspection_type_key', $typeKey)
                ->whereIn('inspection_location_id', $canonicalIds)
                ->pluck('inspection_location_id')
                ->flip();
            $rows = [];
            foreach ($canonicalIds as $index => $locationId) {
                if ($existing->has($locationId)) {
                    continue;
                }
                $rows[] = [
                    'inspection_location_id' => $locationId,
                    'inspection_type_key' => $typeKey,
                    'inspection_type_label' => $typeLabel,
                    'is_default' => true,
                    'sort_order' => $index + 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            if ($rows !== []) {
                DB::table('inspection_location_type_links')->insert($rows);
            }
        }
    }
};
