<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class PayrollCompanyProfileSettingsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_with_permission_can_read_and_update_payroll_company_profile(): void
    {
        $user = User::factory()->create(['status' => 'Active']);
        $this->grantPermission($user, 'staff.salary.manage');

        $this->actingAs($user)
            ->getJson('/api/settings/payroll-company-profile')
            ->assertOk()
            ->assertJsonPath('data.legalName', '')
            ->assertJsonPath('data.registrationNumber', '')
            ->assertJsonPath('data.myTaxNumber', '');

        $payload = [
            'legalName' => 'AmiOSHc Sdn Bhd',
            'registrationNumber' => '202601234567 (1456789-X)',
            'myTaxNumber' => 'MYTAX-778899',
            'address' => 'No 1, Jalan Test, Kuala Lumpur',
            'email' => 'payroll@example.com',
            'phone' => '+60-3-1234-5678',
            'financeContactName' => 'Finance Team',
            'financeContactEmail' => 'finance@example.com',
            'financeContactPhone' => '+60-12-345-6789',
        ];

        $response = $this->actingAs($user)
            ->postJson('/api/settings/payroll-company-profile', $payload)
            ->assertOk()
            ->assertJsonPath('data.legalName', $payload['legalName'])
            ->assertJsonPath('data.registrationNumber', $payload['registrationNumber'])
            ->assertJsonPath('data.myTaxNumber', $payload['myTaxNumber'])
            ->assertJsonPath('data.address', $payload['address'])
            ->assertJsonPath('data.email', $payload['email'])
            ->assertJsonPath('data.phone', $payload['phone'])
            ->assertJsonPath('data.financeContactName', $payload['financeContactName'])
            ->assertJsonPath('data.financeContactEmail', $payload['financeContactEmail'])
            ->assertJsonPath('data.financeContactPhone', $payload['financeContactPhone']);

        $history = $response->json('data.history');
        $this->assertIsArray($history);
        $this->assertNotEmpty($history);
        $last = $history[count($history) - 1];
        $this->assertSame($payload['financeContactName'], $last['financeContactName'] ?? null);
        $this->assertSame($payload['myTaxNumber'], $last['myTaxNumber'] ?? null);
        $this->assertSame($payload['financeContactEmail'], $last['financeContactEmail'] ?? null);
        $this->assertSame($payload['financeContactPhone'], $last['financeContactPhone'] ?? null);
    }

    private function grantPermission(User $user, string $permissionName): void
    {
        $permission = Permission::query()->firstOrCreate([
            'name' => $permissionName,
            'guard_name' => 'web',
        ]);
        $user->givePermissionTo($permission);
    }
}
