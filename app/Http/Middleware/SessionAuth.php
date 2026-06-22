<?php

namespace App\Http\Middleware;

use App\Models\UserSession;
use App\Services\AuthSessionService;
use Closure;
use Illuminate\Http\Request;

class SessionAuth
{
    public function __construct(private readonly AuthSessionService $sessions)
    {
    }

    public function handle(Request $request, Closure $next)
    {
        $sessionId = $request->cookie(AuthSessionService::SESSION_COOKIE);
        $shouldForget = false;
        $restored = null;

        if ($sessionId) {
            $session = UserSession::with(['user' => fn ($query) => $query->withTrashed()])
                ->where('id', $sessionId)
                ->whereNull('logged_out_at')
                ->whereNull('revoked_at')
                ->where('expires_at', '>', now())
                ->first();

            if ($session && $session->user) {
                $user = $session->user;
                if ($user->trashed() || strcasecmp((string) $user->status, 'Active') !== 0 || $user->locked_at) {
                    $session->forceFill([
                        'logged_out_at' => now(),
                        'revoked_at' => now(),
                        'revoke_reason' => $user->trashed() ? 'terminated' : ($user->locked_at ? 'locked' : 'inactive'),
                        'remember_token_hash' => null,
                        'remember_expires_at' => null,
                    ])->save();
                    $shouldForget = true;
                } else {
                    if (! $session->last_seen_at || $session->last_seen_at->lt(now()->subMinutes(5))) {
                        $session->forceFill(['last_seen_at' => now()])->save();
                    }
                    $this->sessions->bindSessionToRequest($request, $session);
                }
            } elseif ($session) {
                $session->forceFill([
                    'logged_out_at' => now(),
                    'revoked_at' => now(),
                    'revoke_reason' => 'user_missing',
                    'remember_token_hash' => null,
                    'remember_expires_at' => null,
                ])->save();
                $shouldForget = true;
            }
        }

        if (! $request->attributes->has('user_session')) {
            $restored = $this->sessions->restoreRememberedSession($request);
            if ($restored) {
                $this->sessions->bindSessionToRequest($request, $restored['session']);
            }
        }

        $response = $next($request);

        if ($shouldForget) {
            return $response
                ->withCookie($this->sessions->forgetSessionCookie())
                ->withCookie($this->sessions->forgetRememberCookie());
        }

        if ($restored) {
            return $response
                ->withCookie($this->sessions->buildSessionCookie($restored['session']->id))
                ->withCookie($this->sessions->buildRememberCookie($restored['session'], $restored['remember_token']));
        }

        return $response;
    }
}
