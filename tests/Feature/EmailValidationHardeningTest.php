<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class EmailValidationHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_global_email_rule_rejects_crlf_injection(): void
    {
        $validator = Validator::make([
            'email' => "person@example.com\r\nBcc: attacker@example.com",
        ], [
            'email' => ['required', 'email'],
        ]);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('email', $validator->errors()->messages());
    }

    public function test_global_email_rule_accepts_normal_email_address(): void
    {
        $validator = Validator::make([
            'email' => 'person@example.com',
        ], [
            'email' => ['required', 'email'],
        ]);

        $this->assertFalse($validator->fails());
    }

    public function test_login_rejects_crlf_email_before_attempt_logging(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'email' => "person@example.com\r\nBcc: attacker@example.com",
            'password' => 'not-used',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['email']);
        $this->assertDatabaseCount('login_attempts', 0);
    }
}
