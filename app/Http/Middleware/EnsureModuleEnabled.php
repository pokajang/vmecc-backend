<?php

namespace App\Http\Middleware;

use App\Services\ModuleActivationService;
use App\Services\ModuleCatalog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureModuleEnabled
{
    public function __construct(private readonly ModuleActivationService $moduleActivationService)
    {
    }

    public function handle(Request $request, Closure $next, string $moduleKey): Response
    {
        $moduleKey = trim($moduleKey);

        if ($moduleKey === '' || ! ModuleCatalog::has($moduleKey)) {
            return response()->json([
                'message' => 'Module gate is misconfigured.',
                'code' => 'MODULE_GATE_MISCONFIGURED',
                'module' => $moduleKey,
            ], 500);
        }

        $state = $this->moduleActivationService->effectiveState($moduleKey);
        if (! ($state['enabled'] ?? true)) {
            return response()->json([
                'message' => 'Module is disabled.',
                'code' => 'MODULE_DISABLED',
                'module' => $moduleKey,
                'reason' => $state['reason'] ?? 'configured_disabled',
                'blocking_module' => $state['blockingModule'] ?? $moduleKey,
            ], 403);
        }

        return $next($request);
    }
}
