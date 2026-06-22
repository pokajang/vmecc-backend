<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Laravel\Socialite\Facades\Socialite;
use Mockery;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\TestCase;

class AuthSessionCookieTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_and_logout_use_matching_session_cookie_attributes(): void
    {
        config([
            'session.domain' => '.amiosh.com',
            'session.secure' => true,
        ]);

        $user = User::factory()->create([
            'email' => 'admin@example.test',
            'status' => 'Active',
        ]);

        $loginResponse = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $loginResponse->assertOk();
        $loginCookie = $this->findSessionCookie($loginResponse->headers->getCookies());
        $this->assertNotNull($loginCookie);
        $this->assertNull($this->findRememberCookie($loginResponse->headers->getCookies()));
        $this->assertSame('.amiosh.com', $loginCookie->getDomain());
        $this->assertSame('/', $loginCookie->getPath());
        $this->assertTrue($loginCookie->isSecure());
        $this->assertTrue($loginCookie->isHttpOnly());
        $this->assertSame('lax', strtolower((string) $loginCookie->getSameSite()));

        $logoutResponse = $this
            ->withCredentials()
            ->withUnencryptedCookie('vmecc_session', $loginCookie->getValue())
            ->postJson('/api/auth/logout');

        $logoutResponse->assertOk();
        $logoutCookie = $this->findSessionCookie($logoutResponse->headers->getCookies());
        $this->assertNotNull($logoutCookie);
        $this->assertSame('', $logoutCookie->getValue());
        $this->assertLessThan(time(), $logoutCookie->getExpiresTime());
        $this->assertSame($loginCookie->getDomain(), $logoutCookie->getDomain());
        $this->assertSame($loginCookie->getPath(), $logoutCookie->getPath());
        $this->assertSame($loginCookie->isSecure(), $logoutCookie->isSecure());
        $this->assertSame($loginCookie->isHttpOnly(), $logoutCookie->isHttpOnly());
        $this->assertSame($loginCookie->getSameSite(), $logoutCookie->getSameSite());
    }

    public function test_login_with_remember_issues_remember_cookie_and_stores_only_token_hash(): void
    {
        config([
            'session.domain' => '.amiosh.com',
            'session.secure' => true,
            'session.lifetime' => 720,
            'session.remember_days' => 30,
        ]);

        $user = User::factory()->create([
            'email' => 'remember@example.test',
            'status' => 'Active',
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
            'remember' => true,
        ]);

        $response->assertOk();
        $sessionCookie = $this->findSessionCookie($response->headers->getCookies());
        $rememberCookie = $this->findRememberCookie($response->headers->getCookies());
        $this->assertNotNull($sessionCookie);
        $this->assertNotNull($rememberCookie);
        $this->assertSame('.amiosh.com', $rememberCookie->getDomain());
        $this->assertTrue($rememberCookie->isSecure());
        $this->assertTrue($rememberCookie->isHttpOnly());
        $this->assertSame('lax', strtolower((string) $rememberCookie->getSameSite()));

        $session = UserSession::where('user_id', $user->id)->firstOrFail();
        $this->assertNotNull($session->remember_token_hash);
        $this->assertNotNull($session->remember_expires_at);
        $this->assertStringNotContainsString((string) $session->remember_token_hash, (string) $rememberCookie->getValue());
        $this->assertTrue($session->expires_at->between(now()->addMinutes(719), now()->addMinutes(721)));
        $this->assertTrue($session->remember_expires_at->between(now()->addDays(29)->addHours(23), now()->addDays(30)->addHour()));
    }

    public function test_valid_remember_cookie_restores_expired_session_and_rotates_token(): void
    {
        $user = User::factory()->create([
            'email' => 'restore@example.test',
            'status' => 'Active',
        ]);

        $loginResponse = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
            'remember' => true,
        ]);

        $sessionCookie = $this->findSessionCookie($loginResponse->headers->getCookies());
        $rememberCookie = $this->findRememberCookie($loginResponse->headers->getCookies());
        $this->assertNotNull($sessionCookie);
        $this->assertNotNull($rememberCookie);

        $session = UserSession::where('user_id', $user->id)->firstOrFail();
        $session->forceFill(['expires_at' => now()->subMinute()])->save();

        $sessionResponse = $this
            ->withCredentials()
            ->withUnencryptedCookie('vmecc_session', $sessionCookie->getValue())
            ->withUnencryptedCookie('vmecc_remember', $rememberCookie->getValue())
            ->getJson('/api/auth/session');

        $sessionResponse
            ->assertOk()
            ->assertJsonStructure(['csrf_token', 'user' => ['id']]);

        $rotatedRememberCookie = $this->findRememberCookie($sessionResponse->headers->getCookies());
        $this->assertNotNull($rotatedRememberCookie);
        $this->assertNotSame($rememberCookie->getValue(), $rotatedRememberCookie->getValue());
        $this->assertTrue($session->fresh()->expires_at->isFuture());
    }

    public function test_invalid_or_expired_remember_cookie_returns_unauthenticated(): void
    {
        $user = User::factory()->create([
            'email' => 'expired-remember@example.test',
            'status' => 'Active',
        ]);

        $loginResponse = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
            'remember' => true,
        ]);

        $sessionCookie = $this->findSessionCookie($loginResponse->headers->getCookies());
        $rememberCookie = $this->findRememberCookie($loginResponse->headers->getCookies());
        $this->assertNotNull($sessionCookie);
        $this->assertNotNull($rememberCookie);

        UserSession::where('user_id', $user->id)->update([
            'expires_at' => now()->subMinute(),
            'remember_expires_at' => now()->subMinute(),
        ]);

        $response = $this
            ->withCredentials()
            ->withUnencryptedCookie('vmecc_session', $sessionCookie->getValue())
            ->withUnencryptedCookie('vmecc_remember', $rememberCookie->getValue())
            ->getJson('/api/auth/session');

        $response->assertUnauthorized();
        $clearedRememberCookie = $this->findRememberCookie($response->headers->getCookies());
        $this->assertNotNull($clearedRememberCookie);
        $this->assertSame('', $clearedRememberCookie->getValue());
    }

    public function test_logout_and_password_change_clear_remembered_access(): void
    {
        $user = User::factory()->create([
            'email' => 'clear-remember@example.test',
            'status' => 'Active',
        ]);

        $loginResponse = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
            'remember' => true,
        ]);

        $sessionCookie = $this->findSessionCookie($loginResponse->headers->getCookies());
        $rememberCookie = $this->findRememberCookie($loginResponse->headers->getCookies());
        $this->assertNotNull($sessionCookie);
        $this->assertNotNull($rememberCookie);

        $logoutResponse = $this
            ->withCredentials()
            ->withUnencryptedCookie('vmecc_session', $sessionCookie->getValue())
            ->withUnencryptedCookie('vmecc_remember', $rememberCookie->getValue())
            ->postJson('/api/auth/logout');

        $logoutResponse->assertOk();
        $this->assertSame('', $this->findRememberCookie($logoutResponse->headers->getCookies())?->getValue());
        $this->assertNull(UserSession::where('user_id', $user->id)->firstOrFail()->remember_token_hash);

        $secondLoginResponse = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
            'remember' => true,
        ]);
        $secondSessionCookie = $this->findSessionCookie($secondLoginResponse->headers->getCookies());
        $secondRememberCookie = $this->findRememberCookie($secondLoginResponse->headers->getCookies());
        $this->assertNotNull($secondSessionCookie);
        $this->assertNotNull($secondRememberCookie);

        $passwordResponse = $this
            ->withCredentials()
            ->withUnencryptedCookie('vmecc_session', $secondSessionCookie->getValue())
            ->withUnencryptedCookie('vmecc_remember', $secondRememberCookie->getValue())
            ->withHeader('X-CSRF-Token', (string) $secondLoginResponse->json('csrf_token'))
            ->postJson('/api/auth/password', [
                'current_password' => 'password',
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ]);

        $passwordResponse->assertOk();
        $this->assertSame('', $this->findRememberCookie($passwordResponse->headers->getCookies())?->getValue());
        $this->assertSame(0, UserSession::where('user_id', $user->id)->whereNotNull('remember_token_hash')->count());
    }

    public function test_failed_login_lock_clears_remembered_access(): void
    {
        $user = User::factory()->create([
            'email' => 'lock-remember@example.test',
            'status' => 'Active',
        ]);

        $loginResponse = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
            'remember' => true,
        ]);

        $loginResponse->assertOk();
        $this->assertSame(1, UserSession::where('user_id', $user->id)->whereNotNull('remember_token_hash')->count());

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/auth/login', [
                'email' => $user->email,
                'password' => 'wrong-password',
            ])->assertUnprocessable();
        }

        $this->assertNotNull($user->fresh()->locked_at);
        $this->assertSame(0, UserSession::where('user_id', $user->id)->whereNotNull('remember_token_hash')->count());
        $this->assertSame(0, UserSession::where('user_id', $user->id)->whereNull('revoked_at')->count());
    }

    public function test_google_callback_uses_shared_session_cookie_attributes_and_active_status_check(): void
    {
        config([
            'app.frontend_url' => 'https://vmecc.amiosh.com',
            'session.domain' => '.amiosh.com',
            'session.secure' => true,
        ]);

        $user = User::factory()->create([
            'email' => 'google@example.test',
            'status' => 'active',
        ]);

        $googleUser = new class ($user->email) {
            public function __construct(private string $email)
            {
            }

            public function getEmail(): string
            {
                return $this->email;
            }
        };

        $provider = Mockery::mock();
        $provider->shouldReceive('stateless')->once()->andReturnSelf();
        $provider->shouldReceive('user')->once()->andReturn($googleUser);
        Socialite::shouldReceive('driver')->once()->with('google')->andReturn($provider);

        $state = Crypt::encryptString(json_encode(['remember' => true]));
        $response = $this->get('/api/auth/google/callback?state=' . urlencode($state));

        $response->assertRedirect('https://vmecc.amiosh.com/login?status=success');
        $cookie = $this->findSessionCookie($response->headers->getCookies());
        $rememberCookie = $this->findRememberCookie($response->headers->getCookies());
        $this->assertNotNull($cookie);
        $this->assertNotNull($rememberCookie);
        $this->assertSame('.amiosh.com', $cookie->getDomain());
        $this->assertTrue($cookie->isSecure());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', strtolower((string) $cookie->getSameSite()));
    }

    /**
     * @param array<int, Cookie> $cookies
     */
    private function findSessionCookie(array $cookies): ?Cookie
    {
        foreach ($cookies as $cookie) {
            if ($cookie->getName() === 'vmecc_session') {
                return $cookie;
            }
        }

        return null;
    }

    /**
     * @param array<int, Cookie> $cookies
     */
    private function findRememberCookie(array $cookies): ?Cookie
    {
        foreach ($cookies as $cookie) {
            if ($cookie->getName() === 'vmecc_remember') {
                return $cookie;
            }
        }

        return null;
    }
}
