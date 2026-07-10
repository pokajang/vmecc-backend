<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class LimitPhotoUploadConcurrency
{
    public function handle(Request $request, Closure $next): Response
    {
        $userId = (int) ($request->user()?->id ?? 0);
        if ($userId <= 0) {
            return $next($request);
        }

        $lock = Cache::lock(
            "photo-upload-processing:user:{$userId}",
            max(30, (int) config('report_media.processing_lock_seconds', 120)),
        );
        if (! $lock->get()) {
            return response()->json([
                'message' => 'Another photo is still being processed. Wait briefly and retry.',
                'code' => 'upload_busy',
            ], 429);
        }

        try {
            return $next($request);
        } finally {
            $lock->release();
        }
    }
}
