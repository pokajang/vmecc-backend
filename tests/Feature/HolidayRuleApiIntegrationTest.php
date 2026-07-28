<?php

namespace Tests\Feature;

use App\Models\Holiday;
use App\Models\LeaveAssignment;
use App\Models\User;
use App\Models\UserRoleAssignment;
use App\Services\RoleCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithoutMiddleware;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class HolidayRuleApiIntegrationTest extends TestCase
{
    use RefreshDatabase;
    use WithoutMiddleware;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createWorkflowRecipient('Human Resource');
        $this->createWorkflowRecipient('Contract Manager');
    }

    private function makeOvertimeEligible(User $user): void
    {
        $role = Role::firstOrCreate(['name' => 'Tactical Response Team', 'guard_name' => 'web']);
        $permission = Permission::firstOrCreate(['name' => 'self.overtime', 'guard_name' => 'web']);
        $role->givePermissionTo($permission);

        UserRoleAssignment::create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'scope_type' => RoleCatalog::GLOBAL,
            'is_primary' => true,
        ]);
    }

    private function createWorkflowRecipient(string $roleName): void
    {
        $user = User::factory()->create(['status' => 'Active']);
        $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
        UserRoleAssignment::query()->create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'scope_type' => RoleCatalog::OFFICE,
            'is_primary' => true,
        ]);
    }

    public function test_leave_store_uses_server_calculated_days_and_returns_holiday_guidance(): void
    {
        $user = User::factory()->create([
            'status' => 'Active',
            'state' => 'Perak',
        ]);
        $this->actingAs($user);

        Holiday::query()->create([
            'name' => 'Labour Day',
            'date' => '2026-05-01',
            'year' => 2026,
            'scope' => 'National',
            'state' => 'All States',
            'is_default_national' => true,
            'fixed_holiday_key' => 'labour-day',
        ]);
        LeaveAssignment::query()->create([
            'user_id' => $user->id,
            'year' => 2026,
            'leave_type' => 'Annual Leave',
            'entitlement' => 14,
            'used' => 0,
            'pending' => 0,
        ]);

        $response = $this->postJson('/api/leave', [
            'leave_type' => 'Annual Leave',
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-04',
            'days' => 4,
            'work_shift' => 'normal',
            'start_time_slot' => 'shift-start',
            'end_time_slot' => 'shift-end',
            'reason' => 'Family matter and recovery',
            'cover_by' => 'Teammate A',
            'attachment_id' => null,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.days', 1);
        $response->assertJsonPath(
            'meta.day_adjusted_message',
            'Recommended leave days based on weekends/public holidays is 1.',
        );
    }

    public function test_overtime_store_keeps_selected_type_and_returns_recommendation(): void
    {
        $user = User::factory()->create([
            'status' => 'Active',
            'state' => 'Perak',
        ]);
        $this->makeOvertimeEligible($user);
        $this->actingAs($user);

        Holiday::query()->create([
            'name' => 'Special Holiday',
            'date' => '2026-04-13',
            'year' => 2026,
            'scope' => 'National',
            'state' => 'All States',
            'is_default_national' => false,
            'fixed_holiday_key' => null,
        ]);

        $classifyResponse = $this->getJson('/api/overtime/classify-date?claim_date=2026-04-13');
        $classifyResponse->assertOk();
        $classifyResponse->assertJsonPath('data.overtime_type', 'publicHoliday');

        $storeResponse = $this->postJson('/api/overtime', [
            'overtime_type' => 'weekday',
            'claim_date' => '2026-04-13',
            'start_time' => '09:00',
            'end_time' => '10:00',
            'is_overnight' => false,
            'duration_minutes' => 60,
            'reason' => 'Urgent support work',
        ]);

        $storeResponse->assertCreated();
        $storeResponse->assertJsonPath('data.overtime_type', 'weekday');
        $storeResponse->assertJsonPath(
            'meta.overtime_type_adjusted_message',
            'Recommended overtime type based on claim date/public holiday rules is publicHoliday.',
        );
    }
}
