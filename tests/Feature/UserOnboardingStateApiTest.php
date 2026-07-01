<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserOnboardingState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserOnboardingStateApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_fetch_empty_onboarding_states(): void
    {
        $user = User::factory()->create(['status' => 'Active']);

        $this->actingAs($user)
            ->getJson('/api/onboarding/states')
            ->assertOk()
            ->assertExactJson(['data' => []]);
    }

    public function test_user_can_persist_onboarding_events(): void
    {
        $user = User::factory()->create(['status' => 'Active']);

        $this->actingAs($user)
            ->postJson('/api/onboarding/states/inspection_quick_tour_trt', [
                'version' => 'v1',
                'event' => 'started',
                'payload' => [
                    'source' => 'inspection_prompt',
                    'targetNotFoundStepKeys' => ['filters'],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.inspection_quick_tour_trt.version', 'v1')
            ->assertJsonPath('data.inspection_quick_tour_trt.payload.source', 'inspection_prompt')
            ->assertJsonPath('data.inspection_quick_tour_trt.payload.targetNotFoundStepKeys.0', 'filters');

        $state = UserOnboardingState::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertNotNull($state->last_started_at);
        $this->assertSame('inspection_prompt', $state->payload['source']);

        $this->actingAs($user)
            ->postJson('/api/onboarding/states/inspection_quick_tour_trt', [
                'version' => 'v1',
                'event' => 'completed',
            ])
            ->assertOk()
            ->assertJsonPath('data.inspection_quick_tour_trt.dismissedAt', null)
            ->assertJsonPath('data.inspection_quick_tour_trt.snoozedUntil', null);

        $state->refresh();
        $this->assertNotNull($state->completed_at);
        $this->assertNull($state->dismissed_at);
        $this->assertNull($state->snoozed_until);

        $this->actingAs($user)
            ->postJson('/api/onboarding/states/profile_completion_trt', [
                'version' => 'v1',
                'event' => 'snoozed',
                'snoozedUntil' => now()->addDay()->toJSON(),
            ])
            ->assertOk()
            ->assertJsonPath('data.profile_completion_trt.version', 'v1');

        $profileState = UserOnboardingState::query()
            ->where('user_id', $user->id)
            ->where('key', 'profile_completion_trt')
            ->firstOrFail();
        $this->assertNotNull($profileState->snoozed_until);

        $this->actingAs($user)
            ->postJson('/api/onboarding/states/profile_completion_trt', [
                'version' => 'v1',
                'event' => 'dismissed',
            ])
            ->assertOk()
            ->assertJsonPath('data.profile_completion_trt.snoozedUntil', null);

        $profileState->refresh();
        $this->assertNotNull($profileState->dismissed_at);
        $this->assertNull($profileState->snoozed_until);
    }

    public function test_onboarding_state_is_user_scoped(): void
    {
        $user = User::factory()->create(['status' => 'Active']);
        $other = User::factory()->create(['status' => 'Active']);

        UserOnboardingState::query()->create([
            'user_id' => $other->id,
            'key' => 'inspection_quick_tour_trt',
            'version' => 'v1',
            'completed_at' => now(),
        ]);

        $this->actingAs($user)
            ->getJson('/api/onboarding/states')
            ->assertOk()
            ->assertExactJson(['data' => []]);
    }

    public function test_onboarding_state_validation_rejects_invalid_input(): void
    {
        $user = User::factory()->create(['status' => 'Active']);

        $this->actingAs($user)
            ->postJson('/api/onboarding/states/not_allowed', [
                'version' => 'v1',
                'event' => 'started',
            ])
            ->assertStatus(422);

        $this->actingAs($user)
            ->postJson('/api/onboarding/states/inspection_quick_tour_trt', [
                'version' => 'v2',
                'event' => 'started',
            ])
            ->assertStatus(422);

        $this->actingAs($user)
            ->postJson('/api/onboarding/states/inspection_quick_tour_trt', [
                'version' => 'v1',
                'event' => 'unknown',
            ])
            ->assertStatus(422);

        $this->actingAs($user)
            ->postJson('/api/onboarding/states/profile_completion_trt', [
                'version' => 'v1',
                'event' => 'snoozed',
                'snoozedUntil' => 'not-a-date',
            ])
            ->assertStatus(422);
    }

    public function test_auth_session_includes_onboarding_state(): void
    {
        $user = User::factory()->create(['status' => 'Active']);
        UserOnboardingState::query()->create([
            'user_id' => $user->id,
            'key' => 'inspection_quick_tour_trt',
            'version' => 'v1',
            'completed_at' => now(),
        ]);

        $this->actingAs($user)
            ->getJson('/api/auth/session')
            ->assertOk()
            ->assertJsonPath('user.onboarding.inspection_quick_tour_trt.version', 'v1')
            ->assertJsonPath('user.onboarding.inspection_quick_tour_trt.dismissedAt', null);
    }
}
