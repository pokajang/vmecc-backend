<?php

namespace App\Providers;

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
