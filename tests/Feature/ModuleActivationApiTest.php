<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserRoleAssignment;
use App\Services\RoleCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ModuleActivationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_modules_default_to_enabled_when_no_setting_exists(): void
    {
        $this->actingAsUserWithPermissions(['settings.manage']);

        $this->getJson('/api/settings/modules')
            ->assertOk()
            ->assertJsonPath('data.effective.payroll.enabled', true)
            ->assertJsonPath('data.effective.messages.enabled', true)
            ->assertJsonPath('data.fallbackMode', true);
    }

    public function test_authenticated_users_can_read_module_state_without_settings_permission(): void
    {
        $this->actingAsUserWithPermissions(['self.dashboard']);

        $this->getJson('/api/settings/modules')
            ->assertOk()
            ->assertJsonPath('data.effective.payroll.enabled', true);
    }

    public function test_locked_modules_cannot_be_disabled(): void
    {
        $this->actingAsUserWithPermissions(['settings.manage']);

        $response = $this->putJson('/api/settings/modules', [
            'configured' => [
                'settings.module_activation' => false,
                'messages' => false,
            ],
        ])->assertOk();

        $data = $response->json('data');

        $this->assertArrayNotHasKey('settings.module_activation', $data['configured']);
        $this->assertFalse($data['configured']['messages']);
        $this->assertTrue($data['effective']['settings.module_activation']['enabled']);
    }

    public function test_disabling_payroll_blocks_payroll_children(): void
    {
        $this->actingAsUserWithPermissions(['settings.manage']);
        $response = $this->putJson('/api/settings/modules', [
            'configured' => [
                'payroll' => false,
            ],
        ])->assertOk();

        $salaryManagementState = $response->json('data')['effective']['payroll.salary_claims_management'];

        $this->assertFalse($salaryManagementState['enabled']);
        $this->assertSame('parent_disabled', $salaryManagementState['reason']);

        $this->actingAsUserWithPermissions(['self.payroll']);

        $this->getJson('/api/payroll/claims')
            ->assertStatus(403)
            ->assertJsonPath('code', 'MODULE_DISABLED')
            ->assertJsonPath('module', 'payroll.self_service')
            ->assertJsonPath('blocking_module', 'payroll');
    }

    /**
     * @param array<int, string> $permissions
     */
    private function actingAsUserWithPermissions(array $permissions): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $role = Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        $role->syncPermissions($permissions);

        UserRoleAssignment::create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'scope_type' => RoleCatalog::GLOBAL,
            'is_primary' => true,
        ]);

        $this->actingAs($user);

        return $user;
    }
}
