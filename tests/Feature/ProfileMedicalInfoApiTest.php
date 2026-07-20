<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileMedicalInfoApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_confirm_no_known_critical_medical_info(): void
    {
        $user = User::factory()->create([
            'status' => 'Active',
            'medical_info' => null,
        ]);

        $medicalInfo = [
            'noKnownCriticalMedicalInfo' => true,
            'bloodType' => '',
            'allergies' => [],
            'conditions' => [],
            'medications' => [],
            'notes' => '',
        ];

        $this->actingAs($user)
            ->putJson('/api/profile', ['medical_info' => $medicalInfo])
            ->assertOk()
            ->assertJsonPath('user.medical_info.noKnownCriticalMedicalInfo', true);

        $updated = User::query()->findOrFail($user->id);
        $this->assertTrue(data_get($updated->medical_info, 'noKnownCriticalMedicalInfo'));
    }
}
