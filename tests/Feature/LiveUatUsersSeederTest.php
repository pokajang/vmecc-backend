<?php

namespace Tests\Feature;

use App\Models\Team;
use App\Models\User;
use Database\Seeders\LiveUatUsersCleanupSeeder;
use Database\Seeders\LiveUatUsersSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

class LiveUatUsersSeederTest extends TestCase
{
    use RefreshDatabase;

    private Team $siteTeam;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->siteTeam = Team::query()->create([
            'name' => 'Production-shaped UAT Site',
            'group' => 'site',
            'status' => 'Active',
        ]);
        $this->configurePersonas();
    }

    public function test_it_creates_six_idempotent_role_scoped_uat_accounts(): void
    {
        $this->seed(LiveUatUsersSeeder::class);
        $this->seed(LiveUatUsersSeeder::class);

        $this->assertSame(6, User::query()->where('name', 'like', '[Live UAT]%')->count());
        foreach (config('live_uat.personas') as $key => $persona) {
            $user = User::query()->where('email', $persona['email'])->firstOrFail();
            $this->assertSame($persona['name'], $user->name);
            $this->assertSame('Active', $user->status);
            $this->assertTrue(Hash::check($persona['password'], $user->password));
            $this->assertTrue($user->hasRole($persona['role']));
            $this->assertSame(1, $user->roleAssignments()->count(), $key);
            $this->assertDatabaseHas('user_role_assignments', [
                'user_id' => $user->id,
                'scope_type' => $persona['scope'],
                'team_id' => $persona['requires_team'] ? $this->siteTeam->id : null,
                'is_primary' => true,
            ]);
            $this->assertSame(
                $persona['requires_team'] ? 1 : 0,
                $user->id ? (int) DB::table('team_members')->where('user_id', $user->id)->count() : 0,
            );
        }
    }

    public function test_it_refuses_to_overwrite_an_existing_non_uat_user(): void
    {
        $email = config('live_uat.personas.trt.email');
        User::factory()->create(['email' => $email, 'name' => 'Actual Employee']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Refusing to overwrite non-UAT user');

        $this->seed(LiveUatUsersSeeder::class);
    }

    public function test_it_requires_explicit_production_permission(): void
    {
        $this->app['env'] = 'production';
        config()->set('live_uat.allow_production', false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('LIVE_UAT_USERS_ALLOW_PRODUCTION');

        $this->seed(LiveUatUsersSeeder::class);
    }

    public function test_cleanup_revokes_access_and_soft_deletes_only_marked_accounts(): void
    {
        $this->seed(LiveUatUsersSeeder::class);
        $this->seed(LiveUatUsersCleanupSeeder::class);

        foreach (config('live_uat.personas') as $persona) {
            $user = User::withTrashed()->where('email', $persona['email'])->firstOrFail();
            $this->assertTrue($user->trashed());
            $this->assertSame('Inactive', $user->status);
            $this->assertDatabaseMissing('user_role_assignments', ['user_id' => $user->id]);
            $this->assertDatabaseMissing('team_members', ['user_id' => $user->id]);
        }
    }

    private function configurePersonas(): void
    {
        $personas = config('live_uat.personas');
        foreach ($personas as $key => &$persona) {
            $persona['email'] = "live-uat-{$key}@example.test";
            $persona['password'] = "Unique-UAT-Password-{$key}-2026";
        }
        unset($persona);

        config()->set('live_uat.enabled', true);
        config()->set('live_uat.allow_production', false);
        config()->set('live_uat.site_team_id', $this->siteTeam->id);
        config()->set('live_uat.personas', $personas);
    }
}
