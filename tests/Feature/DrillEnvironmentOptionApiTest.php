<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserRoleAssignment;
use App\Services\RoleCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DrillEnvironmentOptionApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_users_can_replace_and_read_only_their_own_options(): void
    {
        $alpha = User::factory()->create(['status' => 'active']);
        $beta = User::factory()->create(['status' => 'active']);
        $this->grantDrillAccess($alpha, 'AIC Alpha');
        $this->grantDrillAccess($beta, 'AIC Beta');

        $this->actingAs($alpha)
            ->getJson('/api/reports/drill/environment-options')
            ->assertOk()
            ->assertJsonPath('data', []);

        $this->actingAs($alpha)
            ->putJson('/api/reports/drill/environment-options', [
                'options' => [
                    [
                        'value' => 'Tunnel Environment',
                        'title' => 'Tunnel Environment',
                        'description' => 'Low-light confined operations.',
                        'iconKey' => 'LampDesk',
                    ],
                    [
                        'value' => 'Marine Deck',
                        'title' => 'Marine Deck',
                        'description' => 'Exposed deck operations.',
                        'icon_key' => 'Waves',
                    ],
                    [
                        'value' => 'Tunnel Environment',
                        'title' => 'Tunnel Environment Updated',
                        'description' => 'Latest duplicate wins.',
                        'iconKey' => 'Flashlight',
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Drill environment options saved.')
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.value', 'Marine Deck')
            ->assertJsonPath('data.1.value', 'Tunnel Environment')
            ->assertJsonPath('data.1.title', 'Tunnel Environment Updated')
            ->assertJsonPath('data.1.icon_key', 'Flashlight');

        $this->actingAs($alpha)
            ->getJson('/api/reports/drill/environment-options')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.value', 'Marine Deck')
            ->assertJsonPath('data.0.iconKey', 'Waves')
            ->assertJsonPath('data.1.value', 'Tunnel Environment')
            ->assertJsonPath('data.1.iconKey', 'Flashlight');

        $this->actingAs($beta)
            ->getJson('/api/reports/drill/environment-options')
            ->assertOk()
            ->assertJsonPath('data', []);

        $this->actingAs($beta)
            ->putJson('/api/reports/drill/environment-options', [
                'options' => [[
                    'value' => 'Beta Yard',
                    'title' => 'Beta Yard',
                    'description' => 'Beta-only environment.',
                    'iconKey' => 'Warehouse',
                ]],
            ])
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->actingAs($alpha)
            ->getJson('/api/reports/drill/environment-options')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonMissing(['value' => 'Beta Yard']);

        $this->assertDatabaseCount('report_drill_environment_options', 3);

        $this->actingAs($alpha)
            ->putJson('/api/reports/drill/environment-options', ['options' => []])
            ->assertOk()
            ->assertJsonPath('data', []);

        $this->actingAs($alpha)
            ->getJson('/api/reports/drill/environment-options')
            ->assertOk()
            ->assertJsonPath('data', []);

        $this->actingAs($beta)
            ->getJson('/api/reports/drill/environment-options')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.value', 'Beta Yard');

        $this->assertDatabaseCount('report_drill_environment_options', 1);
    }

    public function test_unauthorized_user_cannot_read_or_replace_options(): void
    {
        $user = User::factory()->create(['status' => 'active']);

        $this->actingAs($user)
            ->getJson('/api/reports/drill/environment-options')
            ->assertForbidden();

        $this->actingAs($user)
            ->putJson('/api/reports/drill/environment-options', ['options' => []])
            ->assertForbidden();
    }

    public function test_replace_validates_rows_and_enforces_the_environment_limit(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->grantDrillAccess($user, 'AIC Limit');
        $this->actingAs($user);

        $this->putJson('/api/reports/drill/environment-options', [
            'options' => [['value' => str_repeat('x', 141)]],
        ])->assertUnprocessable()->assertJsonValidationErrors(['options.0.value']);

        $options = collect(range(1, 101))->map(fn (int $index): array => [
            'value' => "Environment {$index}",
            'title' => "Environment {$index}",
        ])->all();

        $this->putJson('/api/reports/drill/environment-options', ['options' => $options])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'drill_environment_option_limit_reached');

        $this->assertDatabaseCount('report_drill_environment_options', 0);
    }

    private function grantDrillAccess(User $user, string $roleName): void
    {
        $permission = Permission::query()->firstOrCreate([
            'name' => 'reports.drill.view',
            'guard_name' => 'web',
        ]);
        $role = Role::query()->firstOrCreate([
            'name' => $roleName,
            'guard_name' => 'web',
        ]);
        if (! $role->hasPermissionTo($permission)) {
            $role->givePermissionTo($permission);
        }

        UserRoleAssignment::query()->create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'scope_type' => RoleCatalog::GLOBAL,
            'is_primary' => true,
        ]);
    }
}
