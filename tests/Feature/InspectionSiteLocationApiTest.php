<?php

namespace Tests\Feature;

use App\Models\InspectionLocation;
use App\Models\InspectionLocationTypeLink;
use App\Models\User;
use App\Services\InspectionSiteLocationCatalogService;
use Database\Seeders\InspectionLocationCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class InspectionSiteLocationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_site_catalog_requires_inspection_access(): void
    {
        $this->actingAs(User::factory()->create(['status' => 'active']));
        $this->getJson('/api/inspection/site-locations')->assertForbidden();
    }

    public function test_site_catalog_returns_canonical_zone_area_location_shape(): void
    {
        $this->seed(InspectionLocationCatalogSeeder::class);
        $this->actingAsInspectionUser();

        $response = $this->getJson('/api/inspection/site-locations')->assertOk();
        $zone = collect($response->json('data'))->firstWhere('name', '1');
        $this->assertSame('zone', $zone['level'] ?? null);
        $this->assertSame('Zone 1', $zone['displayName'] ?? null);
        $area = collect($zone['children'] ?? [])->firstWhere('name', 'Manjung Hub');
        $this->assertSame('area', $area['level'] ?? null);
        $location = collect($area['children'] ?? [])->firstWhere('name', 'Reception');
        $this->assertSame('location', $location['level'] ?? null);
        $this->assertSame([], $location['children'] ?? null);
    }

    public function test_user_can_create_partial_and_complete_global_site_branches(): void
    {
        $this->seed(InspectionLocationCatalogSeeder::class);
        $this->actingAsInspectionUser();

        $zone = $this->postJson('/api/inspection/site-locations', [
            'level' => 'zone',
            'name' => 'QA 7',
        ])->assertCreated();
        $zoneId = (int) $zone->json('data.id');

        $area = $this->postJson('/api/inspection/site-locations', [
            'level' => 'area',
            'parentId' => $zoneId,
            'name' => 'QA Workshop',
        ])->assertCreated();
        $areaId = (int) $area->json('data.id');

        $location = $this->postJson('/api/inspection/site-locations', [
            'level' => 'location',
            'parentId' => $areaId,
            'name' => 'Ground Floor',
        ])->assertCreated();

        $this->assertSame('location', $location->json('data.level'));
        foreach (array_keys(InspectionSiteLocationCatalogService::SITE_TYPES) as $typeKey) {
            $this->assertDatabaseHas('inspection_location_type_links', [
                'inspection_location_id' => (int) $location->json('data.id'),
                'inspection_type_key' => $typeKey,
            ]);
        }
        foreach (InspectionSiteLocationCatalogService::SITE_TYPES as $typeKey => $typeLabel) {
            $response = $this->getJson('/api/inspection/location-options?inspectionTypeKey='.$typeKey.'&inspectionType='.urlencode($typeLabel))
                ->assertOk();
            $this->assertContains('Ground Floor', $this->locationNames($response->json('data')));
        }
        $scba = $this->getJson('/api/inspection/location-options?inspectionTypeKey=scba-inspection&inspectionType=SCBA%20Inspection')
            ->assertOk();
        $this->assertNotContains('Ground Floor', $this->locationNames($scba->json('data')));
        $this->assertDatabaseMissing('inspection_location_type_links', [
            'inspection_location_id' => (int) $location->json('data.id'),
            'inspection_type_key' => 'scba-inspection',
        ]);
        $this->assertNotNull(InspectionLocation::query()->findOrFail($zoneId)->active_identity_key);
    }

    public function test_same_area_name_is_allowed_under_different_zones(): void
    {
        $this->seed(InspectionLocationCatalogSeeder::class);
        $this->actingAsInspectionUser();

        $firstZone = $this->postJson('/api/inspection/site-locations', [
            'level' => 'zone',
            'name' => 'QA North',
        ])->assertCreated()->json('data.id');
        $secondZone = $this->postJson('/api/inspection/site-locations', [
            'level' => 'zone',
            'name' => 'QA South',
        ])->assertCreated()->json('data.id');

        foreach ([$firstZone, $secondZone] as $zoneId) {
            $this->postJson('/api/inspection/site-locations', [
                'level' => 'area',
                'parentId' => $zoneId,
                'name' => 'Workshop',
            ])->assertCreated();
        }

        $this->assertSame(2, InspectionLocation::query()
            ->where('normalized_name', 'workshop')
            ->whereIn('parent_id', [$firstZone, $secondZone])
            ->where('is_active', true)
            ->count());
    }

    public function test_duplicate_and_invalid_parent_responses_are_structured(): void
    {
        $this->seed(InspectionLocationCatalogSeeder::class);
        $this->actingAsInspectionUser();
        $zone = InspectionLocation::query()
            ->whereNull('parent_id')
            ->where('name', '1')
            ->firstOrFail();

        $this->postJson('/api/inspection/site-locations', [
            'level' => 'area',
            'parentId' => $zone->id,
            'name' => 'manjung hub',
        ])->assertConflict()
            ->assertJsonPath('code', 'SITE_LOCATION_ALREADY_EXISTS')
            ->assertJsonPath('data.existing.name', 'Manjung Hub');

        $this->postJson('/api/inspection/site-locations', [
            'level' => 'location',
            'parentId' => $zone->id,
            'name' => 'Invalid child',
        ])->assertUnprocessable()->assertJsonValidationErrors(['parentId']);

        $this->postJson('/api/inspection/site-locations', [
            'level' => 'area',
            'parentId' => 999999,
            'name' => 'Missing parent',
        ])->assertUnprocessable()->assertJsonValidationErrors(['parentId']);

        $this->postJson('/api/inspection/site-locations', [
            'level' => 'zone',
            'name' => 'Zone 1',
        ])->assertConflict()
            ->assertJsonPath('code', 'SITE_LOCATION_ALREADY_EXISTS')
            ->assertJsonPath('data.existing.id', (string) $zone->id);
    }

    public function test_rename_and_archive_apply_to_the_global_tree(): void
    {
        $this->seed(InspectionLocationCatalogSeeder::class);
        $this->actingAsInspectionUser();
        $location = InspectionLocation::query()->where('name', 'Reception')->firstOrFail();
        $location->update(['description' => 'Keep this metadata.', 'icon_key' => 'pin']);

        $this->patchJson('/api/inspection/site-locations/'.$location->id, [
            'name' => 'Reception Desk',
        ])->assertOk()->assertJsonPath('data.name', 'Reception Desk');
        $this->assertSame('Keep this metadata.', $location->fresh()->description);
        $this->assertSame('pin', $location->fresh()->icon_key);

        $this->deleteJson('/api/inspection/site-locations/'.$location->id)->assertNoContent();
        $this->assertFalse($location->fresh()->is_active);
        $this->assertNull($location->fresh()->active_identity_key);
    }

    public function test_site_create_does_not_adopt_an_unrelated_catalogue_root(): void
    {
        $this->seed(InspectionLocationCatalogSeeder::class);
        $this->actingAsInspectionUser();
        $root = InspectionLocation::query()->create([
            'name' => 'Independent Store',
            'normalized_name' => 'independent store',
            'source' => 'custom',
            'is_active' => true,
            'sort_order' => 999,
        ]);
        InspectionLocationTypeLink::query()->create([
            'inspection_location_id' => $root->id,
            'inspection_type_key' => 'scba-inspection',
            'inspection_type_label' => 'SCBA Inspection',
            'is_default' => true,
            'sort_order' => 999,
        ]);

        $this->postJson('/api/inspection/site-locations', [
            'level' => 'zone',
            'name' => 'Independent Store',
        ])->assertConflict()->assertJsonPath('code', 'SITE_LOCATION_SCOPE_CONFLICT');

        $this->assertDatabaseMissing('inspection_location_type_links', [
            'inspection_location_id' => $root->id,
            'inspection_type_key' => InspectionSiteLocationCatalogService::FIRE_TYPE_KEY,
        ]);
    }

    private function actingAsInspectionUser(): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $permission = Permission::query()->firstOrCreate([
            'name' => 'reports.inspection.view',
            'guard_name' => 'web',
        ]);
        $role = Role::query()->firstOrCreate([
            'name' => 'Inspection Site Location Tester',
            'guard_name' => 'web',
        ]);
        if (! $role->hasPermissionTo($permission)) {
            $role->givePermissionTo($permission);
        }
        $user->assignRole($role);
        $this->actingAs($user);

        return $user;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, string>
     */
    private function locationNames(array $rows): array
    {
        $names = [];
        foreach ($rows as $row) {
            $names[] = (string) ($row['name'] ?? $row['value'] ?? '');
            $names = [...$names, ...$this->locationNames($row['children'] ?? $row['subLocations'] ?? [])];
        }

        return $names;
    }
}
