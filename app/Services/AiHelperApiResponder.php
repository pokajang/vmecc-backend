<?php

namespace App\Services;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class AiHelperApiResponder
{
    public function requestId(Request $request): string
    {
        $existing = trim((string) $request->headers->get('X-Request-Id', ''));

        if ($existing !== '') {
            return Str::limit($existing, 80, '');
        }

        $attributesRequestId = trim((string) $request->attributes->get('ai_helper_request_id', ''));
        if ($attributesRequestId !== '') {
            return $attributesRequestId;
        }

        $requestId = (string) Str::uuid();
        $request->attributes->set('ai_helper_request_id', $requestId);

        return $requestId;
    }

    public function error(
        Request $request,
        string $message,
        string $code,
        int $status,
        array $extra = [],
    ): JsonResponse {
        return response()->json([
            'message' => $message,
            'code' => $code,
            'request_id' => $this->requestId($request),
            ...$extra,
        ], $status);
    }

    public function failure(Request $request, Throwable $e, string $action): JsonResponse
    {
        $requestId = $this->requestId($request);

        Log::warning('Ask AI request failed', [
            'action' => $action,
            'request_id' => $requestId,
            'user_id' => $request->user()?->id,
            'error' => $e->getMessage(),
            'exception' => get_class($e),
        ]);

        return $this->error(
            $request,
            'Ask AI is temporarily unavailable. Please try again later.',
            'AI_HELPER_FAILED',
            500,
        );
    }
}
