<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Models\UserRoleAssignment;
use App\Services\RoleCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OvertimePolicyEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_policy_endpoint_requires_authentication(): void
    {
        $this->getJson('/api/overtime/policy')->assertUnauthorized();
    }

    public function test_policy_endpoint_returns_normalized_default_policy_for_authenticated_user(): void
    {
        $user = $this->createOvertimeUser();
        $this->actingAs($user);

        $response = $this->getJson('/api/overtime/policy')->assertOk();
        $data = $response->json('data');

        $this->assertIsArray($data);
        $this->assertArrayHasKey('workflow', $data);
        $this->assertArrayHasKey('typeVisibility', $data);
        $this->assertArrayHasKey('fallback', $data['workflow']);
        $this->assertArrayHasKey('options', $data['workflow']);
        $this->assertArrayHasKey('rules', $data['workflow']);

        $this->assertIsBool(data_get($data, 'typeVisibility.weekday'));
        $this->assertIsBool(data_get($data, 'typeVisibility.weekend'));
        $this->assertIsBool(data_get($data, 'typeVisibility.publicHoliday'));
        $this->assertIsBool(data_get($data, 'workflow.options.requireRecommendation'));
        $this->assertIsBool(data_get($data, 'workflow.options.enforceDistinctApprovers'));
    }

    public function test_policy_endpoint_normalizes_stored_policy_shape(): void
    {
        Setting::create([
            'key' => 'overtime_approval_rules',
            'value' => [
                'workflow' => [
                    'rules' => [
                        [
                            'id' => 'ot-rule-ic',
                            'applicantRole' => 'Incident Commander',
                            'reviewRole' => 'Contract Manager',
                            'recommendRole' => 'Human Resource',
                            'approveRole' => 'Client Contract Manager',
                            'active' => true,
                        ],
                    ],
                    'fallback' => [
                        'reviewRole' => 'Contract Manager',
                        'recommendRole' => 'Human Resource',
                        'approveRole' => 'Client Contract Manager',
                    ],
                    'options' => [
                        'requireRecommendation' => false,
                        'enforceDistinctApprovers' => true,
                    ],
                ],
                'typeVisibility' => [
                    'weekday' => false,
                    'weekend' => false,
                    'publicHoliday' => false,
                ],
            ],
        ]);

        $user = $this->createOvertimeUser();
        $this->actingAs($user);

        $response = $this->getJson('/api/overtime/policy')->assertOk();
        $data = $response->json('data');

        $this->assertSame(false, data_get($data, 'workflow.options.requireRecommendation'));
        $this->assertSame(true, data_get($data, 'workflow.options.enforceDistinctApprovers'));
        $this->assertNotEmpty(data_get($data, 'workflow.rules'));
        $this->assertSame(true, data_get($data, 'typeVisibility.weekday'));
        $this->assertSame(true, data_get($data, 'typeVisibility.weekend'));
        $this->assertSame(true, data_get($data, 'typeVisibility.publicHoliday'));
    }

    private function createOvertimeUser(): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $role = Role::firstOrCreate(['name' => 'Tactical Response Team', 'guard_name' => 'web']);
        $permission = Permission::firstOrCreate(['name' => 'self.overtime', 'guard_name' => 'web']);
        $role->givePermissionTo($permission);

        UserRoleAssignment::create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'scope_type' => RoleCatalog::GLOBAL,
            'is_primary' => true,
        ]);

        return $user;
    }
}
