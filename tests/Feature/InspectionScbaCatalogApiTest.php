<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\InspectionScbaCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class InspectionScbaCatalogApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeded_scba_catalog_is_visible_but_only_report_managers_can_edit_it(): void
    {
        $this->seed(InspectionScbaCatalogSeeder::class);
        $viewer = $this->actingAsWithPermission('reports.inspection.view');

        $catalog = $this->getJson('/api/inspection/scba-catalog')->assertOk();
        $catalog->assertJsonCount(3, 'data');
        $catalog->assertJsonPath('data.0.key', 'backPlate');
        $catalog->assertJsonPath('data.0.source', 'seed');
        $catalog->assertJsonPath('data.0.canEdit', false);
        $this->assertNotEmpty($catalog->json('data.0.rows'));

        $this->postJson('/api/inspection/scba-catalog/sections', [
            'title' => 'Regulator',
            'fields' => [['key' => 'regulatorCondition', 'label' => 'Regulator', 'kind' => 'status']],
        ])->assertForbidden();

        $manager = User::factory()->create(['status' => 'active']);
        $this->grantPermission($manager, 'reports.manage');
        $this->actingAs($manager);

        $this->getJson('/api/inspection/scba-catalog')
            ->assertOk()
            ->assertJsonPath('data.0.canEdit', true);

        $this->postJson('/api/inspection/scba-catalog/sections', [
            'title' => 'Regulator',
            'fields' => [['key' => 'regulatorCondition', 'label' => 'Regulator', 'kind' => 'status']],
        ])->assertCreated()->assertJsonPath('data.title', 'Regulator');

        $this->assertSame($viewer->id, $viewer->fresh()->id);
    }

    private function actingAsWithPermission(string $permissionName): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->grantPermission($user, $permissionName);
        $this->actingAs($user);

        return $user;
    }

    private function grantPermission(User $user, string $permissionName): void
    {
        $permission = Permission::query()->firstOrCreate(['name' => $permissionName, 'guard_name' => 'web']);
        $role = Role::query()->firstOrCreate(['name' => 'SCBA Catalog Tester', 'guard_name' => 'web']);
        if (! $role->hasPermissionTo($permission)) {
            $role->givePermissionTo($permission);
        }
        $user->assignRole($role);
    }
}
