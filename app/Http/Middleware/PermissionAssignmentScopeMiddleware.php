<?php

namespace App\Http\Middleware;

use App\Services\AssignmentAuthorizationService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PermissionAssignmentScopeMiddleware
{
    public function __construct(private readonly AssignmentAuthorizationService $authorizationService)
    {
    }

    public function handle(Request $request, Closure $next, string $permissions, string $teamParam = 'team'): Response
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $team = $request->route($teamParam);
        $teamId = null;
        if (is_numeric($team)) {
            $teamId = (int) $team;
        } elseif (is_object($team) && isset($team->id)) {
            $teamId = (int) $team->id;
        } else {
            $teamId = $request->integer('team_id') ?: null;
        }

        if (! $this->authorizationService->hasPermission($user, $permissions, $teamId)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return $next($request);
    }
}
