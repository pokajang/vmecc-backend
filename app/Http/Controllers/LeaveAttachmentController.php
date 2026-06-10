<?php

namespace App\Http\Controllers;

use App\Models\LeaveAttachment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LeaveAttachmentController extends Controller
{
    private const MAX_BYTES        = 15 * 1024 * 1024; // 15 MB
    private const ALLOWED_MIMES    = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];
    private const DISK             = 'local';
    private const STORAGE_PREFIX   = 'leave-attachments';

    // ── Upload ────────────────────────────────────────────────────────────────

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        $request->validate([
            'file' => ['required', 'file', 'max:' . (self::MAX_BYTES / 1024)],
        ]);

        $file = $request->file('file');

        if (!in_array($file->getMimeType(), self::ALLOWED_MIMES, true)) {
            throw ValidationException::withMessages([
                'file' => ['Unsupported file type. Allowed: JPG, PNG, WEBP, PDF.'],
            ]);
        }

        if ($file->getSize() > self::MAX_BYTES) {
            throw ValidationException::withMessages([
                'file' => ['File exceeds the 15 MB limit.'],
            ]);
        }

        $attachmentId = (string) Str::uuid();
        $extension    = $file->getClientOriginalExtension() ?: pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION);
        $storagePath  = self::STORAGE_PREFIX . '/' . $user->id . '/' . $attachmentId . '.' . $extension;

        Storage::disk(self::DISK)->put($storagePath, $file->getContent());

        $attachment = LeaveAttachment::create([
            'user_id'       => $user->id,
            'leave_id'      => null,
            'original_name' => $file->getClientOriginalName(),
            'mime_type'     => $file->getMimeType(),
            'size'          => $file->getSize(),
            'original_size' => null,
            'was_compressed'=> false,
            'storage_path'  => $storagePath,
        ]);

        return response()->json([
            'data' => [
                'id'            => $attachment->id,
                'original_name' => $attachment->original_name,
                'mime_type'     => $attachment->mime_type,
                'size'          => $attachment->size,
                'was_compressed'=> $attachment->was_compressed,
            ],
        ], 201);
    }

    // ── Download / Stream ─────────────────────────────────────────────────────

    public function show(Request $request, int $attachmentId)
    {
        $user       = $request->user();
        $attachment = $this->resolveAttachment($attachmentId, $user->id, $request);

        if (!Storage::disk(self::DISK)->exists($attachment->storage_path)) {
            return response()->json(['message' => 'Attachment file not found.'], 404);
        }

        return response()->file(
            Storage::disk(self::DISK)->path($attachment->storage_path),
            [
                'Content-Type'        => $attachment->mime_type,
                'Content-Disposition' => 'inline; filename="' . $attachment->original_name . '"',
            ]
        );
    }

    // ── Delete ────────────────────────────────────────────────────────────────

    public function destroy(Request $request, int $attachmentId): JsonResponse
    {
        $user       = $request->user();
        $attachment = $this->resolveAttachment($attachmentId, $user->id, $request);

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
        $authz = app(\App\Services\AssignmentAuthorizationService::class);
        if ($authz->hasPermission($request->user(), 'staff.leave.manage')) {
            return $attachment;
        }

        abort(403, 'Forbidden');
    }
}
