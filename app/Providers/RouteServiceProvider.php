<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to your application's "home" route.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/home';

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     */
    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(120)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('ai-helper', function (Request $request) {
            return Limit::perMinute((int) config('ai_helper.rate_limit_per_minute', 12))
                ->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('photo-uploads', function (Request $request) {
            $response = fn (Request $request, array $headers) => response()->json([
                'message' => 'Too many photo uploads. Wait briefly and retry.',
                'code' => 'rate_limited',
            ], 429, $headers);

            return [
                Limit::perMinute(20)->by('user:'.($request->user()?->id ?: $request->ip()))->response($response),
                Limit::perMinute(60)->by('ip:'.$request->ip())->response($response),
            ];
        });

        RateLimiter::for('inspection-duty-confirmations', function (Request $request) {
            $response = fn (Request $request, array $headers) => response()->json([
                'message' => 'Too many duty confirmation attempts. Wait briefly and retry.',
                'code' => 'duty_confirmation_rate_limited',
            ], 429, $headers);

            return [
                Limit::perMinute(12)->by('user:'.($request->user()?->id ?: $request->ip()))->response($response),
                Limit::perMinute(40)->by('ip:'.$request->ip())->response($response),
            ];
        });

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }
}
