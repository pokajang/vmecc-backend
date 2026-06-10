<?php

namespace Tests\Feature;

use App\Models\OvertimeRecord;
use App\Models\Team;
use App\Models\User;
use App\Models\UserRoleAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OvertimeManagementRecordsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_overtime_records_requires_auth_and_permission(): void
    {
        $this->getJson('/api/staff/overtime/records')->assertStatus(401);

        $user = User::factory()->create(['status' => 'active']);
        $this->actingAs($user);

        $this->getJson('/api/staff/overtime/records')->assertStatus(403);
    }

    public function test_staff_overtime_records_returns_paginated_meta_filters_and_data_key(): void
    {
        $manager = $this->createStaffManager();
        $this->actingAs($manager);

        $employee = User::factory()->create(['name' => 'Employee One', 'status' => 'active']);
        $this->createOvertimeRecord($employee, [
            'display_id' => 'OT-2026-100',
            'applied_at' => now()->subDay(),
            'status' => 'Pending',
        ]);
        $this->createOvertimeRecord($employee, [
            'display_id' => 'OT-2026-101',
            'applied_at' => now(),
            'status' => 'Approved',
        ]);

        $response = $this->getJson('/api/staff/overtime/records?per_page=1&page=1&sort=appliedAt:desc');
        $response->assertOk();
        $response->assertJsonStructure([
            'data',
            'meta' => [
                'page',
                'per_page',
                'last_page',
                'total_count',
                'filtered_count',
                'returned_count',
                'sort',
                'query',
            ],
            'filters' => ['status', 'overtime_type', 'team'],
        ]);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('meta.per_page', 1);
        $response->assertJsonPath('meta.page', 1);
        $response->assertJsonPath('meta.total_count', 2);
        $response->assertJsonPath('meta.filtered_count', 2);
        $response->assertJsonPath('meta.sort', 'appliedAt:desc');
    }

    public function test_staff_overtime_records_applies_status_type_period_search_sort_and_pagination(): void
    {
        $manager = $this->createStaffManager();
        $this->actingAs($manager);

        $employee = User::factory()->create(['name' => 'Search Target', 'status' => 'active']);
        $this->createOvertimeRecord($employee, [
            'display_id' => 'OT-2026-201',
            'overtime_type' => 'weekend',
            'status' => 'Pending',
            'reason' => 'needle recent overtime',
            'duration_minutes' => 240,
            'applied_at' => now()->subDays(2),
        ]);
        $this->createOvertimeRecord($employee, [
            'display_id' => 'OT-2026-202',
            'overtime_type' => 'weekend',
            'status' => 'Pending',
            'reason' => 'needle short overtime',
            'duration_minutes' => 60,
            'applied_at' => now()->subDays(3),
        ]);
        $this->createOvertimeRecord($employee, [
            'display_id' => 'OT-2026-203',
            'overtime_type' => 'weekday',
            'status' => 'Pending',
            'reason' => 'needle wrong type',
            'duration_minutes' => 180,
            'applied_at' => now()->subDays(1),
        ]);
        $this->createOvertimeRecord($employee, [
            'display_id' => 'OT-2026-204',
            'overtime_type' => 'weekend',
            'status' => 'Approved',
            'reason' => 'needle wrong status',
            'duration_minutes' => 200,
            'applied_at' => now()->subDays(1),
        ]);
        $this->createOvertimeRecord($employee, [
            'display_id' => 'OT-2026-205',
            'overtime_type' => 'weekend',
            'status' => 'Pending',
            'reason' => 'needle too old',
            'duration_minutes' => 300,
            'applied_at' => now()->subDays(120),
        ]);

        $response = $this->getJson(
            '/api/staff/overtime/records?' .
            http_build_query([
                'status' => 'Pending',
                'overtime_type' => 'weekend',
                'period' => '30',
                'search' => 'needle',
                'sort' => 'durationMinutes:desc',
                'per_page' => 1,
                'page' => 1,
            ]),
        );

        $response->assertOk();
        $response->assertJsonPath('meta.filtered_count', 2);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.display_id', 'OT-2026-201');
        $response->assertJsonPath('data.0.duration_minutes', 240);

        $pageTwo = $this->getJson(
            '/api/staff/overtime/records?' .
            http_build_query([
                'status' => 'Pending',
                'overtime_type' => 'weekend',
                'period' => '30',
                'search' => 'needle',
                'sort' => 'durationMinutes:desc',
                'per_page' => 1,
                'page' => 2,
            ]),
        );

        $pageTwo->assertOk();
        $pageTwo->assertJsonPath('data.0.display_id', 'OT-2026-202');
    }

    public function test_staff_overtime_records_team_filter_prefers_active_assignment_then_fallback_user_team(): void
    {
        $manager = $this->createStaffManager();
        $this->actingAs($manager);

        $teamAlpha = Team::query()->create(['name' => 'Alpha', 'status' => 'On Duty']);
        $teamBeta = Team::query()->create(['name' => 'Beta', 'status' => 'On Duty']);

        $assignmentRole = Role::query()->firstOrCreate(
            ['name' => 'Incident Commander', 'guard_name' => 'web'],
            ['name' => 'Incident Commander', 'guard_name' => 'web'],
        );

        $assignmentUser = User::factory()->create([
            'name' => 'Assignment User',
            'team' => 'Legacy Team',
            'status' => 'active',
        ]);
        UserRoleAssignment::query()->create([
            'user_id' => $assignmentUser->id,
            'role_id' => $assignmentRole->id,
            'scope_type' => 'office',
            'team_id' => $teamAlpha->id,
            'start_date' => now()->subDays(5)->toDateString(),
            'end_date' => now()->addDays(5)->toDateString(),
            'is_primary' => true,
        ]);
        $this->createOvertimeRecord($assignmentUser, [
            'display_id' => 'OT-2026-301',
            'status' => 'Pending',
        ]);

        $fallbackUser = User::factory()->create([
            'name' => 'Fallback User',
            'team' => 'Fallback Team',
            'status' => 'active',
        ]);
        UserRoleAssignment::query()->create([
            'user_id' => $fallbackUser->id,
            'role_id' => $assignmentRole->id,
            'scope_type' => 'office',
            'team_id' => $teamBeta->id,
            'start_date' => now()->subDays(10)->toDateString(),
            'end_date' => now()->subDays(2)->toDateString(),
            'is_primary' => true,
        ]);
        $this->createOvertimeRecord($fallbackUser, [
            'display_id' => 'OT-2026-302',
            'status' => 'Pending',
        ]);

        $unassignedUser = User::factory()->create([
            'name' => 'No Team User',
            'team' => '',
            'status' => 'active',
        ]);
        $this->createOvertimeRecord($unassignedUser, [
            'display_id' => 'OT-2026-303',
            'status' => 'Pending',
        ]);

        $alphaResponse = $this->getJson('/api/staff/overtime/records?team=Alpha');
        $alphaResponse->assertOk();
        $this->assertSame(
            ['OT-2026-301'],
            collect($alphaResponse->json('data'))->pluck('display_id')->all(),
        );

        $legacyResponse = $this->getJson('/api/staff/overtime/records?team=Legacy%20Team');
        $legacyResponse->assertOk();
        $this->assertCount(0, $legacyResponse->json('data'));

        $fallbackResponse = $this->getJson('/api/staff/overtime/records?team=Fallback%20Team');
        $fallbackResponse->assertOk();
        $this->assertSame(
            ['OT-2026-302'],
            collect($fallbackResponse->json('data'))->pluck('display_id')->all(),
        );

        $unassignedResponse = $this->getJson('/api/staff/overtime/records?team=Unassigned');
        $unassignedResponse->assertOk();
        $this->assertSame(
            ['OT-2026-303'],
            collect($unassignedResponse->json('data'))->pluck('display_id')->all(),
        );
    }

    public function test_staff_overtime_records_includes_employee_avatar_url_when_available(): void
    {
        $manager = $this->createStaffManager();
        $this->actingAs($manager);

        $employee = User::factory()->create([
            'name' => 'Avatar User',
            'status' => 'active',
            'profile_image_url' => 'https://example.com/avatar-user.jpg',
        ]);
        $this->createOvertimeRecord($employee, [
            'display_id' => 'OT-2026-401',
            'status' => 'Pending',
        ]);

        $response = $this->getJson('/api/staff/overtime/records?search=OT-2026-401');
        $response->assertOk();
        $response->assertJsonPath('data.0.avatar_url', 'https://example.com/avatar-user.jpg');
    }

    private function createStaffManager(): User
    {
        $permission = Permission::query()->firstOrCreate(
            ['name' => 'staff.salary.manage', 'guard_name' => 'web'],
            ['name' => 'staff.salary.manage', 'guard_name' => 'web'],
        );
        $role = Role::query()->firstOrCreate(
            ['name' => 'HR Test Role', 'guard_name' => 'web'],
            ['name' => 'HR Test Role', 'guard_name' => 'web'],
        );
        if (! $role->hasPermissionTo($permission)) {
            $role->givePermissionTo($permission);
        }

        $user = User::factory()->create(['status' => 'active']);
        $user->assignRole($role);

        return $user;
    }

    private function createOvertimeRecord(User $user, array $overrides = []): OvertimeRecord
    {
        return OvertimeRecord::query()->create(array_merge([
            'user_id' => $user->id,
            'display_id' => 'OT-TEST-' . str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT),
            'overtime_type' => 'weekday',
            'claim_date' => now()->toDateString(),
            'start_time' => '09:00',
            'end_time' => '10:00',
            'is_overnight' => false,
            'duration_minutes' => 60,
            'reason' => 'Overtime for feature test',
            'status' => 'Pending',
            'applied_at' => now(),
            'workflow_stage' => 'review',
            'workflow_snapshot' => [
                'reviewRole' => 'Contract Manager',
                'recommendRole' => 'Human Resource',
                'approveRole' => 'Client Contract Manager',
                'requireRecommendation' => true,
            ],
            'next_action_role' => 'Contract Manager',
            'applicant_roles' => ['Incident Commander'],
            'approval_history' => [],
            'submitted_by' => $user->name,
        ], $overrides));
    }
}
