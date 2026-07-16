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

        RateLimiter::for('ai-helper-generation', function (Request $request) {
            $response = fn (Request $request, array $headers) => response()->json([
                'message' => 'Ask AI is receiving too many generation requests. Wait briefly and retry.',
                'code' => 'AI_HELPER_RATE_LIMITED',
                'retry_after' => (int) ($headers['Retry-After'] ?? 60),
            ], 429, $headers);

            return Limit::perMinute(max(1, (int) config('ai_helper.rate_limit_per_minute', 12)))
                ->by($request->user()?->id ?: $request->ip())
                ->response($response);
        });

        RateLimiter::for('ai-helper-knowledge-upload', function (Request $request) {
            $response = fn (Request $request, array $headers) => response()->json([
                'message' => 'Too many Ask AI knowledge uploads. Wait briefly and retry.',
                'code' => 'AI_HELPER_KNOWLEDGE_UPLOAD_RATE_LIMITED',
                'retry_after' => (int) ($headers['Retry-After'] ?? 60),
            ], 429, $headers);

            return [
                Limit::perMinute(max(1, (int) config('ai_helper.knowledge_upload_rate_limit_per_minute', 6)))
                    ->by('user:'.($request->user()?->id ?: $request->ip()))
                    ->response($response),
                Limit::perMinute(max(1, (int) config('ai_helper.knowledge_upload_ip_rate_limit_per_minute', 30)))
                    ->by('ip:'.$request->ip())
                    ->response($response),
            ];
        });

        RateLimiter::for('ai-helper-document-upload', function (Request $request) {
            $response = fn (Request $request, array $headers) => response()->json([
                'message' => 'Too many reference document uploads. Wait briefly and retry.',
                'code' => 'AI_HELPER_DOCUMENT_UPLOAD_RATE_LIMITED',
                'retry_after' => (int) ($headers['Retry-After'] ?? 60),
            ], 429, $headers);

            return [
                Limit::perMinute(max(1, (int) config('ai_helper.knowledge_upload_rate_limit_per_minute', 6)))
                    ->by('user:'.($request->user()?->id ?: $request->ip()))
                    ->response($response),
                Limit::perMinute(max(1, (int) config('ai_helper.knowledge_upload_ip_rate_limit_per_minute', 30)))
                    ->by('ip:'.$request->ip())
                    ->response($response),
            ];
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

        RateLimiter::for('report-pdf-downloads', function (Request $request) {
            $response = fn (Request $request, array $headers) => response()->json([
                'message' => 'Too many report PDF requests. Wait briefly and retry.',
                'code' => 'REPORT_PDF_RATE_LIMITED',
                'retry_after' => (int) ($headers['Retry-After'] ?? 60),
            ], 429, $headers);

            return [
                Limit::perMinute(12)->by('user:'.($request->user()?->id ?: $request->ip()))->response($response),
                Limit::perMinute(60)->by('ip:'.$request->ip())->response($response),
            ];
        });

        RateLimiter::for('report-export-previews', function (Request $request) {
            return Limit::perMinute(60)->by('user:'.($request->user()?->id ?: $request->ip()));
        });

        RateLimiter::for('report-downloads', function (Request $request) {
            $response = fn (Request $request, array $headers) => response()->json([
                'message' => 'Too many report downloads. Wait briefly and retry.',
                'code' => 'REPORT_DOWNLOAD_RATE_LIMITED',
                'retry_after' => (int) ($headers['Retry-After'] ?? 60),
            ], 429, $headers);

            return [
                Limit::perMinute(12)->by('user:'.($request->user()?->id ?: $request->ip()))->response($response),
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
