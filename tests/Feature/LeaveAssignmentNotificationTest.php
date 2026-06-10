<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserRoleAssignment;
use App\Models\WorkflowNotification;
use App\Services\RoleCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class LeaveAssignmentNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsLeaveManager(): User
    {
        $manager = User::factory()->create(['status' => 'active']);
        $role = Role::firstOrCreate(['name' => 'Leave Manager', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'staff.leave.manage', 'guard_name' => 'web']);
        $role->givePermissionTo('staff.leave.manage');
        UserRoleAssignment::create([
            'user_id' => $manager->id,
            'role_id' => $role->id,
            'scope_type' => RoleCatalog::GLOBAL,
            'is_primary' => true,
            'start_date' => now()->subDay(),
            'end_date' => now()->addDay(),
        ]);

        $this->actingAs($manager);
        return $manager;
    }

    public function test_delete_emits_leave_allocation_deleted_notification_to_owner(): void
    {
        $this->actingAsLeaveManager();
        $employee = User::factory()->create(['status' => 'active']);

        $created = $this->postJson('/api/staff/leave/assignments', [
            'user_id' => $employee->id,
            'year' => 2026,
            'leave_type' => 'Annual Leave',
            'entitlement' => 12,
            'used' => 0,
            'pending' => 0,
        ])->assertCreated();

        $assignmentId = (int) data_get($created->json(), 'data.id');
        $this->deleteJson("/api/staff/leave/assignments/{$assignmentId}")
            ->assertOk();

        $notification = WorkflowNotification::query()
            ->where('module', 'leave')
            ->where('event_type', 'allocation_deleted')
            ->where('record_type', 'leave_allocation')
            ->where('owner_user_id', $employee->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($notification);
        $this->assertContains((int) $employee->id, $notification->recipient_user_ids ?? []);
    }
}
