<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordResetSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_forgot_password_uses_the_same_generic_response_for_known_and_unknown_accounts(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $expectedMessage = 'If an account exists for that email, a password reset link has been sent.';

        $this->postJson('/api/password/forgot', ['email' => $user->email])
            ->assertOk()
            ->assertJsonPath('message', $expectedMessage);

        $this->postJson('/api/password/forgot', ['email' => 'missing-user@example.test'])
            ->assertOk()
            ->assertJsonPath('message', $expectedMessage);

        Notification::assertSentTo($user, ResetPassword::class);
    }
}
