<?php

namespace Tests;

use App\Models\UserSession;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Str;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected ?string $testCsrfToken = null;

    public function actingAs(Authenticatable $user, $guard = null): static
    {
        parent::actingAs($user, $guard);

        $token = Str::random(64);
        $this->testCsrfToken = $token;
        $session = UserSession::create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->getAuthIdentifier(),
            'ip_address' => '127.0.0.1',
            'user_agent' => 'phpunit',
            'last_seen_at' => now(),
            'expires_at' => now()->addHours(2),
            'csrf_token_hash' => hash('sha256', $token),
        ]);

        $this->withUnencryptedCookie('vmecc_session', $session->id);
        $this->withHeader('X-CSRF-Token', $token);
        $this->withCredentials();

        return $this;
    }

    protected function sessionCsrfToken(): ?string
    {
        return $this->testCsrfToken;
    }
}
