<?php

namespace App\Http\Controllers;

use App\Models\OvertimeRecord;
use App\Models\PayrollClaim;
use App\Models\PayrollClaimItem;
use App\Models\WorkflowAttachment;
use App\Services\AssignmentAuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class WorkflowAttachmentController extends Controller
{
    public function __construct(private readonly AssignmentAuthorizationService $authorizationService) {}

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'file' => ['required', 'file', 'max:10240'],
        ]);

        $file = $validated['file'];
        $disk = config('filesystems.default', 'local');
        $path = $file->store('workflow-attachments/'.$user->id, ['disk' => $disk]);

        $attachment = WorkflowAttachment::create([
            'owner_user_id' => $user->id,
            'disk' => $disk,
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType(),
            'size' => $file->getSize() ?: 0,
            'checksum' => @hash_file('sha256', $file->getRealPath()) ?: null,
            'uploaded_at' => now(),
        ]);

        return response()->json([
            'data' => [
                'id' => $attachment->id,
                'original_name' => $attachment->original_name,
                'mime_type' => $attachment->mime_type,
                'size' => (int) $attachment->size,
                'uploaded_at' => optional($attachment->uploaded_at)->toIso8601String(),
            ],
        ], 201);
    }

    public function show(Request $request, int $id)
    {
        $user = $request->user();
        $attachment = WorkflowAttachment::findOrFail($id);

        if (! $this->canAccessAttachment($user, $attachment)) {
            throw ValidationException::withMessages([
                'attachment' => ['You are not allowed to access this attachment.'],
            ]);
        }

        if (! Storage::disk($attachment->disk)->exists($attachment->path)) {
            abort(404);
        }

        return Storage::disk($attachment->disk)->download($attachment->path, $attachment->original_name, [
            'Content-Type' => $attachment->mime_type ?: 'application/octet-stream',
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $attachment = WorkflowAttachment::findOrFail($id);

        if (! $this->canAccessAttachment($user, $attachment)) {
            throw ValidationException::withMessages([
                'attachment' => ['You are not allowed to delete this attachment.'],
            ]);
        }

        $this->assertAttachmentNotInUse($attachment);

        Storage::disk($attachment->disk)->delete($attachment->path);
        $attachment->delete();

        return response()->json(null, 204);
    }

    private function canAccessAttachment($user, WorkflowAttachment $attachment): bool
    {
        if ((int) $attachment->owner_user_id === (int) $user->id) {
            return true;
        }

        return $this->authorizationService->hasPermission($user, 'staff.leave.manage|staff.salary.manage|staff.manage');
    }

    private function assertAttachmentNotInUse(WorkflowAttachment $attachment): void
    {
        $attachmentId = (int) $attachment->id;

        $usedByPayrollClaim = PayrollClaim::query()
            ->where('attachment_id', $attachmentId)
            ->exists();
        if ($usedByPayrollClaim) {
            throw ValidationException::withMessages([
                'attachment' => ['Cannot delete an attachment that is linked to a payroll claim.'],
            ]);
        }

        $usedByPayrollClaimItem = PayrollClaimItem::query()
            ->where('attachment_id', $attachmentId)
            ->whereHas('claim')
            ->exists();
        if ($usedByPayrollClaimItem) {
            throw ValidationException::withMessages([
                'attachment' => ['Cannot delete an attachment that is linked to payroll claim line items.'],
            ]);
        }

        $usedByOvertime = OvertimeRecord::query()
            ->where('attachment_id', $attachmentId)
            ->exists();
        if ($usedByOvertime) {
            throw ValidationException::withMessages([
                'attachment' => ['Cannot delete an attachment that is linked to overtime records.'],
            ]);
        }
    }
}
