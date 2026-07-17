<?php

namespace App\Providers;

use App\Models\Leave;
use App\Models\OvertimeRecord;
use App\Models\PayrollClaim;
use App\Models\Report;
use App\Observers\WorkflowTransitionObserver;
use App\Validation\SafeEmailValidator;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Leave::observe(WorkflowTransitionObserver::class);
        OvertimeRecord::observe(WorkflowTransitionObserver::class);
        PayrollClaim::observe(WorkflowTransitionObserver::class);
        Report::observe(WorkflowTransitionObserver::class);

        Validator::resolver(function ($translator, $data, $rules, $messages, $attributes) {
            return new SafeEmailValidator($translator, $data, $rules, $messages, $attributes);
        });

        ResetPassword::createUrlUsing(function ($user, string $token) {
            $baseUrl = rtrim(config('app.frontend_url', config('app.url')), '/');

            $query = http_build_query([
                'token' => $token,
                'email' => $user->getEmailForPasswordReset(),
            ]);

            return "{$baseUrl}/reset-password?{$query}";
        });
    }
}
