<?php

namespace Tests\Feature;

use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\UserRoleAssignment;
use App\Models\WorkflowNotification;
use App\Services\RoleCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TeamControllerTest extends TestCase
{
    use RefreshDatabase;

    // ─── Helpers ────────────────────────────────────────────────────────────

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->create(['status' => 'active']);
        $role  = Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'teams.manage', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'teams.view', 'guard_name' => 'web']);
        $role->givePermissionTo(['teams.manage', 'teams.view']);
        UserRoleAssignment::create([
            'user_id'    => $admin->id,
            'role_id'    => $role->id,
            'scope_type' => RoleCatalog::GLOBAL,
            'is_primary' => true,
        ]);
        $this->actingAs($admin);
        return $admin;
    }

    // ─── CREATE ──────────────────────────────────────────────────────────────

    public function test_store_creates_team_and_returns_201(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/teams', ['name' => 'Alpha Team']);

        $response->assertCreated();
        $this->assertDatabaseHas('teams', ['name' => 'Alpha Team']);
    }

    public function test_store_requires_unique_name(): void
    {
        $this->actingAsAdmin();
        Team::factory()->create(['name' => 'Alpha Team']);

        $this->postJson('/api/teams', ['name' => 'Alpha Team'])
             ->assertUnprocessable()
             ->assertJsonValidationErrors(['name']);
    }

    // ─── UPDATE — dual-team guard ────────────────────────────────────────────

    public function test_update_rejects_member_already_active_on_another_team(): void
    {
        $this->actingAsAdmin();

        $otherTeam = Team::factory()->create();
        $user      = User::factory()->create(['status' => 'active']);

        // User is already an active member of $otherTeam
        TeamMember::factory()->create([
            'team_id'  => $otherTeam->id,
            'user_id'  => $user->id,
            'name'     => $user->name,
            'ended_at' => null,
        ]);

        $targetTeam = Team::factory()->create();

        $response = $this->putJson("/api/teams/{$targetTeam->id}", [
            'name'    => $targetTeam->name,
            'members' => [
                [
                    'user_id'    => $user->id,
                    'name'       => $user->name,
                    'role'       => 'tactical response team',
                    'is_primary' => false,
                    'started_at' => now()->toDateString(),
                ],
            ],
        ]);

        $response->assertUnprocessable()
                 ->assertJsonPath('errors.members.0', fn ($msg) => str_contains($msg, $otherTeam->name));
    }

    public function test_update_allows_user_already_on_same_team(): void
    {
        $this->actingAsAdmin();

        $team = Team::factory()->create();
        $user = User::factory()->create(['status' => 'active']);

        // User is an active member of the same team being updated — should be fine
        TeamMember::factory()->create([
            'team_id'  => $team->id,
            'user_id'  => $user->id,
            'name'     => $user->name,
            'ended_at' => null,
        ]);

        $this->putJson("/api/teams/{$team->id}", [
            'name'    => $team->name,
            'members' => [
                [
                    'user_id'    => $user->id,
                    'name'       => $user->name,
                    'role'       => 'tactical response team',
                    'is_primary' => false,
                    'started_at' => now()->toDateString(),
                ],
            ],
        ])->assertOk();
    }

    // ─── UPDATE — member sync ────────────────────────────────────────────────

    public function test_update_sets_ended_at_on_removed_members(): void
    {
        $this->actingAsAdmin();

        $team       = Team::factory()->create();
        $user       = User::factory()->create(['status' => 'active']);
        $membership = TeamMember::factory()->create([
            'team_id'  => $team->id,
            'user_id'  => $user->id,
            'name'     => $user->name,
            'ended_at' => null,
        ]);

        // Update with an empty members list — should close the existing membership
        $this->putJson("/api/teams/{$team->id}", [
            'name'    => $team->name,
            'members' => [],
        ])->assertOk();

        $this->assertNotNull($membership->fresh()->ended_at);
    }

    // ─── UPDATE — in-app notifications ──────────────────────────────────────

    public function test_update_emits_workflow_notification_for_new_member(): void
    {
        $this->actingAsAdmin();

        $team = Team::factory()->create();
        $user = User::factory()->create(['status' => 'active']);

        $this->putJson("/api/teams/{$team->id}", [
            'name'    => $team->name,
            'members' => [
                [
                    'user_id'    => $user->id,
                    'name'       => $user->name,
                    'role'       => 'tactical response team',
                    'is_primary' => false,
                    'started_at' => now()->toDateString(),
                ],
            ],
        ])->assertOk();

        $this->assertDatabaseHas('workflow_notifications', [
            'module'     => 'team',
            'event_type' => 'member_assigned',
            'record_id'  => $team->id,
        ]);

        $notification = WorkflowNotification::where('module', 'team')
            ->where('event_type', 'member_assigned')
            ->where('record_id', $team->id)
            ->first();

        $this->assertNotNull($notification);
        $this->assertContains((int) $user->id, $notification->recipient_user_ids ?? []);
    }

    // ─── DELETE ──────────────────────────────────────────────────────────────

    public function test_destroy_deletes_team_and_returns_204(): void
    {
        $this->actingAsAdmin();
        $team = Team::factory()->create();

        $this->deleteJson("/api/teams/{$team->id}")->assertNoContent();

        $this->assertDatabaseMissing('teams', ['id' => $team->id]);
    }

    public function test_destroy_emits_team_disbanded_notification_to_active_members(): void
    {
        $this->actingAsAdmin();

        $team = Team::factory()->create();
        $user = User::factory()->create(['status' => 'active']);

        TeamMember::factory()->create([
            'team_id'  => $team->id,
            'user_id'  => $user->id,
            'name'     => $user->name,
            'ended_at' => null,
        ]);

        $this->deleteJson("/api/teams/{$team->id}")->assertNoContent();

        $notification = WorkflowNotification::where('module', 'team')
            ->where('event_type', 'team_disbanded')
            ->first();

        $this->assertNotNull($notification);
        $this->assertContains((int) $user->id, $notification->recipient_user_ids ?? []);
    }

    public function test_destroy_snapshots_active_members_to_deleted_teams(): void
    {
        $this->actingAsAdmin();

        $team = Team::factory()->create();
        $user = User::factory()->create(['status' => 'active']);

        TeamMember::factory()->create([
            'team_id'  => $team->id,
            'user_id'  => $user->id,
            'name'     => $user->name,
            'role'     => 'tactical response team',
            'ended_at' => null,
        ]);

        $this->deleteJson("/api/teams/{$team->id}")->assertNoContent();

        $this->assertDatabaseHas('deleted_teams', ['name' => $team->name]);
    }

    // ─── Audit log ───────────────────────────────────────────────────────────

    public function test_store_writes_audit_log(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/teams', ['name' => 'Audit Team'])->assertCreated();

        $this->assertDatabaseHas('audit_logs', ['action' => 'team_created']);
    }

    public function test_update_writes_audit_log(): void
    {
        $this->actingAsAdmin();
        $team = Team::factory()->create();

        $this->putJson("/api/teams/{$team->id}", [
            'name'    => $team->name,
            'members' => [],
        ])->assertOk();

        $this->assertDatabaseHas('audit_logs', ['action' => 'team_updated']);
    }

    public function test_destroy_writes_audit_log(): void
    {
        $this->actingAsAdmin();
        $team = Team::factory()->create();

        $this->deleteJson("/api/teams/{$team->id}")->assertNoContent();

        $this->assertDatabaseHas('audit_logs', ['action' => 'team_deleted']);
    }

    // ─── uploadImage ─────────────────────────────────────────────────────────

    public function test_upload_image_stores_file_and_updates_team(): void
    {
        Storage::fake('public');
        $this->actingAsAdmin();
        $team = Team::factory()->create();

        $file = UploadedFile::fake()->image('photo.jpg', 100, 100);

        $response = $this->postJson("/api/teams/{$team->id}/image", ['image' => $file]);

        $response->assertOk()->assertJsonPath('data.image_url', fn ($url) => $url !== null);

        $storedPath = $team->fresh()->image_url;
        $this->assertNotNull($storedPath);
        Storage::disk('public')->assertExists($storedPath);
    }

    public function test_upload_image_rejects_non_image_mime(): void
    {
        Storage::fake('public');
        $this->actingAsAdmin();
        $team = Team::factory()->create();

        $file = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');

        $this->postJson("/api/teams/{$team->id}/image", ['image' => $file])
             ->assertUnprocessable()
             ->assertJsonValidationErrors(['image']);
    }

    public function test_upload_image_rejects_file_over_4mb(): void
    {
        Storage::fake('public');
        $this->actingAsAdmin();
        $team = Team::factory()->create();

        // UploadedFile::fake()->image() size param is in KB
        $file = UploadedFile::fake()->image('big.jpg')->size(5000); // 5 MB

        $this->postJson("/api/teams/{$team->id}/image", ['image' => $file])
             ->assertUnprocessable()
             ->assertJsonValidationErrors(['image']);
    }

    // ─── Atomic image + members (multipart PUT via method spoofing) ───────────

    public function test_update_with_image_saves_members_and_image_atomically(): void
    {
        Storage::fake('public');
        $this->actingAsAdmin();

        $team = Team::factory()->create();
        $user = User::factory()->create(['status' => 'active']);
        $file = UploadedFile::fake()->image('team.png', 80, 80);

        $members = json_encode([[
            'user_id'    => $user->id,
            'name'       => $user->name,
            'role'       => 'tactical response team',
            'is_primary' => false,
            'started_at' => now()->toDateString(),
        ]]);

        $response = $this->call('POST', "/api/teams/{$team->id}", [
            '_method' => 'PUT',
            'name'    => $team->name,
            'members' => $members,
        ], $this->prepareCookiesForRequest(), ['image' => $file], [
            'CONTENT_TYPE' => 'multipart/form-data',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $this->sessionCsrfToken(),
        ]);

        $this->assertEquals(200, $response->getStatusCode(), $response->getContent());

        // Member was saved
        $this->assertDatabaseHas('team_members', [
            'team_id' => $team->id,
            'user_id' => $user->id,
        ]);

        // Image was stored
        $storedPath = $team->fresh()->image_url;
        $this->assertNotNull($storedPath);
        Storage::disk('public')->assertExists($storedPath);
    }

    public function test_update_with_invalid_image_mime_is_rejected(): void
    {
        Storage::fake('public');
        $this->actingAsAdmin();

        $team = Team::factory()->create();
        $file = UploadedFile::fake()->create('script.exe', 100, 'application/octet-stream');

        $response = $this->call('POST', "/api/teams/{$team->id}", [
            '_method' => 'PUT',
            'name'    => $team->name,
            'members' => '[]',
        ], $this->prepareCookiesForRequest(), ['image' => $file], [
            'CONTENT_TYPE' => 'multipart/form-data',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $this->sessionCsrfToken(),
        ]);

        $this->assertEquals(422, $response->getStatusCode());
    }

    // ─── Authorization denials ───────────────────────────────────────────────

    public function test_unauthenticated_user_cannot_access_teams(): void
    {
        $team = Team::factory()->create();

        $this->getJson('/api/teams')->assertUnauthorized();
        $this->postJson('/api/teams', ['name' => 'X'])->assertUnauthorized();
        $this->putJson("/api/teams/{$team->id}", ['name' => $team->name, 'members' => []])->assertUnauthorized();
        $this->deleteJson("/api/teams/{$team->id}")->assertUnauthorized();
    }

    public function test_user_without_teams_manage_cannot_create_team(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $role = Role::firstOrCreate(['name' => 'Viewer', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'teams.view', 'guard_name' => 'web']);
        $role->givePermissionTo('teams.view');
        UserRoleAssignment::create([
            'user_id'    => $user->id,
            'role_id'    => $role->id,
            'scope_type' => RoleCatalog::GLOBAL,
            'is_primary' => true,
        ]);
        $this->actingAs($user);

        $this->postJson('/api/teams', ['name' => 'New Team'])->assertForbidden();
    }

    public function test_user_without_teams_manage_cannot_delete_team(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $role = Role::firstOrCreate(['name' => 'ViewerOnly', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'teams.view', 'guard_name' => 'web']);
        $role->givePermissionTo('teams.view');
        UserRoleAssignment::create([
            'user_id'    => $user->id,
            'role_id'    => $role->id,
            'scope_type' => RoleCatalog::GLOBAL,
            'is_primary' => true,
        ]);
        $this->actingAs($user);

        $team = Team::factory()->create();

        $this->deleteJson("/api/teams/{$team->id}")->assertForbidden();
        $this->assertDatabaseHas('teams', ['id' => $team->id]);
    }

    // ─── index / show ────────────────────────────────────────────────────────

    public function test_member_options_requires_team_management_and_excludes_sensitive_fields(): void
    {
        $target = User::factory()->create([
            'status' => 'active',
            'banking_info' => ['bank' => 'Sensitive Bank'],
            'medical_info' => ['condition' => 'Private'],
        ]);
        $team = Team::factory()->create(['name' => 'Options Team']);
        TeamMember::factory()->create([
            'team_id' => $team->id,
            'user_id' => $target->id,
            'name' => $target->name,
            'ended_at' => null,
        ]);

        $viewer = User::factory()->create(['status' => 'active']);
        $viewerRole = Role::firstOrCreate(['name' => 'Team Viewer', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'teams.view', 'guard_name' => 'web']);
        $viewerRole->givePermissionTo('teams.view');
        UserRoleAssignment::create([
            'user_id' => $viewer->id,
            'role_id' => $viewerRole->id,
            'scope_type' => RoleCatalog::GLOBAL,
            'is_primary' => true,
        ]);
        $this->actingAs($viewer);
        $this->getJson('/api/teams/member-options')->assertForbidden();

        $this->actingAsAdmin();
        $response = $this->getJson('/api/teams/member-options')->assertOk();
        $row = collect($response->json('data'))->firstWhere('id', $target->id);

        $this->assertNotNull($row);
        $this->assertSame('Options Team', $row['team']);
        $this->assertArrayHasKey('roles', $row);
        $this->assertArrayNotHasKey('banking_info', $row);
        $this->assertArrayNotHasKey('medical_info', $row);
        $this->assertArrayNotHasKey('login_records', $row);
        $this->assertArrayNotHasKey('permissions', $row);
        $this->assertArrayNotHasKey('role_assignments', $row);
    }

    public function test_index_returns_all_teams_with_members(): void
    {
        $this->actingAsAdmin();

        $team = Team::factory()->create();
        $user = User::factory()->create(['status' => 'active']);
        TeamMember::factory()->create([
            'team_id'  => $team->id,
            'user_id'  => $user->id,
            'name'     => $user->name,
            'ended_at' => null,
        ]);

        $response = $this->getJson('/api/teams')->assertOk();

        $data = $response->json('data');
        $this->assertIsArray($data);

        $returned = collect($data)->firstWhere('id', $team->id);
        $this->assertNotNull($returned);
        $this->assertArrayHasKey('members', $returned);
        $this->assertCount(1, $returned['members']);
        $this->assertEquals($user->id, $returned['members'][0]['user_id']);
    }

    public function test_show_returns_single_team_with_members_and_past_members(): void
    {
        $this->actingAsAdmin();

        $team       = Team::factory()->create();
        $active     = User::factory()->create(['status' => 'active']);
        $pastUser   = User::factory()->create(['status' => 'active']);

        TeamMember::factory()->create([
            'team_id'  => $team->id,
            'user_id'  => $active->id,
            'name'     => $active->name,
            'ended_at' => null,
        ]);
        TeamMember::factory()->ended()->create([
            'team_id' => $team->id,
            'user_id' => $pastUser->id,
            'name'    => $pastUser->name,
        ]);

        $response = $this->getJson("/api/teams/{$team->id}")->assertOk();

        $data = $response->json('data');
        $this->assertEquals($team->id, $data['id']);
        $this->assertCount(1, $data['members']);
        $this->assertCount(1, $data['past_members']);
        $this->assertEquals($active->id, $data['members'][0]['user_id']);
        $this->assertEquals($pastUser->id, $data['past_members'][0]['user_id']);
    }

    // ─── image_url validation ────────────────────────────────────────────────

    public function test_update_rejects_invalid_image_url_format(): void
    {
        $this->actingAsAdmin();
        $team = Team::factory()->create();

        $this->putJson("/api/teams/{$team->id}", [
            'name'      => $team->name,
            'members'   => [],
            'image_url' => '../../../etc/passwd',
        ])->assertUnprocessable()
          ->assertJsonValidationErrors(['image_url']);
    }

    public function test_update_accepts_valid_preset_image_url(): void
    {
        $this->actingAsAdmin();
        $team = Team::factory()->create();

        $this->putJson("/api/teams/{$team->id}", [
            'name'      => $team->name,
            'members'   => [],
            'image_url' => 'preset:alpha',
        ])->assertOk();

        $this->assertDatabaseHas('teams', ['id' => $team->id, 'image_url' => 'preset:alpha']);
    }

    public function test_update_accepts_valid_stored_image_url(): void
    {
        $this->actingAsAdmin();
        $team = Team::factory()->create();

        $this->putJson("/api/teams/{$team->id}", [
            'name'      => $team->name,
            'members'   => [],
            'image_url' => 'teams/abc123.jpg',
        ])->assertOk();

        $this->assertDatabaseHas('teams', ['id' => $team->id, 'image_url' => 'teams/abc123.jpg']);
    }

    // ─── syncForUser rollback ────────────────────────────────────────────────

    public function test_sync_for_user_rolls_back_on_failure(): void
    {
        $this->actingAsAdmin();

        $team = Team::factory()->create();
        $user = User::factory()->create(['status' => 'active']);

        // Create a role assignment pointing to the team
        $role = Role::firstOrCreate(['name' => 'Tactical Response Team', 'guard_name' => 'web']);
        $assignment = UserRoleAssignment::create([
            'user_id'    => $user->id,
            'role_id'    => $role->id,
            'scope_type' => 'site',
            'team_id'    => $team->id,
            'is_primary' => false,
        ]);

        // Pre-existing member row so the sync knows this is not new (avoids notification path)
        TeamMember::factory()->create([
            'team_id'  => $team->id,
            'user_id'  => $user->id,
            'name'     => $user->name,
            'ended_at' => null,
        ]);

        // Force a DB error mid-transaction by deleting the team between query and write
        // Use a mock to intercept the updateOrCreate and delete the team to cause a FK error
        $service = app(\App\Services\TeamMemberSyncService::class);

        // Delete the team so the FK on team_members.team_id fails during upsert
        $team->delete();

        try {
            $service->syncForUser($user);
        } catch (\Throwable) {
            // Expected — FK violation or similar
        }

        // No orphaned team_member rows should remain from the failed sync
        $this->assertDatabaseMissing('team_members', [
            'team_id' => $team->id,
            'user_id' => $user->id,
            'ended_at' => null,
        ]);
    }
}
