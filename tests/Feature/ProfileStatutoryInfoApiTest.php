<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileStatutoryInfoApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_update_statutory_info_via_profile_endpoint(): void
    {
        $user = User::factory()->create([
            'status' => 'Active',
            'statutory_info' => null,
        ]);

        $payload = [
            'statutory_info' => [
                'epfNo' => 'EPF-123456',
                'perkesoNo' => 'SOC-987654',
                'incomeTaxNo' => 'TAX-445566',
            ],
        ];

        $response = $this->actingAs($user)
            ->putJson('/api/profile', $payload)
            ->assertOk()
            ->assertJsonPath('user.statutory_info.epfNo', 'EPF-123456')
            ->assertJsonPath('user.statutory_info.perkesoNo', 'SOC-987654')
            ->assertJsonPath('user.statutory_info.incomeTaxNo', 'TAX-445566');

        $updated = User::query()->findOrFail($user->id);
        $this->assertSame('EPF-123456', data_get($updated->statutory_info, 'epfNo'));
        $this->assertSame('SOC-987654', data_get($updated->statutory_info, 'perkesoNo'));
        $this->assertSame('TAX-445566', data_get($updated->statutory_info, 'incomeTaxNo'));

        $response->assertJsonPath('user.id', $user->id);
    }

    public function test_user_can_update_ic_number_via_profile_endpoint(): void
    {
        $user = User::factory()->create([
            'status' => 'Active',
            'ic_number' => null,
        ]);

        $this->actingAs($user)
            ->putJson('/api/profile', ['ic_number' => '900101-01-1234'])
            ->assertOk()
            ->assertJsonPath('user.ic_number', '900101-01-1234');

        $updated = User::query()->findOrFail($user->id);
        $this->assertSame('900101-01-1234', (string) $updated->ic_number);
    }
}
