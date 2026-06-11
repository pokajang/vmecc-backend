<?php

namespace App\Http\Middleware;

use App\Models\UserSession;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SessionAuth
{
    public function handle(Request $request, Closure $next)
    {
        $sessionId = $request->cookie('vmecc_session');
        $shouldForget = false;

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
                    ])->save();
                    $shouldForget = true;
                } else {
                    if (! $session->last_seen_at || $session->last_seen_at->lt(now()->subMinutes(5))) {
                        $session->forceFill(['last_seen_at' => now()])->save();
                    }
                    // Make the user available to the current request/guards
                    Auth::setUser($user);
                    $request->attributes->set('user_session', $session);
                }
            } elseif ($session) {
                $session->forceFill([
                    'logged_out_at' => now(),
                    'revoked_at' => now(),
                    'revoke_reason' => 'user_missing',
                ])->save();
                $shouldForget = true;
            }
        }

        $response = $next($request);

        if ($shouldForget) {
            return $response->withCookie($this->forgetSessionCookie());
        }

        return $response;
    }

    private function forgetSessionCookie()
    {
        return cookie(
            name: 'vmecc_session',
            value: '',
            minutes: -2628000,
            path: '/',
            domain: config('session.domain'),
            secure: (bool) config('session.secure'),
            httpOnly: true,
            sameSite: 'lax'
        );
    }
}
