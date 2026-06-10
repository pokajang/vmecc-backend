<?php

namespace App\Http\Middleware;

use App\Models\AuditLog;
use App\Services\AssignmentAuthorizationService;
use App\Services\SystemMaintenanceService;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class SystemMaintenanceMiddleware
{
    public function __construct(
        private readonly SystemMaintenanceService $maintenanceService,
        private readonly AssignmentAuthorizationService $authorizationService,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        try {
            $loaded = $this->maintenanceService->load();
            $resolved = $this->maintenanceService->resolveState($loaded);
            $setting = $resolved['setting'];
            if (($resolved['autoTransitioned'] ?? false) === true) {
                $this->logAutoEnforcement($request, $setting);
            }
        } catch (QueryException $e) {
            return $next($request);
        }

        if (! ($setting['enabled'] ?? false)) {
            return $next($request);
        }

        $phase = (string) ($setting['phase'] ?? SystemMaintenanceService::PHASE_OFF);
        $isSystemAdministrator = $this->authorizationService->getActiveRoleNames($user)
            ->contains(fn (string $roleName) => trim($roleName) === 'System Administrator');

        $hasWildcardPermission = $this->authorizationService->hasPermission($user, '*');

        if ($isSystemAdministrator || $hasWildcardPermission || $phase === SystemMaintenanceService::PHASE_GRACE) {
            $response = $next($request);
            return $this->attachMaintenanceHeaders($response, $setting);
        }

        Log::warning('System maintenance request blocked', [
            'user_id' => $user->id,
            'route' => $request->path(),
            'method' => $request->method(),
            'phase' => $phase,
            'setting_version' => $setting['updatedAt'] ?? null,
        ]);

        $response = response()->json([
            'message' => $setting['message'] ?? 'System is under maintenance. Please try again later.',
            'code' => 'SYSTEM_MAINTENANCE',
            'data' => $setting,
        ], 503);
        return $this->attachMaintenanceHeaders($response, $setting);
    }

    private function attachMaintenanceHeaders(Response $response, array $setting): Response
    {
        if (! ($setting['enabled'] ?? false)) {
            return $response;
        }

        $response->headers->set('X-System-Maintenance-Enabled', '1');
        $response->headers->set('X-System-Maintenance-Phase', (string) ($setting['phase'] ?? SystemMaintenanceService::PHASE_OFF));
        $response->headers->set('X-System-Maintenance-Version', (string) ($setting['updatedAt'] ?? ''));
        if (! empty($setting['graceEndsAt'])) {
            $response->headers->set('X-System-Maintenance-Grace-Ends-At', (string) $setting['graceEndsAt']);
        }
        return $response;
    }

    private function logAutoEnforcement(Request $request, array $setting): void
    {
        try {
            AuditLog::create([
                'actor_user_id' => null,
                'action' => 'system_maintenance_auto_enforced',
                'subject_type' => null,
                'subject_id' => null,
                'metadata' => [
                    'next' => $setting,
                    'trigger_route' => $request->path(),
                    'trigger_method' => $request->method(),
                ],
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 255),
            ]);
        } catch (\Throwable) {
            // Non-fatal: auto-enforcement should proceed even if audit logging fails.
        }
    }
}
