<?php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Models\MessageAttachment;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class MessageAttachmentController extends Controller
{
    // Upload an image before sending — returns attachment_id to include in the message
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $request->validate([
            'file' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,gif,webp', 'max:2048'],
        ]);

        $file = $request->file('file');
        $disk = config('filesystems.default', 'local');
        $path = $file->store('message-attachments/' . $user->id, ['disk' => $disk]);

        $attachment = MessageAttachment::create([
            'message_id'     => null,
            'owner_user_id'  => $user->id,
            'disk'           => $disk,
            'path'           => $path,
            'original_name'  => $file->getClientOriginalName(),
            'mime_type'      => $file->getClientMimeType(),
            'size'           => $file->getSize() ?: 0,
        ]);

        return response()->json(['data' => ['id' => $attachment->id]], 201);
    }

    // Serve the image — only sender or recipient may view
    public function show(Request $request, int $id)
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $attachment = MessageAttachment::findOrFail($id);

        // Allow owner always; if linked to a message, allow the other party too
        $allowed = (int) $attachment->owner_user_id === (int) $user->id;
        if (! $allowed && $attachment->message_id) {
            $message = Message::withTrashed()->find($attachment->message_id);
            if ($message) {
                $allowed = (int) $message->sender_user_id === (int) $user->id
                    || (int) $message->recipient_user_id === (int) $user->id;
            }
        }

        if (! $allowed) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if (! Storage::disk($attachment->disk)->exists($attachment->path)) {
            abort(404);
        }

        return Storage::disk($attachment->disk)->response($attachment->path, $attachment->original_name, [
            'Content-Type'  => $attachment->mime_type ?: 'image/jpeg',
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    // Soft-delete an attachment — only the message sender (owner) may delete
    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $attachment = MessageAttachment::findOrFail($id);

        if ((int) $attachment->owner_user_id !== (int) $user->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $attachment->delete();

        AuditLogger::log($request, 'message_attachment_deleted', $user, [
            'attachment_id' => $attachment->id,
            'message_id'    => $attachment->message_id,
        ]);

        return response()->json(['message' => 'Attachment deleted.']);
    }
}
