<?php

namespace App\Http\Middleware;

use App\Services\AssignmentAuthorizationService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PermissionAssignmentMiddleware
{
    public function __construct(private readonly AssignmentAuthorizationService $authorizationService)
    {
    }

    public function handle(Request $request, Closure $next, string $permissions): Response
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        if (! $this->authorizationService->hasPermission($user, $permissions)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return $next($request);
    }
}
