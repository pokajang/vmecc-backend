<?php

namespace Tests\Feature;

use App\Models\InspectionEquipment;
use App\Models\User;
use Database\Seeders\InspectionEquipmentCatalogSeeder;
use Database\Seeders\InspectionErAuxEquipmentCatalogSeeder;
use Database\Seeders\InspectionHighAngleCatalogSeeder;
use Database\Seeders\InspectionLocationCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class InspectionEquipmentCatalogApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_equipment_catalog_requires_inspection_permission(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->actingAs($user);

        $this->getJson('/api/inspection/equipment-options?inspectionType=Hydraulic%20Rescue%20Tools%20Inspection&mainLocation=FRT')
            ->assertStatus(403);
    }

    public function test_seeded_hydraulic_equipment_returns_by_main_location(): void
    {
        $this->seedHydraulicCatalog();
        $user = User::factory()->create(['status' => 'active']);
        $this->grantPermission($user, 'reports.inspection.view');
        $this->actingAs($user);

        $response = $this->getJson('/api/inspection/equipment-options?inspectionType=Hydraulic%20Rescue%20Tools%20Inspection&mainLocation=FRT');

        $response->assertOk();
        $response->assertJsonPath('meta.source', 'database');
        $titles = collect($response->json('data'))->pluck('title')->all();
        $this->assertContains('Hydraulic Pump Motor 1', $titles);
        $this->assertNotContains('Hydraulic Pump Motor 2', $titles);
        $pump = collect($response->json('data'))->firstWhere('title', 'Hydraulic Pump Motor 1');
        $this->assertSame(false, $pump['canEdit'] ?? null);
        $this->assertSame(false, $pump['canDelete'] ?? null);
    }

    public function test_report_manager_can_create_update_and_delete_custom_equipment(): void
    {
        $this->seedHydraulicCatalog();
        $user = User::factory()->create(['status' => 'active']);
        $this->grantPermission($user, 'reports.manage');
        $this->actingAs($user);

        $created = $this->postJson('/api/inspection/equipment', [
            'inspectionType' => 'Hydraulic Rescue Tools Inspection',
            'mainLocation' => 'FRT',
            'name' => 'Hydraulic Ram Extension',
            'description' => 'Stored with FRT tools.',
        ]);
        $created->assertCreated();
        $created->assertJsonPath('data.title', 'Hydraulic Ram Extension');
        $created->assertJsonPath('data.equipmentSource', 'custom');
        $equipmentId = (int) $created->json('data.id');

        $updated = $this->patchJson("/api/inspection/equipment/{$equipmentId}", [
            'name' => 'Hydraulic Ram Extension Kit',
            'description' => 'Updated.',
        ]);
        $updated->assertOk();
        $updated->assertJsonPath('data.title', 'Hydraulic Ram Extension Kit');

        $catalog = $this->getJson('/api/inspection/equipment-options?inspectionType=Hydraulic%20Rescue%20Tools%20Inspection&mainLocation=FRT');
        $catalog->assertOk();
        $this->assertContains('Hydraulic Ram Extension Kit', collect($catalog->json('data'))->pluck('title')->all());

        $this->deleteJson("/api/inspection/equipment/{$equipmentId}")->assertNoContent();
        $afterDelete = $this->getJson('/api/inspection/equipment-options?inspectionType=Hydraulic%20Rescue%20Tools%20Inspection&mainLocation=FRT');
        $this->assertNotContains('Hydraulic Ram Extension Kit', collect($afterDelete->json('data'))->pluck('title')->all());
    }

    public function test_equipment_catalog_rejects_duplicates_under_same_main_location(): void
    {
        $this->seedHydraulicCatalog();
        $user = User::factory()->create(['status' => 'active']);
        $this->grantPermission($user, 'reports.manage');
        $this->actingAs($user);

        $this->postJson('/api/inspection/equipment', [
            'inspectionType' => 'Hydraulic Rescue Tools Inspection',
            'mainLocation' => 'FRT',
            'name' => 'Hydraulic Temporary Tool',
        ])->assertCreated();

        $this->postJson('/api/inspection/equipment', [
            'inspectionType' => 'Hydraulic Rescue Tools Inspection',
            'mainLocation' => 'FRT',
            'name' => 'Hydraulic Temporary Tool',
        ])->assertStatus(422)->assertJsonValidationErrors(['name']);
    }

    public function test_equipment_catalog_derives_label_when_only_type_key_is_sent(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->grantPermission($user, 'reports.manage');
        $this->actingAs($user);

        $created = $this->postJson('/api/inspection/equipment', [
            'inspectionTypeKey' => 'future-inspection-type',
            'main_location' => 'Office',
            'name' => 'Future Tool',
        ]);

        $created->assertCreated();
        $created->assertJsonPath('data.inspectionTypeKey', 'future-inspection-type');
        $created->assertJsonPath('data.inspectionType', 'Future Inspection Type');
        $created->assertJsonPath('data.mainLocation', 'Office');
    }

    public function test_report_manager_can_persist_supported_equipment_metadata(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->grantPermission($user, 'reports.manage');
        $this->actingAs($user);

        $created = $this->postJson('/api/inspection/equipment', [
            'inspectionTypeKey' => 'high-angle-rescue-equipment-inspection',
            'mainLocation' => 'Rescue Store',
            'name' => 'Rescue Tripod',
            'metadata' => [
                'storageLocation' => 'Bay 4',
                'quantity' => '2',
                'ignored' => 'not persisted',
            ],
        ])->assertCreated();

        $equipmentId = (int) $created->json('data.id');
        $created->assertJsonPath('data.metadata.storageLocation', 'Bay 4');
        $created->assertJsonPath('data.metadata.quantity', '2');
        $this->assertArrayNotHasKey('ignored', $created->json('data.metadata'));

        $this->patchJson("/api/inspection/equipment/{$equipmentId}", [
            'name' => 'Rescue Tripod Kit',
            'metadata' => ['compartment' => 'Locker A'],
        ])->assertOk()
            ->assertJsonPath('data.metadata.compartment', 'Locker A');

        $equipment = InspectionEquipment::query()->findOrFail($equipmentId);
        $this->assertSame(['compartment' => 'Locker A'], $equipment->metadata);
        $this->assertSame($user->id, $equipment->updated_by);
    }

    public function test_high_angle_and_er_aux_workbook_catalogs_seed_with_metadata(): void
    {
        $this->seed(InspectionLocationCatalogSeeder::class);
        $this->seed([InspectionHighAngleCatalogSeeder::class, InspectionErAuxEquipmentCatalogSeeder::class]);

        $this->assertSame(111, InspectionEquipment::query()->where('inspection_type_key', 'high-angle-rescue-equipment-inspection')->where('source', 'seed')->count());
        $this->assertSame(31, InspectionEquipment::query()->where('inspection_type_key', 'er-aux-equipment-inspection')->where('source', 'seed')->count());

        $rope = InspectionEquipment::query()->where('inspection_type_key', 'high-angle-rescue-equipment-inspection')->where('name', 'Gotcha Rope')->firstOrFail();
        $this->assertSame('111', $rope->metadata['workbookRowNumber']);
        $this->assertSame('0', $rope->metadata['quantity']);

        $radio = InspectionEquipment::query()->where('inspection_type_key', 'er-aux-equipment-inspection')->where('name', 'Radio Tetra')->firstOrFail();
        $this->assertSame('27', $radio->metadata['workbookRowNumber']);
        $this->assertSame('Motorola MTP3500', $radio->description);
    }

    public function test_regular_inspection_user_cannot_mutate_catalog_equipment(): void
    {
        $this->seedHydraulicCatalog();
        $user = User::factory()->create(['status' => 'active']);
        $this->grantPermission($user, 'reports.inspection.view');
        $this->actingAs($user);

        $seed = InspectionEquipment::query()
            ->where('source', 'seed')
            ->where('name', 'Hydraulic Pump Motor 1')
            ->firstOrFail();

        $this->deleteJson("/api/inspection/equipment/{$seed->id}")
            ->assertForbidden();
    }

    public function test_report_manager_can_edit_seeded_equipment(): void
    {
        $this->seedHydraulicCatalog();
        $user = User::factory()->create(['status' => 'active']);
        $this->grantPermission($user, 'reports.manage');
        $this->actingAs($user);

        $catalog = $this->getJson('/api/inspection/equipment-options?inspectionType=Hydraulic%20Rescue%20Tools%20Inspection&mainLocation=FRT');
        $catalog->assertOk();
        $pump = collect($catalog->json('data'))->firstWhere('title', 'Hydraulic Pump Motor 1');
        $this->assertSame(true, $pump['canEdit'] ?? null);
        $this->assertSame(true, $pump['canDelete'] ?? null);

        $equipmentId = (int) ($pump['id'] ?? 0);
        $this->patchJson("/api/inspection/equipment/{$equipmentId}", [
            'name' => 'Hydraulic Pump Motor 1A',
            'description' => 'Updated seeded pump.',
        ])
            ->assertOk()
            ->assertJsonPath('data.title', 'Hydraulic Pump Motor 1A')
            ->assertJsonPath('data.equipmentSource', 'seed')
            ->assertJsonPath('data.canEdit', true);
    }

    private function seedHydraulicCatalog(): void
    {
        $this->seed(InspectionLocationCatalogSeeder::class);
        $this->seed(InspectionEquipmentCatalogSeeder::class);
    }

    private function grantPermission(User $user, string $permissionName): void
    {
        $permission = Permission::query()->firstOrCreate([
            'name' => $permissionName,
            'guard_name' => 'web',
        ]);
        $role = Role::query()->firstOrCreate([
            'name' => 'Inspection Equipment Catalog Tester',
            'guard_name' => 'web',
        ]);
        if (! $role->hasPermissionTo($permission)) {
            $role->givePermissionTo($permission);
        }
        $user->assignRole($role);
    }
}
