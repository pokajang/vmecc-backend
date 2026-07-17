<?php

namespace Tests\Feature;

use App\Models\Leave;
use App\Models\User;
use App\Models\UserRoleAssignment;
use App\Services\RoleCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class WorkflowRbacMaintenanceCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_reports_ownerless_pending_workflow(): void
    {
        $this->ownerlessLeave();

        $this->artisan('workflow:audit-rbac')
            ->expectsOutputToContain('no_action_owner')
            ->assertFailed();
    }

    public function test_reassignment_is_dry_run_by_default_and_apply_is_audited(): void
    {
        $leave = $this->ownerlessLeave();
        $this->activeLeaveManager();

        $this->artisan('workflow:reassign-role', [
            'module' => 'leave',
            'toRole' => 'Human Resource',
        ])->expectsOutputToContain('Dry run: 1')
            ->assertSuccessful();
        $this->assertNull($leave->fresh()->next_action_role);

        $this->artisan('workflow:reassign-role', [
            'module' => 'leave',
            'toRole' => 'Human Resource',
            '--reason' => 'Approved recovery for a stranded request.',
            '--apply' => true,
        ])->expectsOutputToContain('Reassigned 1')
            ->assertSuccessful();

        $leave->refresh();
        $this->assertSame('Human Resource', $leave->next_action_role);
        $this->assertSame(2, $leave->version);
        $this->assertSame('Workflow Reassigned', $leave->approval_history[0]['action']);
        $this->assertDatabaseHas('workflow_transition_events', [
            'record_type' => Leave::class,
            'record_id' => (string) $leave->id,
            'action' => 'Workflow Reassigned',
        ]);
        $this->assertDatabaseHas('workflow_notifications', [
            'record_type' => 'leave',
            'record_id' => (string) $leave->id,
            'event_type' => 'workflow_reassigned',
        ]);
    }

    private function ownerlessLeave(): Leave
    {
        $owner = User::factory()->create(['status' => 'Active']);

        return Leave::query()->create([
            'user_id' => $owner->id,
            'display_id' => 'LV-STRANDED-001',
            'leave_type' => 'Annual Leave',
            'status' => 'Pending',
            'start_date' => '2026-07-20',
            'end_date' => '2026-07-20',
            'days' => 1,
            'applied_at' => now(),
            'workflow_stage' => 'review',
            'workflow_snapshot' => [],
            'next_action_role' => null,
            'approval_history' => [],
            'version' => 1,
        ]);
    }

    private function activeLeaveManager(): User
    {
        $permission = Permission::query()->firstOrCreate([
            'name' => 'staff.leave.manage',
            'guard_name' => 'web',
        ]);
        $role = Role::query()->firstOrCreate([
            'name' => 'Human Resource',
            'guard_name' => 'web',
        ]);
        $role->givePermissionTo($permission);
        $user = User::factory()->create(['status' => 'Active']);
        UserRoleAssignment::query()->create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'scope_type' => RoleCatalog::GLOBAL,
            'is_primary' => true,
        ]);

        return $user;
    }
}
