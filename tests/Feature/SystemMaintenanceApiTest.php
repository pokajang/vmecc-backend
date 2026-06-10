<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\RoleCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SystemMaintenanceApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_returns_default_when_setting_is_missing(): void
    {
        $user = User::factory()->create(['status' => 'Active']);

        $response = $this->actingAs($user)
            ->getJson('/api/settings/system-maintenance')
            ->assertOk();

        $data = $response->json('data');
        $this->assertSame(false, $data['enabled']);
        $this->assertSame('off', $data['phase']);
        $this->assertNull($data['graceEndsAt']);
        $this->assertSame('System is under maintenance. Please try again later.', $data['message']);
        $this->assertSame('', $data['updatedAt']);
        $this->assertNull($data['updatedByUserId']);
    }

    public function test_post_persists_and_get_returns_same_setting(): void
    {
        $admin = User::factory()->create(['status' => 'Active']);
        $this->grantPermission($admin, 'settings.manage');

        $payload = [
            'enabled' => true,
            'message' => 'Planned maintenance in progress.',
            'updatedAt' => now()->toIso8601String(),
        ];

        $this->actingAs($admin)
            ->postJson('/api/settings/system-maintenance', $payload)
            ->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.phase', 'grace')
            ->assertJsonPath('data.message', 'Planned maintenance in progress.')
            ->assertJsonPath('data.updatedByUserId', $admin->id);

        $response = $this->actingAs($admin)
            ->getJson('/api/settings/system-maintenance');
        $response->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.phase', 'grace')
            ->assertJsonPath('data.message', 'Planned maintenance in progress.')
            ->assertJsonPath('data.updatedByUserId', $admin->id);
        $this->assertNotEmpty($response->json('data.graceEndsAt'));
    }

    public function test_non_settings_manager_cannot_post_setting(): void
    {
        $user = User::factory()->create(['status' => 'Active']);

        $this->actingAs($user)
            ->postJson('/api/settings/system-maintenance', [
                'enabled' => true,
                'message' => 'Blocked update',
            ])
            ->assertForbidden();
    }

    public function test_non_sysadmin_receives_503_on_private_api_when_enforced(): void
    {
        Setting::create([
            'key' => 'system_maintenance',
            'value' => [
                'enabled' => true,
                'phase' => 'enforced',
                'graceEndsAt' => null,
                'message' => 'Maintenance lock',
                'updatedAt' => now()->toIso8601String(),
                'updatedByUserId' => null,
            ],
        ]);

        $user = User::factory()->create(['status' => 'Active']);

        $this->actingAs($user)
            ->putJson('/api/profile', ['name' => 'Updated Name'])
            ->assertStatus(503)
            ->assertJsonPath('code', 'SYSTEM_MAINTENANCE')
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.phase', 'enforced')
            ->assertJsonPath('message', 'Maintenance lock');
    }

    public function test_sysadmin_bypasses_maintenance_lock(): void
    {
        Setting::create([
            'key' => 'system_maintenance',
            'value' => [
                'enabled' => true,
                'phase' => 'enforced',
                'graceEndsAt' => null,
                'message' => 'Maintenance lock',
                'updatedAt' => now()->toIso8601String(),
                'updatedByUserId' => null,
            ],
        ]);

        $user = User::factory()->create(['status' => 'Active']);
        $role = Role::query()->firstOrCreate([
            'name' => 'System Administrator',
            'guard_name' => 'web',
        ]);
        $permissions = collect(RoleCatalog::allPermissions())
            ->map(fn (string $name) => Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']))
            ->pluck('name')
            ->values()
            ->all();
        $role->syncPermissions($permissions);
        $user->assignRole($role);

        $this->actingAs($user)
            ->putJson('/api/profile', ['name' => 'Updated Name'])
            ->assertOk();
    }

    public function test_allowlisted_maintenance_read_endpoint_remains_reachable_when_enabled(): void
    {
        Setting::create([
            'key' => 'system_maintenance',
            'value' => [
                'enabled' => true,
                'phase' => 'enforced',
                'graceEndsAt' => null,
                'message' => 'Maintenance lock',
                'updatedAt' => now()->toIso8601String(),
                'updatedByUserId' => null,
            ],
        ]);

        $user = User::factory()->create(['status' => 'Active']);

        $this->actingAs($user)
            ->getJson('/api/settings/system-maintenance')
            ->assertOk()
            ->assertJsonPath('data.enabled', true);
    }

    public function test_non_sysadmin_can_access_private_api_during_grace_period(): void
    {
        Setting::create([
            'key' => 'system_maintenance',
            'value' => [
                'enabled' => true,
                'phase' => 'grace',
                'graceEndsAt' => now()->addMinutes(3)->toIso8601String(),
                'message' => 'Grace mode',
                'updatedAt' => now()->toIso8601String(),
                'updatedByUserId' => null,
            ],
        ]);

        $user = User::factory()->create(['status' => 'Active']);

        $this->actingAs($user)
            ->putJson('/api/profile', ['name' => 'Grace Allowed'])
            ->assertOk();
    }

    public function test_expired_grace_auto_transitions_to_enforced_and_blocks_non_sysadmin(): void
    {
        Setting::create([
            'key' => 'system_maintenance',
            'value' => [
                'enabled' => true,
                'phase' => 'grace',
                'graceEndsAt' => now()->subMinute()->toIso8601String(),
                'message' => 'Grace expired',
                'updatedAt' => now()->subMinute()->toIso8601String(),
                'updatedByUserId' => null,
            ],
        ]);

        $user = User::factory()->create(['status' => 'Active']);

        $this->actingAs($user)
            ->putJson('/api/profile', ['name' => 'Should be blocked'])
            ->assertStatus(503)
            ->assertJsonPath('code', 'SYSTEM_MAINTENANCE')
            ->assertJsonPath('data.phase', 'enforced');

        $setting = Setting::query()->where('key', 'system_maintenance')->first();
        $value = $setting?->value ?? [];
        $this->assertSame('enforced', $value['phase'] ?? null);
        $this->assertNull($value['graceEndsAt'] ?? null);
    }

    public function test_non_sysadmin_can_access_private_api_when_maintenance_disabled(): void
    {
        Setting::create([
            'key' => 'system_maintenance',
            'value' => [
                'enabled' => false,
                'phase' => 'off',
                'graceEndsAt' => null,
                'message' => 'Maintenance lock',
                'updatedAt' => now()->toIso8601String(),
                'updatedByUserId' => null,
            ],
        ]);

        $user = User::factory()->create(['status' => 'Active']);

        $this->actingAs($user)
            ->putJson('/api/profile', ['name' => 'Normal Mode'])
            ->assertOk();
    }

    private function grantPermission(User $user, string $permissionName): void
    {
        $permission = Permission::query()->firstOrCreate([
            'name' => $permissionName,
            'guard_name' => 'web',
        ]);
        $user->givePermissionTo($permission);
    }
}
