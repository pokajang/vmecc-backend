<?php

namespace App\Providers;

use App\Models\User;
use App\Services\AssignmentAuthorizationService;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        //
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        Gate::before(function (User $user, string $ability): ?bool {
            $authorization = app(AssignmentAuthorizationService::class);
            if ($authorization->isSystemAdministrator($user)) {
                return true;
            }

            $isRegisteredPermission = Permission::query()
                ->where('name', $ability)
                ->where('guard_name', 'web')
                ->exists();

            return $isRegisteredPermission
                ? $authorization->hasPermission($user, $ability)
                : null;
        });
    }
}
