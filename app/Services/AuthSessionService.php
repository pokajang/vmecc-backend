<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;

class AuthSessionService
{
    public const SESSION_COOKIE = 'vmecc_session';
    public const REMEMBER_COOKIE = 'vmecc_remember';

    /**
     * @return array{session: UserSession, remember_token: string|null}
     */
    public function createSession(User $user, Request $request, bool $remember = false): array
    {
        $rememberToken = $remember ? Str::random(64) : null;

        $session = UserSession::create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 255),
            'device_id' => $request->header('X-Client-Id'),
            'expires_at' => now()->addMinutes($this->sessionLifetimeMinutes()),
            'last_seen_at' => now(),
            'remember_token_hash' => $rememberToken ? $this->hashToken($rememberToken) : null,
            'remember_expires_at' => $rememberToken ? now()->addDays($this->rememberLifetimeDays()) : null,
        ]);

        return ['session' => $session, 'remember_token' => $rememberToken];
    }

    /**
     * @return array{session: UserSession, remember_token: string}|null
     */
    public function restoreRememberedSession(Request $request): ?array
    {
        $rememberCookie = (string) $request->cookie(self::REMEMBER_COOKIE, '');
        [$sessionId, $token] = $this->parseRememberCookie($rememberCookie);

        if (! $sessionId || ! $token) {
            return null;
        }

        $session = UserSession::with(['user' => fn ($query) => $query->withTrashed()])
            ->where('id', $sessionId)
            ->whereNull('logged_out_at')
            ->whereNull('revoked_at')
            ->whereNotNull('remember_token_hash')
            ->where('remember_expires_at', '>', now())
            ->first();

        if (! $session || ! $session->user) {
            return null;
        }

        if (! hash_equals((string) $session->remember_token_hash, $this->hashToken($token))) {
            $session->forceFill([
                'logged_out_at' => now(),
                'revoked_at' => now(),
                'revoke_reason' => 'remember_token_mismatch',
                'remember_token_hash' => null,
                'remember_expires_at' => null,
            ])->save();

            return null;
        }

        if (! $this->isUserEligible($session->user)) {
            $session->forceFill([
                'logged_out_at' => now(),
                'revoked_at' => now(),
                'revoke_reason' => $this->userIneligibleReason($session->user),
                'remember_token_hash' => null,
                'remember_expires_at' => null,
            ])->save();

            return null;
        }

        $nextToken = Str::random(64);
        $session->forceFill([
            'expires_at' => now()->addMinutes($this->sessionLifetimeMinutes()),
            'last_seen_at' => now(),
            'csrf_token_hash' => $this->hashToken(Str::random(64)),
            'remember_token_hash' => $this->hashToken($nextToken),
            'remember_expires_at' => now()->addDays($this->rememberLifetimeDays()),
        ])->save();

        return ['session' => $session->fresh('user'), 'remember_token' => $nextToken];
    }

    public function bindSessionToRequest(Request $request, UserSession $session): void
    {
        Auth::setUser($session->user);
        $request->attributes->set('user_session', $session);
    }

    public function buildSessionCookie(string $sessionId): Cookie
    {
        return cookie(
            name: self::SESSION_COOKIE,
            value: $sessionId,
            minutes: $this->sessionLifetimeMinutes(),
            path: '/',
            domain: config('session.domain'),
            secure: (bool) config('session.secure'),
            httpOnly: true,
            sameSite: 'lax'
        );
    }

    public function buildRememberCookie(UserSession $session, string $token): Cookie
    {
        return cookie(
            name: self::REMEMBER_COOKIE,
            value: "{$session->id}|{$token}",
            minutes: $this->rememberLifetimeDays() * 24 * 60,
            path: '/',
            domain: config('session.domain'),
            secure: (bool) config('session.secure'),
            httpOnly: true,
            sameSite: 'lax'
        );
    }

    public function forgetSessionCookie(): Cookie
    {
        return $this->forgetCookie(self::SESSION_COOKIE);
    }

    public function forgetRememberCookie(): Cookie
    {
        return $this->forgetCookie(self::REMEMBER_COOKIE);
    }

    public function invalidateSession(?string $sessionId, string $reason): void
    {
        if (! $sessionId) {
            return;
        }

        UserSession::where('id', $sessionId)->update([
            'logged_out_at' => now(),
            'revoked_at' => now(),
            'revoke_reason' => $reason,
            'remember_token_hash' => null,
            'remember_expires_at' => null,
        ]);
    }

    public function invalidateRememberCookie(?string $rememberCookie, string $reason): void
    {
        if (! $rememberCookie) {
            return;
        }

        [$sessionId] = $this->parseRememberCookie($rememberCookie);
        $this->invalidateSession($sessionId, $reason);
    }

    public function sessionLifetimeMinutes(): int
    {
        return max(1, (int) config('session.lifetime', 720));
    }

    public function rememberLifetimeDays(): int
    {
        return max(1, (int) config('session.remember_days', 30));
    }

    public function isUserEligible(User $user): bool
    {
        return ! $user->trashed()
            && strcasecmp((string) $user->status, 'Active') === 0
            && ! $user->locked_at;
    }

    public function userIneligibleReason(User $user): string
    {
        if ($user->trashed()) {
            return 'terminated';
        }

        if ($user->locked_at) {
            return 'locked';
        }

        return 'inactive';
    }

    private function forgetCookie(string $name): Cookie
    {
        return cookie(
            name: $name,
            value: '',
            minutes: -2628000,
            path: '/',
            domain: config('session.domain'),
            secure: (bool) config('session.secure'),
            httpOnly: true,
            sameSite: 'lax'
        );
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    private function parseRememberCookie(string $value): array
    {
        $parts = explode('|', $value, 2);

        if (count($parts) !== 2) {
            return [null, null];
        }

        $sessionId = trim($parts[0]);
        $token = trim($parts[1]);

        if (! Str::isUuid($sessionId) || $token === '') {
            return [null, null];
        }

        return [$sessionId, $token];
    }

    private function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
