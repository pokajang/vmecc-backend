<?php

namespace App\Http\Middleware;

use App\Models\UserSession;
use Closure;
use Illuminate\Http\Request;

class VerifySessionCsrfToken
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->isMethodSafe()) {
            return $next($request);
        }

        $session = $request->attributes->get('user_session');
        if (! $session instanceof UserSession) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $expectedHash = (string) ($session->csrf_token_hash ?? '');
        $provided = trim((string) $request->header('X-CSRF-Token', ''));

        if ($expectedHash === '' || $provided === '' || ! hash_equals($expectedHash, hash('sha256', $provided))) {
            return response()->json(['message' => 'CSRF token mismatch.'], 419);
        }

        return $next($request);
    }
}
