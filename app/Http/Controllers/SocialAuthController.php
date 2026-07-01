<?php

namespace App\Http\Controllers;

use App\Models\LoginAttempt;
use App\Models\User;
use App\Services\AuthSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\Response;

class SocialAuthController extends Controller
{
    public function __construct(private readonly AuthSessionService $sessions)
    {
    }

    public function redirect(Request $request): JsonResponse
    {
        $state = Crypt::encryptString(json_encode([
            'remember' => $request->boolean('remember'),
            'client_mode' => $this->sessions->clientModeFromRequest($request),
        ]));

        $url = Socialite::driver('google')
            ->stateless()
            ->with(['state' => $state])
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
        $clientMode = $this->clientModeRequested($request);
        $remember = $this->rememberRequested($request);

        try {
            $googleUser = Socialite::driver('google')->stateless()->user();
        } catch (\Throwable $e) {
            $this->logAttempt(null, $request->input('email', ''), 'Failed', 'Google authentication error', $ip, $ua, $deviceId, $deviceInfo, $clientMode);
            return $this->redirectToFrontend('error', 'Unable to authenticate with Google.');
        }

        if (! $googleUser->getEmail()) {
            $this->logAttempt(null, '', 'Failed', 'Google account missing email', $ip, $ua, $deviceId, $deviceInfo, $clientMode);
            return $this->redirectToFrontend('error', 'Google account does not have an email address.');
        }

        $user = User::where('email', $googleUser->getEmail())->first();

        if (! $user || strcasecmp((string) $user->status, 'Active') !== 0 || $user->locked_at) {
            $reason = $user && $user->locked_at ? 'Account locked' : 'Account not enabled for Google login';
            $this->logAttempt($user, $googleUser->getEmail(), 'Failed', $reason, $ip, $ua, $deviceId, $deviceInfo, $clientMode);
            return $this->redirectToFrontend('error', 'Your account is not enabled for Google sign-in. Please try logging in with your email and password.');
        }

        $created = $this->sessions->createSession($user, $request, $remember, $clientMode);
        $session = $created['session'];
        $user->forceFill(['last_login_at' => now()])->save();
        $this->logAttempt($user, $googleUser->getEmail(), 'Success', null, $ip, $ua, $deviceId, $deviceInfo, $clientMode);

        $response = $this->redirectToFrontend('success')
            ->withCookie($this->sessions->buildSessionCookie($session->id));

        if ($created['remember_token']) {
            $response->withCookie($this->sessions->buildRememberCookie($session, $created['remember_token']));
        }

        return $response;
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

    private function rememberRequested(Request $request): bool
    {
        $payload = $this->statePayload($request);
        return (bool) ($payload['remember'] ?? false);
    }

    private function clientModeRequested(Request $request): string
    {
        $mode = strtolower(trim((string) ($this->statePayload($request)['client_mode'] ?? 'browser')));

        return in_array($mode, ['pwa', 'browser'], true) ? $mode : 'browser';
    }

    private function statePayload(Request $request): array
    {
        $state = (string) $request->query('state', '');
        if ($state === '') {
            return [];
        }

        try {
            return json_decode(Crypt::decryptString($state), true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return [];
        }
    }

    private function logAttempt(?User $user, string $email, string $status, ?string $reason, ?string $ip, ?string $ua, ?string $deviceId, ?string $deviceInfo, ?string $clientMode): void
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
            'client_mode' => $clientMode,
        ]);
    }
}
