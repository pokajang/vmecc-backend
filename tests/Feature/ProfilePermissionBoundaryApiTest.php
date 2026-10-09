<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProfilePermissionBoundaryApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_restricted_profile_fields_are_filtered_and_cannot_be_mutated_without_permission(): void
    {
        $user = User::factory()->create([
            'status' => 'Active',
            'emergency_contact' => ['name' => 'Private contact'],
            'banking_info' => ['bankName' => 'Private bank'],
            'medical_info' => ['bloodType' => 'O+'],
        ]);

        $this->actingAs($user)
            ->putJson('/api/profile', ['name' => 'Allowed account update'])
            ->assertOk()
            ->assertJsonPath('user.name', 'Allowed account update')
            ->assertJsonPath('user.emergency_contact', null)
            ->assertJsonPath('user.banking_info', null)
            ->assertJsonPath('user.medical_info', null);

        foreach (['emergency_contact', 'banking_info', 'medical_info'] as $field) {
            $this->actingAs($user)
                ->putJson('/api/profile', [$field => []])
                ->assertForbidden()
                ->assertJsonPath('message', 'You do not have permission to update this profile section.');
        }

        $user->refresh();
        $this->assertSame('Private contact', data_get($user->emergency_contact, 'name'));
        $this->assertSame('Private bank', data_get($user->banking_info, 'bankName'));
        $this->assertSame('O+', data_get($user->medical_info, 'bloodType'));
    }

    public function test_each_sensitive_profile_permission_discloses_only_its_own_field(): void
    {
        $user = User::factory()->create([
            'status' => 'Active',
            'emergency_contact' => ['name' => 'Emergency visible'],
            'banking_info' => ['bankName' => 'Bank hidden'],
            'medical_info' => ['bloodType' => 'A+'],
        ]);
        $permission = Permission::findOrCreate('self.profile.emergency', 'web');
        $user->givePermissionTo($permission);

        $this->actingAs($user)
            ->putJson('/api/profile', ['emergency_contact' => ['name' => 'Emergency updated']])
            ->assertOk()
            ->assertJsonPath('user.emergency_contact.name', 'Emergency updated')
            ->assertJsonPath('user.banking_info', null)
            ->assertJsonPath('user.medical_info', null);
    }

    public function test_system_administrator_bypass_includes_sensitive_profile_fields(): void
    {
        $user = User::factory()->create([
            'status' => 'Active',
            'emergency_contact' => ['name' => 'Emergency visible'],
            'banking_info' => ['bankName' => 'Bank visible'],
            'medical_info' => ['bloodType' => 'B+'],
        ]);
        $user->assignRole(Role::findOrCreate('System Administrator', 'web'));

        $this->actingAs($user)
            ->putJson('/api/profile', ['name' => 'Administrator'])
            ->assertOk()
            ->assertJsonPath('user.emergency_contact.name', 'Emergency visible')
            ->assertJsonPath('user.banking_info.bankName', 'Bank visible')
            ->assertJsonPath('user.medical_info.bloodType', 'B+');
    }
}
