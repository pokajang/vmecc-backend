<?php

namespace Tests\Feature;

use App\Models\LeaveAssignment;
use App\Models\Roster;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\UserRoleAssignment;
use App\Services\RoleCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithoutMiddleware;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class LeaveRosterImpactTest extends TestCase
{
    use RefreshDatabase;
    use WithoutMiddleware;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'leave_type' => 'Annual Leave',
            'start_date' => '2026-07-13',
            'end_date' => '2026-07-13',
            'days' => 1,
            'work_shift' => 'day12',
            'start_time_slot' => 'shift-start',
            'end_time_slot' => 'shift-end',
            'reason' => 'Planned leave',
        ], $overrides);
    }

    private function assignRosterManager(User $user): void
    {
        $permission = Permission::firstOrCreate(['name' => 'rosters.manage', 'guard_name' => 'web']);
        $role = Role::firstOrCreate(['name' => 'Roster Manager', 'guard_name' => 'web']);
        $role->givePermissionTo($permission);
        UserRoleAssignment::query()->create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'scope_type' => RoleCatalog::GLOBAL,
            'is_primary' => true,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
        ]);
    }

    private function setupPublishedDuty(User $user): Team
    {
        $team = Team::query()->create(['name' => 'Alpha']);
        TeamMember::query()->create([
            'team_id' => $team->id,
            'user_id' => $user->id,
            'name' => $user->name,
            'started_at' => '2026-01-01',
        ]);
        Roster::query()->create([
            'date' => '2026-07-13',
            'shift' => 'day',
            'team_id' => $team->id,
            'status' => 'published',
        ]);

        return $team;
    }

    public function test_applicant_receives_published_roster_guidance_and_submission_snapshot(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->setupPublishedDuty($user);
        LeaveAssignment::query()->create([
            'user_id' => $user->id,
            'year' => 2026,
            'leave_type' => 'Annual Leave',
            'entitlement' => 14,
            'used' => 0,
            'pending' => 0,
        ]);

        $this->actingAs($user)
            ->getJson('/api/leave/roster-impact?start_date=2026-07-13&end_date=2026-07-13&work_shift=day12&start_time_slot=shift-start&end_time_slot=shift-end')
            ->assertOk()
            ->assertJsonPath('data.summary.duty_count', 1)
            ->assertJsonPath('data.items.0.team_name', 'Alpha');

        $this->postJson('/api/leave', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.roster_impact_snapshot.summary.duty_count', 1)
            ->assertJsonPath('data.roster_impact_snapshot.items.0.shift', 'day');
    }

    public function test_roster_markers_are_live_and_hide_people_from_non_managers(): void
    {
        $applicant = User::factory()->create(['status' => 'active', 'name' => 'Person A']);
        $this->setupPublishedDuty($applicant);
        LeaveAssignment::query()->create([
            'user_id' => $applicant->id,
            'year' => 2026,
            'leave_type' => 'Annual Leave',
            'entitlement' => 14,
            'used' => 0,
            'pending' => 0,
        ]);
        $this->actingAs($applicant)->postJson('/api/leave', $this->payload())->assertCreated();

        $viewer = User::factory()->create(['status' => 'active']);
        $this->actingAs($viewer)
            ->getJson('/api/rosters?date=2026-07-13')
            ->assertOk()
            ->assertJsonPath('data.0.shifts.day.leave_marker.requested_count', 1)
            ->assertJsonPath('data.0.shifts.day.leave_marker.people', []);

        $manager = User::factory()->create(['status' => 'active']);
        $this->assignRosterManager($manager);
        $this->actingAs($manager)
            ->getJson('/api/rosters?date=2026-07-13')
            ->assertOk()
            ->assertJsonPath('data.0.shifts.day.leave_marker.requested_count', 1)
            ->assertJsonPath('data.0.shifts.day.leave_marker.people.0.name', 'Person A')
            ->assertJsonMissing(['leave_type'])
            ->assertJsonMissing(['reason']);
    }

    public function test_roster_impact_supports_legacy_night_shift_and_rejects_unbounded_ranges(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $team = $this->setupPublishedDuty($user);
        Roster::query()->create([
            'date' => '2026-07-14',
            'shift' => 'night',
            'team_id' => $team->id,
            'status' => 'published',
        ]);

        $this->actingAs($user)
            ->getJson('/api/leave/roster-impact?start_date=2026-07-14&end_date=2026-07-14&work_shift=night&start_time_slot=shift-start&end_time_slot=shift-end')
            ->assertOk()
            ->assertJsonPath('data.summary.duty_count', 1)
            ->assertJsonPath('data.items.0.shift', 'night');

        $this->getJson('/api/leave/roster-impact?start_date=2026-01-01&end_date=2027-01-03')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('end_date');
    }
}
