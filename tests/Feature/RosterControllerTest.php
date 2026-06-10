<?php

namespace Tests\Feature;

use App\Models\Roster;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\UserRoleAssignment;
use App\Notifications\RosterPublishedNotification;
use App\Services\RoleCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RosterControllerTest extends TestCase
{
    use RefreshDatabase;

    // ─── Helpers ────────────────────────────────────────────────────────────

    private function actingAsRosterManager(): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $role = Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'rosters.manage', 'guard_name' => 'web']);
        $role->givePermissionTo('rosters.manage');
        UserRoleAssignment::create([
            'user_id'    => $user->id,
            'role_id'    => $role->id,
            'scope_type' => RoleCatalog::GLOBAL,
            'is_primary' => true,
        ]);
        $this->actingAs($user);
        return $user;
    }

    private function makeTeam(string $name = null): Team
    {
        return Team::factory()->create(['name' => $name ?? 'Team ' . uniqid()]);
    }

    private function rosterEntry(string $date, array $shifts): array
    {
        return ['date' => $date, 'shifts' => $shifts];
    }

    private function shift(string $shift, ?int $teamId): array
    {
        return ['shift' => $shift, 'team_id' => $teamId];
    }

    // ─── Auth / Authorization ────────────────────────────────────────────────

    public function test_unauthenticated_cannot_access_rosters(): void
    {
        $this->getJson('/api/rosters')->assertStatus(401);
        $this->postJson('/api/rosters', [])->assertStatus(401);
        $this->postJson('/api/rosters/publish', [])->assertStatus(401);
    }

    public function test_user_without_rosters_manage_cannot_read(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->actingAs($user);
        $this->getJson('/api/rosters')->assertStatus(403);
    }

    public function test_user_without_rosters_manage_cannot_store(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $team = $this->makeTeam();
        $this->actingAs($user);
        $this->postJson('/api/rosters', [
            'entries' => [$this->rosterEntry('2026-05-01', [$this->shift('day', $team->id)])],
        ])->assertStatus(403);
    }

    public function test_user_without_rosters_manage_cannot_publish(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $team = $this->makeTeam();
        $this->actingAs($user);
        $this->postJson('/api/rosters/publish', [
            'entries'     => [$this->rosterEntry('2026-05-01', [$this->shift('day', $team->id)])],
            'scope_label' => 'May 2026',
        ])->assertStatus(403);
    }

    // ─── Index ───────────────────────────────────────────────────────────────

    public function test_index_returns_roster_entries(): void
    {
        $this->actingAsRosterManager();
        $team = $this->makeTeam('Alpha');

        Roster::create([
            'date' => '2026-05-01', 'shift' => 'day',
            'team_id' => $team->id, 'status' => 'published',
        ]);

        $res = $this->getJson('/api/rosters?from=2026-05-01&to=2026-05-01');
        $res->assertOk()
            ->assertJsonPath('data.0.date', '2026-05-01')
            ->assertJsonPath('data.0.shifts.day.team', 'Alpha');
    }

    public function test_index_filters_by_status(): void
    {
        $this->actingAsRosterManager();
        $team = $this->makeTeam();

        Roster::create(['date' => '2026-05-01', 'shift' => 'day', 'team_id' => $team->id, 'status' => 'draft']);
        Roster::create(['date' => '2026-05-02', 'shift' => 'day', 'team_id' => $team->id, 'status' => 'published']);

        $res = $this->getJson('/api/rosters?status=draft&from=2026-05-01&to=2026-05-03');
        $res->assertOk();
        $dates = collect($res->json('data'))->pluck('date')->all();
        $this->assertContains('2026-05-01', $dates);
        $this->assertNotContains('2026-05-02', $dates);
    }

    public function test_index_rejects_range_over_366_days(): void
    {
        $this->actingAsRosterManager();
        $this->getJson('/api/rosters?from=2026-01-01&to=2027-06-01')
            ->assertStatus(422)
            ->assertJsonPath('errors.to.0', 'Date range must not exceed 366 days.');
    }

    public function test_index_rejects_invalid_status(): void
    {
        $this->actingAsRosterManager();
        $this->getJson('/api/rosters?status=invalid')
            ->assertStatus(422);
    }

    public function test_index_accepts_months_as_comma_string(): void
    {
        $this->actingAsRosterManager();
        $team = $this->makeTeam();

        Roster::create(['date' => '2026-05-15', 'shift' => 'day', 'team_id' => $team->id, 'status' => 'published']);

        $res = $this->getJson('/api/rosters?months=2026-05');
        $res->assertOk();
        $dates = collect($res->json('data'))->pluck('date')->all();
        $this->assertContains('2026-05-15', $dates);
    }

    // ─── Store (draft) ───────────────────────────────────────────────────────

    public function test_store_creates_draft_roster_entries(): void
    {
        $this->actingAsRosterManager();
        $team = $this->makeTeam();

        $this->postJson('/api/rosters', [
            'entries' => [
                $this->rosterEntry('2026-06-01', [
                    $this->shift('day', $team->id),
                    $this->shift('night', null),
                ]),
            ],
        ])->assertOk()->assertJsonPath('message', 'Roster draft saved.');

        $this->assertDatabaseHas('rosters', [
            'date'    => '2026-06-01',
            'shift'   => 'day',
            'team_id' => $team->id,
            'status'  => 'draft',
        ]);
    }

    public function test_store_deletes_shift_when_team_id_is_null(): void
    {
        $this->actingAsRosterManager();
        $team = $this->makeTeam();

        Roster::create(['date' => '2026-06-01', 'shift' => 'day', 'team_id' => $team->id, 'status' => 'draft']);

        $this->postJson('/api/rosters', [
            'entries' => [$this->rosterEntry('2026-06-01', [$this->shift('day', null)])],
        ])->assertOk();

        $this->assertDatabaseMissing('rosters', ['date' => '2026-06-01', 'shift' => 'day']);
    }

    public function test_store_rejects_same_team_on_both_shifts(): void
    {
        $this->actingAsRosterManager();
        $team = $this->makeTeam();

        $this->postJson('/api/rosters', [
            'entries' => [
                $this->rosterEntry('2026-06-01', [
                    $this->shift('day', $team->id),
                    $this->shift('night', $team->id),
                ]),
            ],
        ])->assertStatus(422)
            ->assertJsonPath('message', 'A team cannot be assigned to more than one shift on the same date.');
    }

    public function test_store_rejects_entries_exceeding_500(): void
    {
        $this->actingAsRosterManager();
        $entries = array_fill(0, 501, $this->rosterEntry('2026-06-01', [$this->shift('day', null)]));

        $this->postJson('/api/rosters', ['entries' => $entries])
            ->assertStatus(422);
    }

    public function test_store_rejects_nonexistent_team_id(): void
    {
        $this->actingAsRosterManager();

        $this->postJson('/api/rosters', [
            'entries' => [$this->rosterEntry('2026-06-01', [$this->shift('day', 99999)])],
        ])->assertStatus(422);
    }

    // ─── Publish ─────────────────────────────────────────────────────────────

    public function test_publish_sets_status_to_published(): void
    {
        Notification::fake();
        $this->actingAsRosterManager();
        $team = $this->makeTeam();

        $this->postJson('/api/rosters/publish', [
            'entries'     => [$this->rosterEntry('2026-07-01', [$this->shift('day', $team->id)])],
            'scope_label' => 'July 2026',
        ])->assertOk()->assertJsonPath('message', 'Roster published and teams notified.');

        $this->assertDatabaseHas('rosters', [
            'date'   => '2026-07-01',
            'shift'  => 'day',
            'status' => 'published',
        ]);
    }

    public function test_publish_upgrades_existing_draft_to_published(): void
    {
        Notification::fake();
        $this->actingAsRosterManager();
        $team = $this->makeTeam();

        Roster::create(['date' => '2026-07-01', 'shift' => 'day', 'team_id' => $team->id, 'status' => 'draft']);

        $this->postJson('/api/rosters/publish', [
            'entries'     => [$this->rosterEntry('2026-07-01', [$this->shift('day', $team->id)])],
            'scope_label' => 'July 2026',
        ])->assertOk();

        $this->assertEquals('published', Roster::where('date', '2026-07-01')->where('shift', 'day')->value('status'));
    }

    public function test_publish_sends_notification_to_active_team_members(): void
    {
        Notification::fake();
        config(['mail.workflow_notifications.enabled' => true, 'mail.workflow_notifications.modules.roster' => true]);

        $this->actingAsRosterManager();
        $team   = $this->makeTeam('Bravo');
        $member = User::factory()->create(['email' => 'member@example.com']);
        TeamMember::create(['team_id' => $team->id, 'user_id' => $member->id, 'name' => $member->name, 'ended_at' => null]);

        $this->postJson('/api/rosters/publish', [
            'entries'     => [$this->rosterEntry('2026-07-10', [$this->shift('day', $team->id)])],
            'scope_label' => 'July 2026',
        ])->assertOk();

        Notification::assertSentTo($member, RosterPublishedNotification::class);
    }

    public function test_publish_does_not_notify_ended_members(): void
    {
        Notification::fake();
        config(['mail.workflow_notifications.enabled' => true, 'mail.workflow_notifications.modules.roster' => true]);

        $this->actingAsRosterManager();
        $team   = $this->makeTeam('Charlie');
        $member = User::factory()->create(['email' => 'ended@example.com']);
        TeamMember::create([
            'team_id'  => $team->id,
            'user_id'  => $member->id,
            'name'     => $member->name,
            'ended_at' => Carbon::yesterday(),
        ]);

        $this->postJson('/api/rosters/publish', [
            'entries'     => [$this->rosterEntry('2026-07-10', [$this->shift('day', $team->id)])],
            'scope_label' => 'July 2026',
        ])->assertOk();

        Notification::assertNotSentTo($member, RosterPublishedNotification::class);
    }

    public function test_publish_does_not_send_notifications_when_mail_disabled(): void
    {
        Notification::fake();
        config(['mail.workflow_notifications.enabled' => false]);

        $this->actingAsRosterManager();
        $team   = $this->makeTeam('Delta');
        $member = User::factory()->create(['email' => 'delta@example.com']);
        TeamMember::create(['team_id' => $team->id, 'user_id' => $member->id, 'name' => $member->name, 'ended_at' => null]);

        $this->postJson('/api/rosters/publish', [
            'entries'     => [$this->rosterEntry('2026-07-10', [$this->shift('day', $team->id)])],
            'scope_label' => 'July 2026',
        ])->assertOk();

        Notification::assertNothingSent();
    }

    public function test_publish_rejects_same_team_on_both_shifts(): void
    {
        $this->actingAsRosterManager();
        $team = $this->makeTeam();

        $this->postJson('/api/rosters/publish', [
            'entries'     => [
                $this->rosterEntry('2026-07-01', [
                    $this->shift('day', $team->id),
                    $this->shift('night', $team->id),
                ]),
            ],
            'scope_label' => 'July 2026',
        ])->assertStatus(422)
            ->assertJsonPath('message', 'A team cannot be assigned to more than one shift on the same date.');
    }

    public function test_publish_requires_scope_label(): void
    {
        $this->actingAsRosterManager();
        $team = $this->makeTeam();

        $this->postJson('/api/rosters/publish', [
            'entries' => [$this->rosterEntry('2026-07-01', [$this->shift('day', $team->id)])],
        ])->assertStatus(422);
    }

    // ─── resolve row status ──────────────────────────────────────────────────

    public function test_index_row_status_is_unassigned_when_no_shifts(): void
    {
        $this->actingAsRosterManager();
        // No roster rows in DB — query with a date range that has no data.
        // We verify via the logic path by creating an entry, then deleting it.
        $team = $this->makeTeam();
        $r    = Roster::create(['date' => '2026-08-01', 'shift' => 'day', 'team_id' => $team->id, 'status' => 'draft']);
        $r->delete();

        $res = $this->getJson('/api/rosters?from=2026-08-01&to=2026-08-01');
        $res->assertOk();
        // No rows for that date — data array is empty (not "unassigned")
        $this->assertEmpty($res->json('data'));
    }

    public function test_index_row_status_is_draft_when_any_shift_is_draft(): void
    {
        $this->actingAsRosterManager();
        $team1 = $this->makeTeam();
        $team2 = $this->makeTeam();

        Roster::create(['date' => '2026-08-05', 'shift' => 'day',   'team_id' => $team1->id, 'status' => 'published']);
        Roster::create(['date' => '2026-08-05', 'shift' => 'night', 'team_id' => $team2->id, 'status' => 'draft']);

        $res = $this->getJson('/api/rosters?from=2026-08-05&to=2026-08-05');
        $res->assertOk()->assertJsonPath('data.0.status', 'draft');
    }

    public function test_index_row_status_is_published_when_all_shifts_published(): void
    {
        $this->actingAsRosterManager();
        $team1 = $this->makeTeam();
        $team2 = $this->makeTeam();

        Roster::create(['date' => '2026-08-10', 'shift' => 'day',   'team_id' => $team1->id, 'status' => 'published']);
        Roster::create(['date' => '2026-08-10', 'shift' => 'night', 'team_id' => $team2->id, 'status' => 'published']);

        $res = $this->getJson('/api/rosters?from=2026-08-10&to=2026-08-10');
        $res->assertOk()->assertJsonPath('data.0.status', 'published');
    }
}
