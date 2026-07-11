<?php

namespace App\Http\Controllers;

use App\Exceptions\ReportImageException;
use App\Models\LeaveAttachment;
use App\Services\AssignmentAuthorizationService;
use App\Services\PhotoUploadCapacityService;
use App\Services\ReportImageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LeaveAttachmentController extends Controller
{
    private const MAX_BYTES = 15 * 1024 * 1024; // 15 MB

    private const ALLOWED_MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif', 'image/heic-sequence', 'image/heif-sequence', 'application/pdf'];

    private const DISK = 'local';

    private const STORAGE_PREFIX = 'leave-attachments';

    public function __construct(
        private readonly ReportImageService $imageService,
        private readonly PhotoUploadCapacityService $capacityService,
    ) {}

    // ── Upload ────────────────────────────────────────────────────────────────

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'file' => ['required', 'file', 'max:'.(self::MAX_BYTES / 1024)],
            'source' => ['nullable', 'string', 'in:camera,upload'],
            'upload_id' => ['required', 'uuid'],
        ]);

        $file = $request->file('file');

        $existing = LeaveAttachment::query()->where('user_id', $user->id)->where('client_upload_id', $data['upload_id'])->first();
        if ($existing) {
            return response()->json(['data' => [
                'id' => $existing->id, 'original_name' => $existing->original_name,
                'mime_type' => $existing->mime_type, 'size' => $existing->size,
                'original_size' => $existing->original_size, 'was_compressed' => $existing->was_compressed,
                'idempotent_replay' => true,
            ]]);
        }

        if (! in_array($file->getMimeType(), self::ALLOWED_MIMES, true)) {
            throw ValidationException::withMessages([
                'file' => ['Unsupported file type. Allowed: JPG, PNG, WEBP, PDF.'],
            ]);
        }

        if ($file->getSize() > self::MAX_BYTES) {
            throw ValidationException::withMessages([
                'file' => ['File exceeds the 15 MB limit.'],
            ]);
        }

        $isImage = str_starts_with((string) $file->getMimeType(), 'image/');
        try {
            $this->capacityService->assertCanAccept((int) $user->id, (int) $file->getSize());
            $normalized = $isImage ? $this->imageService->normalize($file, (string) ($data['source'] ?? 'upload')) : null;
        } catch (ReportImageException $exception) {
            return response()->json(['message' => $exception->getMessage(), 'code' => $exception->errorCode], $exception->httpStatus);
        }
        $attachmentId = (string) Str::uuid();
        $extension = $isImage ? 'jpg' : 'pdf';
        $storagePath = self::STORAGE_PREFIX.'/'.$user->id.'/'.$attachmentId.'.'.$extension;
        Storage::disk(self::DISK)->put($storagePath, $isImage ? $normalized['bytes'] : $file->getContent());

        $attachment = LeaveAttachment::query()->firstOrCreate([
            'user_id' => $user->id,
            'client_upload_id' => $data['upload_id'],
        ], [
            'leave_id' => null,
            'original_name' => $isImage ? 'leave-photo.jpg' : $this->safeFileName($file->getClientOriginalName(), 'attachment.pdf'),
            'mime_type' => $isImage ? $normalized['mimeType'] : $file->getMimeType(),
            'size' => $isImage ? $normalized['sizeBytes'] : $file->getSize(),
            'original_size' => $isImage ? $normalized['originalSize'] : null,
            'was_compressed' => $isImage ? $normalized['wasCompressed'] : false,
            'storage_path' => $storagePath,
        ]);
        if (! $attachment->wasRecentlyCreated) {
            Storage::disk(self::DISK)->delete($storagePath);
        }

        return response()->json([
            'data' => [
                'id' => $attachment->id,
                'original_name' => $attachment->original_name,
                'mime_type' => $attachment->mime_type,
                'size' => $attachment->size,
                'was_compressed' => $attachment->was_compressed,
                'original_size' => $attachment->original_size,
                'width' => $normalized['width'] ?? null,
                'height' => $normalized['height'] ?? null,
                'idempotent_replay' => false,
            ],
        ], 201);
    }

    // ── Download / Stream ─────────────────────────────────────────────────────

    public function show(Request $request, int $attachmentId)
    {
        $user = $request->user();
        $attachment = $this->resolveAttachment($attachmentId, $user->id, $request);

        if (! Storage::disk(self::DISK)->exists($attachment->storage_path)) {
            return response()->json(['message' => 'Attachment file not found.'], 404);
        }

        return response()->file(
            Storage::disk(self::DISK)->path($attachment->storage_path),
            [
                'Content-Type' => $attachment->mime_type,
                'Content-Disposition' => 'inline; filename="'.$attachment->original_name.'"',
            ]
        );
    }

    // ── Delete ────────────────────────────────────────────────────────────────

    public function destroy(Request $request, int $attachmentId): JsonResponse
    {
        $user = $request->user();
        $attachment = LeaveAttachment::with('leave')->findOrFail($attachmentId);
        if ((int) $attachment->user_id !== (int) $user->id) {
            abort(403, 'Only the leave owner can delete an attachment.');
        }

        // Only allow deletion if the leave is still in draft/pending (not approved)
        if ($attachment->leave) {
            $leave = $attachment->leave;
            if (in_array($leave->status, ['Approved'], true)) {
                throw ValidationException::withMessages([
                    'attachment' => ['Cannot delete attachment from an approved leave.'],
                ]);
            }
        }

        Storage::disk(self::DISK)->delete($attachment->storage_path);
        $attachment->delete();

        return response()->json(['message' => 'Attachment deleted.']);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function resolveAttachment(int $id, int $userId, Request $request): LeaveAttachment
    {
        $attachment = LeaveAttachment::with('leave')->findOrFail($id);

        // Owner can always access their own attachments
        if ($attachment->user_id === $userId) {
            return $attachment;
        }

        // Staff with leave management permission can access any attachment
        $authz = app(AssignmentAuthorizationService::class);
        if ($authz->hasPermission($request->user(), 'staff.leave.manage')) {
            return $attachment;
        }

        abort(403, 'Forbidden');
    }

    private function safeFileName(string $value, string $fallback): string
    {
        $name = preg_replace('/[\x00-\x1F\x7F"\\\/]+/u', '-', basename($value));
        $name = trim((string) $name, ' .-');

        return mb_substr($name !== '' ? $name : $fallback, 0, 190);
    }
}
