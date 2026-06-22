<?php

namespace App\Http\Controllers;

use App\Services\AuditLogger;
use App\Services\ModuleActivationService;
use App\Services\ModuleCatalog;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ModuleActivationController extends Controller
{
    public function __construct(private readonly ModuleActivationService $moduleActivationService)
    {
    }

    public function show(): JsonResponse
    {
        return response()->json([
            'data' => $this->moduleActivationService->load(),
            'registryErrors' => ModuleCatalog::validateRegistry(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $request->validate([
            'configured' => ['present'],
        ]);

        $configured = $request->input('configured');

        if (! is_array($configured)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'configured' => ['The configured field must be an object.'],
            ]);
        }

        foreach ($configured as $module => $enabled) {
            if (! is_string($module) || ! is_bool($enabled)) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'configured' => ['Module overrides must be keyed by module and set to true or false.'],
                ]);
            }
        }

        $before = $this->moduleActivationService->load();

        try {
            $next = $this->moduleActivationService->save($configured, $request->user());
        } catch (QueryException) {
            return response()->json(['message' => 'Settings table not available. Please run migrations.'], 500);
        }

        AuditLogger::log($request, 'module_activation_updated', null, [
            'before' => $before['configured'] ?? [],
            'after' => $next['configured'] ?? [],
            'forceAllEnabled' => $next['forceAllEnabled'] ?? false,
        ]);

        return response()->json([
            'message' => 'Module activation settings updated.',
            'data' => $next,
            'registryErrors' => ModuleCatalog::validateRegistry(),
        ]);
    }
}
