<?php

namespace Tests\Feature;

use App\Models\CustomShift;
use App\Models\User;
use App\Models\UserRoleAssignment;
use App\Services\RoleCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SettingsControllerTest extends TestCase
{
    use RefreshDatabase;

    // ─── Helpers ────────────────────────────────────────────────────────────

    private function actingAsSettingsManager(string $permission = 'settings.manage'): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $role = Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        $role->givePermissionTo($permission);
        UserRoleAssignment::create([
            'user_id'    => $user->id,
            'role_id'    => $role->id,
            'scope_type' => RoleCatalog::GLOBAL,
            'is_primary' => true,
        ]);
        $this->actingAs($user);
        return $user;
    }

    private function validWindows(): array
    {
        return [
            'normal_start' => '08:00',
            'normal_end'   => '17:00',
            'day_start'    => '07:00',
            'day_end'      => '19:00',
            'night_start'  => '19:00',
            'night_end'    => '07:00',
        ];
    }

    // ─── Auth / Authorization ────────────────────────────────────────────────

    public function test_unauthenticated_cannot_read_shift_windows(): void
    {
        $this->getJson('/api/settings/shift-windows')->assertStatus(401);
    }

    public function test_unauthenticated_cannot_access_custom_shifts(): void
    {
        $this->getJson('/api/settings/custom-shifts')->assertStatus(401);
        $this->postJson('/api/settings/custom-shifts', [])->assertStatus(401);
    }

    public function test_user_without_permission_cannot_read_shift_windows(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->actingAs($user);
        $this->getJson('/api/settings/shift-windows')->assertStatus(403);
    }

    public function test_user_without_permission_cannot_manage_custom_shifts(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->actingAs($user);
        $this->getJson('/api/settings/custom-shifts')->assertStatus(403);
        $this->postJson('/api/settings/custom-shifts', ['name' => 'Evening', 'start' => '17:00', 'end' => '21:00'])->assertStatus(403);
    }

    public function test_staff_leave_manage_can_access_shift_windows(): void
    {
        $this->actingAsSettingsManager('staff.leave.manage');
        $this->getJson('/api/settings/shift-windows')->assertOk();
    }

    public function test_staff_salary_manage_can_access_custom_shifts(): void
    {
        $this->actingAsSettingsManager('staff.salary.manage');
        $this->getJson('/api/settings/custom-shifts')->assertOk();
    }

    // ─── Shift Windows ───────────────────────────────────────────────────────

    public function test_get_shift_windows_returns_defaults_when_none_saved(): void
    {
        $this->actingAsSettingsManager();
        $res = $this->getJson('/api/settings/shift-windows');
        $res->assertOk()
            ->assertJsonPath('data.normal_start', '08:00')
            ->assertJsonPath('data.day_start', '07:00')
            ->assertJsonPath('data.night_start', '19:00');
    }

    public function test_update_shift_windows_saves_all_six_fields(): void
    {
        $this->actingAsSettingsManager();
        $payload = $this->validWindows();
        $payload['normal_start'] = '09:00';

        $this->postJson('/api/settings/shift-windows', $payload)->assertOk();

        $res = $this->getJson('/api/settings/shift-windows');
        $res->assertOk()->assertJsonPath('data.normal_start', '09:00');
    }

    public function test_update_shift_windows_rejects_invalid_time_format(): void
    {
        $this->actingAsSettingsManager();
        $payload = $this->validWindows();
        $payload['day_start'] = '25:00';

        $this->postJson('/api/settings/shift-windows', $payload)->assertStatus(422);
    }

    public function test_update_shift_windows_rejects_missing_field(): void
    {
        $this->actingAsSettingsManager();
        $payload = $this->validWindows();
        unset($payload['normal_start']);

        $this->postJson('/api/settings/shift-windows', $payload)->assertStatus(422);
    }

    public function test_update_shift_windows_rejects_non_time_string(): void
    {
        $this->actingAsSettingsManager();
        $payload = $this->validWindows();
        $payload['night_end'] = 'drop table';

        $this->postJson('/api/settings/shift-windows', $payload)->assertStatus(422);
    }

    // ─── Custom Shifts — CRUD ────────────────────────────────────────────────

    public function test_get_custom_shifts_returns_empty_list_initially(): void
    {
        $this->actingAsSettingsManager();
        $this->getJson('/api/settings/custom-shifts')
            ->assertOk()
            ->assertJsonPath('data', []);
    }

    public function test_store_custom_shift_creates_record(): void
    {
        $this->actingAsSettingsManager();
        $res = $this->postJson('/api/settings/custom-shifts', [
            'name'  => 'Evening',
            'start' => '17:00',
            'end'   => '21:00',
        ]);
        $res->assertStatus(201)->assertJsonPath('data.name', 'Evening');
        $this->assertDatabaseHas('custom_shifts', ['name' => 'Evening']);
    }

    public function test_store_custom_shift_rejects_duplicate_name(): void
    {
        $this->actingAsSettingsManager();
        CustomShift::create(['name' => 'Evening', 'start' => '17:00', 'end' => '21:00']);

        $this->postJson('/api/settings/custom-shifts', [
            'name'  => 'Evening',
            'start' => '18:00',
            'end'   => '22:00',
        ])->assertStatus(422);
    }

    public function test_store_custom_shift_rejects_invalid_time(): void
    {
        $this->actingAsSettingsManager();
        $this->postJson('/api/settings/custom-shifts', [
            'name'  => 'Bad',
            'start' => 'abc',
            'end'   => '21:00',
        ])->assertStatus(422);
    }

    public function test_store_custom_shift_rejects_missing_name(): void
    {
        $this->actingAsSettingsManager();
        $this->postJson('/api/settings/custom-shifts', [
            'start' => '08:00',
            'end'   => '16:00',
        ])->assertStatus(422);
    }

    public function test_store_custom_shift_enforces_50_row_limit(): void
    {
        $this->actingAsSettingsManager();
        for ($i = 1; $i <= 50; $i++) {
            CustomShift::create(['name' => "Shift $i", 'start' => '08:00', 'end' => '16:00']);
        }

        $this->postJson('/api/settings/custom-shifts', [
            'name'  => 'Overflow',
            'start' => '08:00',
            'end'   => '16:00',
        ])->assertStatus(422)
          ->assertJsonPath('message', 'Custom shift limit reached (50).');
    }

    public function test_update_custom_shift_changes_record(): void
    {
        $this->actingAsSettingsManager();
        $shift = CustomShift::create(['name' => 'Flexi', 'start' => '10:00', 'end' => '18:00']);

        $res = $this->putJson("/api/settings/custom-shifts/{$shift->id}", [
            'name'  => 'Flexi Updated',
            'start' => '10:30',
            'end'   => '18:30',
        ]);
        $res->assertOk()->assertJsonPath('data.name', 'Flexi Updated');
        $this->assertDatabaseHas('custom_shifts', ['id' => $shift->id, 'name' => 'Flexi Updated']);
    }

    public function test_update_custom_shift_allows_same_name_on_self(): void
    {
        $this->actingAsSettingsManager();
        $shift = CustomShift::create(['name' => 'Split', 'start' => '06:00', 'end' => '14:00']);

        $this->putJson("/api/settings/custom-shifts/{$shift->id}", [
            'name'  => 'Split',
            'start' => '06:30',
            'end'   => '14:30',
        ])->assertOk();
    }

    public function test_update_custom_shift_rejects_duplicate_name_of_other_record(): void
    {
        $this->actingAsSettingsManager();
        CustomShift::create(['name' => 'Alpha', 'start' => '08:00', 'end' => '16:00']);
        $beta = CustomShift::create(['name' => 'Beta', 'start' => '09:00', 'end' => '17:00']);

        $this->putJson("/api/settings/custom-shifts/{$beta->id}", [
            'name'  => 'Alpha',
            'start' => '09:00',
            'end'   => '17:00',
        ])->assertStatus(422);
    }

    public function test_update_custom_shift_returns_404_for_missing_id(): void
    {
        $this->actingAsSettingsManager();
        $this->putJson('/api/settings/custom-shifts/99999', [
            'name'  => 'Ghost',
            'start' => '08:00',
            'end'   => '16:00',
        ])->assertStatus(404);
    }

    public function test_delete_custom_shift_removes_record(): void
    {
        $this->actingAsSettingsManager();
        $shift = CustomShift::create(['name' => 'ToDelete', 'start' => '08:00', 'end' => '16:00']);

        $this->deleteJson("/api/settings/custom-shifts/{$shift->id}")->assertOk();
        $this->assertDatabaseMissing('custom_shifts', ['id' => $shift->id]);
    }

    public function test_delete_custom_shift_returns_404_for_missing_id(): void
    {
        $this->actingAsSettingsManager();
        $this->deleteJson('/api/settings/custom-shifts/99999')->assertStatus(404);
    }

    // ─── Overtime Rate Multipliers ───────────────────────────────────────────

    public function test_update_overtime_rates_rejects_non_numeric_multiplier(): void
    {
        $this->actingAsSettingsManager();
        $this->postJson('/api/settings/overtime-rate-settings', [
            'weekdayMultiplier' => 'drop table',
        ])->assertStatus(422);
    }

    public function test_update_overtime_rates_rejects_negative_multiplier(): void
    {
        $this->actingAsSettingsManager();
        $this->postJson('/api/settings/overtime-rate-settings', [
            'weekdayMultiplier' => -1,
        ])->assertStatus(422);
    }

    public function test_update_overtime_rates_rejects_multiplier_over_100(): void
    {
        $this->actingAsSettingsManager();
        $this->postJson('/api/settings/overtime-rate-settings', [
            'publicHolidayMultiplier' => 150,
        ])->assertStatus(422);
    }

    public function test_update_overtime_rates_accepts_valid_multipliers(): void
    {
        $this->actingAsSettingsManager();
        $this->postJson('/api/settings/overtime-rate-settings', [
            'weekdayMultiplier'       => 1.5,
            'weekendMultiplier'       => 2.0,
            'publicHolidayMultiplier' => 3.0,
        ])->assertOk();
    }

    // ─── Leave Approval Rules — role name validation ─────────────────────────

    public function test_update_leave_rules_rejects_nonexistent_role(): void
    {
        $this->actingAsSettingsManager();
        $this->postJson('/api/settings/leave-approval-rules', [
            'rules' => [[
                'applicantRole' => 'Ghost Role',
                'reviewRole'    => 'Admin',
                'approveRole'   => 'Admin',
            ]],
        ])->assertStatus(422);
    }

    public function test_update_leave_rules_accepts_existing_roles(): void
    {
        $this->actingAsSettingsManager();
        Role::firstOrCreate(['name' => 'Staff', 'guard_name' => 'web']);

        $this->postJson('/api/settings/leave-approval-rules', [
            'rules' => [[
                'applicantRole' => 'Staff',
                'reviewRole'    => 'Admin',
                'approveRole'   => 'Admin',
            ]],
        ])->assertOk();
    }

    // ─── Salary Workflow Rules — role name validation ────────────────────────

    public function test_update_salary_rules_rejects_nonexistent_role(): void
    {
        $this->actingAsSettingsManager();
        $this->postJson('/api/settings/salary-workflow-rules', [
            'fallback' => ['approveRole' => 'Fake Role'],
        ])->assertStatus(422);
    }

    public function test_update_salary_rules_accepts_null_role_fields(): void
    {
        $this->actingAsSettingsManager();
        $this->postJson('/api/settings/salary-workflow-rules', [
            'fallback' => ['checkRole' => null, 'reviewRole' => null, 'approveRole' => null],
        ])->assertOk();
    }
}
