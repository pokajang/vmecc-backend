<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class InspectionFireTruckApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_archived_plate_is_reported_as_a_validation_error_instead_of_a_server_error(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->grantPermission($user, 'reports.inspection.view');
        $this->actingAs($user);

        $created = $this->postJson('/api/inspection/fire-trucks', [
            'plateNo' => 'SMOKE-TRUCK-01',
            'name' => 'Smoke Truck',
        ])->assertCreated();

        $this->deleteJson('/api/inspection/fire-trucks/'.$created->json('data.id'))
            ->assertNoContent();

        $this->postJson('/api/inspection/fire-trucks', [
            'plateNo' => 'smoke truck 01',
            'name' => 'Replacement Smoke Truck',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['plateNo']);

        $second = $this->postJson('/api/inspection/fire-trucks', [
            'plateNo' => 'SMOKE-TRUCK-02',
            'name' => 'Second Smoke Truck',
        ])->assertCreated();

        $this->patchJson('/api/inspection/fire-trucks/'.$second->json('data.id'), [
            'plateNo' => 'SMOKE TRUCK 01',
            'name' => 'Second Smoke Truck',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['plateNo']);
    }

    private function grantPermission(User $user, string $permissionName): void
    {
        $permission = Permission::query()->firstOrCreate([
            'name' => $permissionName,
            'guard_name' => 'web',
        ]);
        $role = Role::query()->firstOrCreate([
            'name' => 'Inspection Fire Truck Catalog Tester',
            'guard_name' => 'web',
        ]);
        if (! $role->hasPermissionTo($permission)) {
            $role->givePermissionTo($permission);
        }
        $user->assignRole($role);
    }
}
