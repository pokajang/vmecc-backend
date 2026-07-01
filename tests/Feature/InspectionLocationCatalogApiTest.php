<?php

namespace Tests\Feature;

use App\Models\InspectionLocation;
use App\Models\User;
use Database\Seeders\InspectionLocationCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class InspectionLocationCatalogApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_requires_inspection_permission(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->actingAs($user);

        $this->getJson('/api/inspection/location-options?inspectionType=Fire%20Extinguisher%20Inspection')
            ->assertStatus(403);
    }

    public function test_seeded_catalog_returns_hierarchical_locations_by_inspection_type(): void
    {
        $this->seed(InspectionLocationCatalogSeeder::class);
        $user = User::factory()->create(['status' => 'active']);
        $this->grantPermission($user, 'reports.inspection.view');
        $this->actingAs($user);

        $response = $this->getJson('/api/inspection/location-options?inspectionType=Fire%20Extinguisher%20Inspection');

        $response->assertOk();
        $response->assertJsonPath('meta.source', 'database');
        $response->assertJsonFragment(['title' => 'Manjung Hub']);
        $manjungHub = collect($response->json('data'))->firstWhere('title', 'Manjung Hub');
        $this->assertContains(
            'Reception',
            collect($manjungHub['subLocations'] ?? [])->pluck('title')->all()
        );
    }

    public function test_user_can_create_custom_main_and_sub_location(): void
    {
        $this->seed(InspectionLocationCatalogSeeder::class);
        $user = User::factory()->create(['status' => 'active']);
        $this->grantPermission($user, 'reports.inspection.view');
        $this->actingAs($user);

        $main = $this->postJson('/api/inspection/locations', [
            'inspectionType' => 'General Inspection',
            'name' => 'Crusher Bay',
            'description' => 'Primary crusher zone.',
        ]);
        $main->assertCreated();
        $main->assertJsonPath('data.title', 'Crusher Bay');
        $mainId = (int) $main->json('data.id');

        $sub = $this->postJson('/api/inspection/locations', [
            'inspectionType' => 'General Inspection',
            'parentId' => $mainId,
            'name' => 'North Platform',
        ]);
        $sub->assertCreated();
        $sub->assertJsonPath('data.parentId', $mainId);

        $catalog = $this->getJson('/api/inspection/location-options?inspectionType=General%20Inspection');
        $catalog->assertOk();
        $created = collect($catalog->json('data'))->firstWhere('title', 'Crusher Bay');
        $this->assertSame('North Platform', $created['subLocations'][0]['title'] ?? null);
    }

    public function test_shared_main_location_does_not_leak_other_type_sub_locations(): void
    {
        $this->seed(InspectionLocationCatalogSeeder::class);
        $user = User::factory()->create(['status' => 'active']);
        $this->grantPermission($user, 'reports.inspection.view');
        $this->actingAs($user);

        $frt = $this->getJson('/api/inspection/location-options?inspectionType=FRT%20Daily%20Inspection');
        $frt->assertOk();
        $fireTruck = collect($frt->json('data'))->firstWhere('title', 'FIRE TRUCK');
        $this->assertSame('FIRE TRUCK', $fireTruck['title'] ?? null);
        $this->assertSame([], $fireTruck['subLocations'] ?? []);

        $general = $this->getJson('/api/inspection/location-options?inspectionType=General%20Inspection');
        $general->assertOk();
        $generalFireTruck = collect($general->json('data'))->firstWhere('title', 'FIRE TRUCK');
        $this->assertNotNull($generalFireTruck);
        $this->assertNotContains('TRUCK CHECKLIST', collect($generalFireTruck['subLocations'] ?? [])->pluck('title')->all());
    }

    public function test_high_angle_catalog_returns_workbook_kit_order_without_site_sub_locations(): void
    {
        $this->seed(InspectionLocationCatalogSeeder::class);
        $user = User::factory()->create(['status' => 'active']);
        $this->grantPermission($user, 'reports.inspection.view');
        $this->actingAs($user);

        $response = $this->getJson('/api/inspection/location-options?inspectionType=High%20Angle%20Rescue%20Equipment%20Inspection');

        $response->assertOk();
        $response->assertJsonPath('meta.source', 'database');

        $rows = collect($response->json('data'));
        $this->assertSame([
            'Response Kit #1',
            'Response Kit #2',
            'Response Kit #3',
            'Stretcher Response Kit',
            'PPE and Auxillary Kit',
            'Arizona Vortex Tripod Kits',
            'Rescue Rope',
        ], $rows->pluck('title')->all());
        $this->assertTrue($rows->every(fn (array $row): bool => empty($row['subLocations'] ?? [])));
    }

    public function test_catalog_rejects_duplicates_under_same_parent(): void
    {
        $this->seed(InspectionLocationCatalogSeeder::class);
        $user = User::factory()->create(['status' => 'active']);
        $this->grantPermission($user, 'reports.inspection.view');
        $this->actingAs($user);

        $first = $this->postJson('/api/inspection/locations', [
            'inspectionType' => 'General Inspection',
            'name' => 'Temporary Yard',
        ]);
        $first->assertCreated();

        $this->postJson('/api/inspection/locations', [
            'inspectionType' => 'General Inspection',
            'name' => 'Temporary Yard',
        ])->assertStatus(422)->assertJsonValidationErrors(['name']);
    }

    public function test_seeded_location_cannot_be_archived_by_regular_inspection_user(): void
    {
        $this->seed(InspectionLocationCatalogSeeder::class);
        $user = User::factory()->create(['status' => 'active']);
        $this->grantPermission($user, 'reports.inspection.view');
        $this->actingAs($user);

        $seed = InspectionLocation::query()
            ->where('source', 'seed')
            ->where('name', 'Manjung Hub')
            ->firstOrFail();

        $this->deleteJson("/api/inspection/locations/{$seed->id}")
            ->assertStatus(403)
            ->assertJsonPath('code', 'INSPECTION_LOCATION_SEED_PROTECTED');
    }

    private function grantPermission(User $user, string $permissionName): void
    {
        $permission = Permission::query()->firstOrCreate([
            'name' => $permissionName,
            'guard_name' => 'web',
        ]);
        $role = Role::query()->firstOrCreate([
            'name' => 'Inspection Location Catalog Tester',
            'guard_name' => 'web',
        ]);
        if (! $role->hasPermissionTo($permission)) {
            $role->givePermissionTo($permission);
        }
        $user->assignRole($role);
    }
}
