<?php

namespace App\Http\Controllers;

use App\Exceptions\ReportImageException;
use App\Models\ReportMedia;
use App\Services\PhotoUploadCapacityService;
use App\Services\ReportImageService;
use App\Services\ReportMediaAuthorizationService;
use App\Services\ReportThumbnailService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ReportMediaController extends Controller
{
    public function __construct(
        private readonly ReportImageService $imageService,
        private readonly ReportThumbnailService $thumbnailService,
        private readonly ReportMediaAuthorizationService $mediaAuthorizationService,
        private readonly PhotoUploadCapacityService $capacityService,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'max:30720'],
            'module' => ['required', 'string', 'in:inspection,erco'],
            'source' => ['nullable', 'string', 'in:camera,upload'],
            'upload_id' => ['required', 'uuid'],
        ]);
        $existing = ReportMedia::query()->where('user_id', $request->user()->id)->where('client_upload_id', $data['upload_id'])->first();
        if ($existing) {
            return response()->json(['data' => $this->format($existing) + ['idempotent_replay' => true]]);
        }
        $started = microtime(true);
        $path = null;
        $thumbnailPath = null;
        try {
            $this->capacityService->assertCanAccept((int) $request->user()->id, (int) $request->file('file')->getSize());
            $normalized = $this->imageService->normalize($request->file('file'), (string) ($data['source'] ?? 'upload'));
            $thumbnail = $this->thumbnailService->create($normalized['bytes']);
            $publicId = 'rpm_'.Str::lower(Str::random(24));
            $path = 'report-media/'.$request->user()->id.'/'.$publicId.'.jpg';
            Storage::disk('local')->put($path, $normalized['bytes']);
            $thumbnailPath = 'report-media/'.$request->user()->id.'/'.$publicId.'-thumb.jpg';
            Storage::disk('local')->put($thumbnailPath, $thumbnail['bytes']);
            $media = ReportMedia::query()->create([
                'public_id' => $publicId,
                'client_upload_id' => $data['upload_id'],
                'user_id' => $request->user()->id,
                'module' => $data['module'],
                'disk' => 'local',
                'storage_path' => $path,
                'thumbnail_path' => $thumbnailPath,
                'original_name' => pathinfo($request->file('file')->getClientOriginalName(), PATHINFO_FILENAME).'.jpg',
                'mime_type' => $normalized['mimeType'],
                'size_bytes' => $normalized['sizeBytes'],
                'thumbnail_size_bytes' => $thumbnail['sizeBytes'],
                'width' => $normalized['width'],
                'thumbnail_width' => $thumbnail['width'],
                'height' => $normalized['height'],
                'thumbnail_height' => $thumbnail['height'],
                'checksum_sha256' => hash('sha256', $normalized['bytes']),
                'thumbnail_checksum_sha256' => $thumbnail['checksum'],
            ]);
            Log::info('report_media_processed', ['upload_id' => $data['upload_id'], 'user_id' => $request->user()->id, 'module' => $data['module'], 'source' => $data['source'] ?? 'upload', 'processor' => $normalized['processor'] ?? null, 'browser_family' => $this->browserFamily($request->userAgent()), 'source_bytes' => $normalized['originalSize'], 'output_bytes' => $normalized['sizeBytes'], 'thumbnail_bytes' => $thumbnail['sizeBytes'], 'width' => $normalized['width'], 'height' => $normalized['height'], 'duration_ms' => (int) ((microtime(true) - $started) * 1000)]);

            return response()->json(['data' => $this->format($media) + ['idempotent_replay' => false]], 201);
        } catch (ReportImageException $exception) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }
            if ($thumbnailPath) {
                Storage::disk('local')->delete($thumbnailPath);
            }
            Log::warning('report_media_failed', ['upload_id' => $data['upload_id'] ?? null, 'user_id' => $request->user()?->id, 'module' => $data['module'] ?? '', 'source' => $data['source'] ?? 'upload', 'browser_family' => $this->browserFamily($request->userAgent()), 'code' => $exception->errorCode, 'duration_ms' => (int) ((microtime(true) - $started) * 1000)]);

            return response()->json(['message' => $exception->getMessage(), 'code' => $exception->errorCode], $exception->httpStatus);
        } catch (QueryException $exception) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }
            if ($thumbnailPath) {
                Storage::disk('local')->delete($thumbnailPath);
            }
            $existing = ReportMedia::query()->where('user_id', $request->user()->id)->where('client_upload_id', $data['upload_id'])->first();
            if ($existing) {
                return response()->json(['data' => $this->format($existing) + ['idempotent_replay' => true]]);
            }
            throw $exception;
        }
    }

    public function health(): JsonResponse
    {
        $capabilities = $this->imageService->capabilities();

        return response()->json(['data' => $capabilities], $capabilities['ready'] ? 200 : 503);
    }

    public function show(Request $request, string $mediaId)
    {
        $media = ReportMedia::query()->with('links')->where('public_id', $mediaId)->firstOrFail();
        if (! $this->mediaAuthorizationService->canView($request->user(), $media)) {
            abort(404);
        }
        $thumbnail = $request->query('variant') === 'thumbnail' && $media->thumbnail_path;
        $storagePath = $thumbnail ? $media->thumbnail_path : $media->storage_path;
        if (! Storage::disk($media->disk)->exists($storagePath)) {
            abort(404);
        }
        $checksum = $thumbnail ? $media->thumbnail_checksum_sha256 : $media->checksum_sha256;

        return response()->file(Storage::disk($media->disk)->path($storagePath), ['Content-Type' => 'image/jpeg', 'Content-Disposition' => 'inline; filename="report-photo.jpg"', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, max-age=3600', 'ETag' => '"'.($checksum ?: $media->public_id).'"']);
    }

    public function destroy(Request $request, string $mediaId): JsonResponse
    {
        $media = ReportMedia::query()->with('links')->where('public_id', $mediaId)->firstOrFail();
        if ((int) $media->user_id !== (int) $request->user()->id) {
            abort(403, 'Forbidden');
        }
        if ($media->links->where('parent_type', 'report')->count()) {
            return response()->json(['message' => 'Submitted report media cannot be deleted.', 'code' => 'media_protected'], 422);
        }
        Storage::disk($media->disk)->delete($media->storage_path);
        if ($media->thumbnail_path) {
            Storage::disk($media->disk)->delete($media->thumbnail_path);
        }
        $media->delete();

        return response()->json(null, 204);
    }

    private function format(ReportMedia $media): array
    {
        return ['media_id' => $media->public_id, 'file_name' => $media->original_name, 'mime_type' => $media->mime_type, 'size_bytes' => $media->size_bytes, 'width' => $media->width, 'height' => $media->height, 'checksum_sha256' => $media->checksum_sha256, 'url' => '/report-media/'.$media->public_id, 'thumbnail_url' => $media->thumbnail_path ? '/report-media/'.$media->public_id.'?variant=thumbnail' : null, 'thumbnail_size_bytes' => $media->thumbnail_size_bytes, 'thumbnail_width' => $media->thumbnail_width, 'thumbnail_height' => $media->thumbnail_height];
    }

    private function browserFamily(?string $userAgent): string
    {
        $userAgent = strtolower($userAgent ?? '');

        return match (true) {
            str_contains($userAgent, 'edgios') => 'edge_ios',
            str_contains($userAgent, 'edga') => 'edge_android',
            str_contains($userAgent, 'crios') => 'chrome_ios',
            str_contains($userAgent, 'fxios') => 'firefox_ios',
            str_contains($userAgent, 'iphone'), str_contains($userAgent, 'ipad') => 'safari_ios',
            str_contains($userAgent, 'samsungbrowser') => 'samsung_internet',
            str_contains($userAgent, 'android') && str_contains($userAgent, 'chrome') => 'chrome_android',
            str_contains($userAgent, 'android') => 'android_webview_or_other',
            default => 'other',
        };
    }
}
