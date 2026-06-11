<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        $response = $this->get('/api/auth/google/callback');

        $response->assertRedirect('https://vmecc.amiosh.com/login?status=success');
        $cookie = $this->findSessionCookie($response->headers->getCookies());
        $this->assertNotNull($cookie);
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
}
