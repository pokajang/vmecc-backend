<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
