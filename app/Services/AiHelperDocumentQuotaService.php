<?php

namespace App\Services;

use App\Models\AiHelperDocument;
use App\Models\User;
use Illuminate\Http\UploadedFile;

class AiHelperDocumentQuotaService
{
    /** @return array{ok: bool, message?: string, code?: string} */
    public function checkUpload(User $user, UploadedFile $file): array
    {
        $activeLimit = (int) config('ai_helper.document_max_active_uploads_per_user', 100);
        $userBytesLimit = (int) config('ai_helper.document_max_upload_bytes_per_user', 2147483648);
        $globalBytesLimit = (int) config('ai_helper.document_max_total_upload_bytes', 21474836480);
        $incomingSize = (int) ($file->getSize() ?: 0);

        if ($activeLimit > 0 && AiHelperDocument::query()->where('uploaded_by', $user->id)->count() >= $activeLimit) {
            return [
                'ok' => false,
                'message' => 'Reference document upload limit reached. Delete old documents before uploading more.',
                'code' => 'AI_HELPER_DOCUMENT_UPLOAD_LIMIT',
            ];
        }

        if ($userBytesLimit > 0) {
            $userBytes = (int) AiHelperDocument::withTrashed()->where('uploaded_by', $user->id)->sum('source_size');
            if (($userBytes + $incomingSize) > $userBytesLimit) {
                return [
                    'ok' => false,
                    'message' => 'Reference document storage limit reached for your account.',
                    'code' => 'AI_HELPER_DOCUMENT_STORAGE_LIMIT',
                ];
            }
        }

        if ($globalBytesLimit > 0) {
            $globalBytes = (int) AiHelperDocument::withTrashed()->sum('source_size');
            if (($globalBytes + $incomingSize) > $globalBytesLimit) {
                return [
                    'ok' => false,
                    'message' => 'Reference document storage is currently full. Please contact an administrator.',
                    'code' => 'AI_HELPER_DOCUMENT_GLOBAL_STORAGE_LIMIT',
                ];
            }
        }

        return ['ok' => true];
    }
}
