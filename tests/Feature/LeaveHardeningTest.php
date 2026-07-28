<?php

namespace Tests\Feature;

use App\Models\Leave;
use App\Models\LeaveAssignment;
use App\Models\LeaveAttachment;
use App\Models\User;
use App\Models\UserRoleAssignment;
use App\Services\RoleCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithoutMiddleware;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class LeaveHardeningTest extends TestCase
{
    use RefreshDatabase;
    use WithoutMiddleware;

    protected function setUp(): void
    {
        parent::setUp();

        $reviewer = User::factory()->create(['status' => 'active']);
        $this->assignRole($reviewer, 'Human Resource');
    }

    private function assignmentFor(User $user, string $type = 'Annual Leave', float $entitlement = 14): void
    {
        LeaveAssignment::query()->create([
            'user_id' => $user->id,
            'year' => 2026,
            'leave_type' => $type,
            'entitlement' => $entitlement,
            'used' => 0,
            'pending' => 0,
        ]);
    }

    private function assignRole(User $user, string $roleName): void
    {
        $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
        UserRoleAssignment::query()->create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'scope_type' => RoleCatalog::GLOBAL,
            'is_primary' => true,
            'start_date' => now()->subDay(),
            'end_date' => now()->addDay(),
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'leave_type' => 'Annual Leave',
            'start_date' => '2026-07-13',
            'end_date' => '2026-07-13',
            'days' => 0.5,
            'work_shift' => 'normal',
            'start_time_slot' => 'shift-start',
            'end_time_slot' => 'shift-end',
            'reason' => 'Family commitment',
            'cover_by' => null,
            'attachment_id' => null,
        ], $overrides);
    }

    public function test_store_persists_server_calculated_days_and_reserves_balance(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->assignmentFor($user, entitlement: 2);
        $this->actingAs($user);

        $this->postJson('/api/leave', $this->payload(['days' => 0.5]))
            ->assertCreated()
            ->assertJsonPath('data.days', 1)
            ->assertJsonPath('data.version', 1);

        $this->assertDatabaseHas('leave_assignments', [
            'user_id' => $user->id,
            'year' => 2026,
            'leave_type' => 'Annual Leave',
            'pending' => 1,
        ]);
    }

    public function test_store_rejects_missing_entitlement_and_overlapping_active_leave(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->actingAs($user);

        $this->postJson('/api/leave', $this->payload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('leave_type');

        $this->assignmentFor($user);
        $this->postJson('/api/leave', $this->payload())->assertCreated();
        $this->postJson('/api/leave', $this->payload(['start_time_slot' => 'midpoint']))
            ->assertConflict()
            ->assertJsonPath('code', 'LEAVE_OVERLAP');
    }

    public function test_stale_update_returns_conflict_and_correction_can_be_resubmitted(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->assignmentFor($user, entitlement: 5);
        $this->actingAs($user);
        $created = $this->postJson('/api/leave', $this->payload())->assertCreated();
        $leaveId = (int) $created->json('data.id');

        $this->putJson("/api/leave/{$leaveId}", $this->payload([
            'reason' => 'Updated family commitment',
            'expected_version' => 1,
        ]))->assertOk()->assertJsonPath('data.version', 2);
        $this->putJson("/api/leave/{$leaveId}", $this->payload([
            'reason' => 'Stale change',
            'expected_version' => 1,
        ]))->assertConflict()->assertJsonPath('code', 'LEAVE_VERSION_CONFLICT');

        $reviewer = User::factory()->create(['status' => 'active']);
        $this->assignRole($reviewer, 'Reviewer');
        Leave::query()->whereKey($leaveId)->update([
            'workflow_snapshot' => ['reviewRole' => 'Reviewer'],
            'next_action_role' => 'Reviewer',
        ]);
        $this->actingAs($reviewer);
        $this->postJson("/api/staff/leave/records/{$user->id}/{$leaveId}/request-correction", [
            'remarks' => 'Please clarify the handover.',
            'expected_version' => 2,
        ])->assertOk()->assertJsonPath('data.status', 'Needs Correction')->assertJsonPath('data.version', 3);
        $this->actingAs($user);
        $this->putJson("/api/leave/{$leaveId}", $this->payload([
            'reason' => 'Clarified family commitment and handover.',
            'expected_version' => 3,
        ]))->assertOk()->assertJsonPath('data.status', 'Pending')->assertJsonPath('data.version', 4);
    }

    public function test_non_assigned_manager_cannot_reject_a_leave(): void
    {
        $owner = User::factory()->create(['status' => 'active']);
        $manager = User::factory()->create(['status' => 'active']);
        $this->assignRole($manager, 'Other Manager');
        $leave = Leave::query()->create([
            'user_id' => $owner->id,
            'display_id' => 'LV-AL-2026-001',
            'leave_type' => 'Annual Leave',
            'status' => 'Pending',
            'start_date' => '2026-07-13',
            'end_date' => '2026-07-13',
            'days' => 1,
            'workflow_stage' => 'review',
            'workflow_snapshot' => ['reviewRole' => 'Reviewer'],
            'next_action_role' => 'Reviewer',
            'approval_history' => [],
            'version' => 1,
        ]);
        $this->actingAs($manager);

        $this->postJson("/api/staff/leave/records/{$owner->id}/{$leave->id}/reject", [
            'remarks' => 'Not authorized for this stage.',
            'expected_version' => 1,
        ])->assertUnprocessable()->assertJsonValidationErrors('role');
    }

    public function test_required_evidence_must_belong_to_the_applicant(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $otherUser = User::factory()->create(['status' => 'active']);
        $this->assignmentFor($user, 'Medical Leave');
        $attachment = LeaveAttachment::query()->create([
            'user_id' => $otherUser->id,
            'original_name' => 'medical-note.pdf',
            'mime_type' => 'application/pdf',
            'size' => 32,
            'storage_path' => 'leave-attachments/test-note.pdf',
        ]);
        $this->actingAs($user);

        $this->postJson('/api/leave', $this->payload([
            'leave_type' => 'Medical Leave',
            'attachment_id' => $attachment->id,
        ]))->assertUnprocessable()->assertJsonValidationErrors('attachment_id');
    }

    public function test_leave_manager_can_view_evidence_but_an_unrelated_user_cannot(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create(['status' => 'active']);
        $manager = User::factory()->create(['status' => 'active']);
        $otherUser = User::factory()->create(['status' => 'active']);
        $this->assignRole($manager, 'Leave Manager');
        Permission::firstOrCreate(['name' => 'staff.leave.manage', 'guard_name' => 'web']);
        Role::findByName('Leave Manager', 'web')->givePermissionTo('staff.leave.manage');
        $path = 'leave-attachments/test-access.pdf';
        Storage::disk('local')->put($path, 'test evidence');
        $attachment = LeaveAttachment::query()->create([
            'user_id' => $owner->id,
            'original_name' => 'test-access.pdf',
            'mime_type' => 'application/pdf',
            'size' => 13,
            'storage_path' => $path,
        ]);

        $this->actingAs($manager)
            ->get("/api/leave/attachments/{$attachment->id}")
            ->assertOk();
        $this->actingAs($otherUser)
            ->get("/api/leave/attachments/{$attachment->id}")
            ->assertForbidden();
    }
}
