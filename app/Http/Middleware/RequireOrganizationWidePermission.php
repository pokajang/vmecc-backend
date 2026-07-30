<?php

namespace App\Http\Middleware;

use App\Services\AssignmentAuthorizationService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireOrganizationWidePermission
{
    public function __construct(private readonly AssignmentAuthorizationService $authorizationService) {}

    public function handle(Request $request, Closure $next, string $requiredPermissions): Response
    {
        $user = $request->user();
        if (! $user || ! $this->authorizationService->hasOrganizationWidePermission(
            $user,
            $requiredPermissions,
        )) {
            abort(403, 'This action requires an organization-wide permission assignment.');
        }

        return $next($request);
    }
}
