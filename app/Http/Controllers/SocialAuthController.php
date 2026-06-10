<?php

namespace App\Http\Controllers;

use App\Models\LoginAttempt;
use App\Models\User;
use App\Models\UserSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\Response;

class SocialAuthController extends Controller
{
    private const SESSION_COOKIE = 'vmecc_session';
    private const SESSION_LIFETIME_MINUTES = 120;

    public function redirect(): JsonResponse
    {
        $url = Socialite::driver('google')
            ->stateless()
            ->redirect()
            ->getTargetUrl();

        return response()->json(['url' => $url]);
    }

    public function callback(Request $request): RedirectResponse
    {
        $ip = $request->ip();
        $ua = substr((string) $request->userAgent(), 0, 255);
        $deviceId = $request->header('X-Client-Id');
        $deviceInfo = $ua;

        try {
            $googleUser = Socialite::driver('google')->stateless()->user();
        } catch (\Throwable $e) {
            $this->logAttempt(null, $request->input('email', ''), 'Failed', 'Google authentication error', $ip, $ua, $deviceId, $deviceInfo);
            return $this->redirectToFrontend('error', 'Unable to authenticate with Google.');
        }

        if (! $googleUser->getEmail()) {
            $this->logAttempt(null, '', 'Failed', 'Google account missing email', $ip, $ua, $deviceId, $deviceInfo);
            return $this->redirectToFrontend('error', 'Google account does not have an email address.');
        }

        $user = User::where('email', $googleUser->getEmail())->first();

        if (! $user || $user->status !== 'Active' || $user->locked_at) {
            $reason = $user && $user->locked_at ? 'Account locked' : 'Account not enabled for Google login';
            $this->logAttempt($user, $googleUser->getEmail(), 'Failed', $reason, $ip, $ua, $deviceId, $deviceInfo);
            return $this->redirectToFrontend('error', 'Your account is not enabled for Google sign-in. Please try logging in with your email and password.');
        }

        $session = $this->createSession($user, $request);
        $user->forceFill(['last_login_at' => now()])->save();
        $this->logAttempt($user, $googleUser->getEmail(), 'Success', null, $ip, $ua, $deviceId, $deviceInfo);

        return $this->redirectToFrontend('success')
            ->withCookie($this->buildSessionCookie($session->id));
    }

    private function redirectToFrontend(string $status, ?string $message = null): RedirectResponse
    {
        $base = rtrim(config('app.frontend_url', config('app.url')), '/');
        $query = http_build_query(array_filter([
            'status' => $status,
            'message' => $message,
        ]));

        return redirect()->away($query ? "{$base}/login?{$query}" : "{$base}/login", Response::HTTP_TEMPORARY_REDIRECT);
    }

    private function createSession(User $user, Request $request): UserSession
    {
        return UserSession::create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 255),
            'device_id' => $request->header('X-Client-Id'),
            'expires_at' => now()->addMinutes(self::SESSION_LIFETIME_MINUTES),
            'last_seen_at' => now(),
        ]);
    }

    private function buildSessionCookie(string $sessionId)
    {
        return cookie(
            name: self::SESSION_COOKIE,
            value: $sessionId,
            minutes: self::SESSION_LIFETIME_MINUTES,
            path: '/',
            domain: config('session.domain'),
            secure: app()->environment('production'),
            httpOnly: true,
            sameSite: 'lax'
        );
    }

    private function logAttempt(?User $user, string $email, string $status, ?string $reason, ?string $ip, ?string $ua, ?string $deviceId, ?string $deviceInfo): void
    {
        LoginAttempt::create([
            'user_id' => $user?->id,
            'email' => $email,
            'status' => $status,
            'reason' => $reason,
            'ip_address' => $ip,
            'user_agent' => $ua,
            'device_id' => $deviceId,
            'device_info' => $deviceInfo,
        ]);
    }
}
