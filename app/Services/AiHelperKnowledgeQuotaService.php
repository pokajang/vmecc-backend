<?php

namespace App\Services;

use App\Models\AiHelperKnowledgeEntry;
use App\Models\User;
use Illuminate\Http\UploadedFile;

class AiHelperKnowledgeQuotaService
{
    /**
     * @return array{ok: bool, message?: string, code?: string}
     */
    public function checkUpload(User $user, UploadedFile $file): array
    {
        $activeLimit = (int) config('ai_helper.knowledge_max_active_uploads_per_user', 100);
        $userBytesLimit = (int) config('ai_helper.knowledge_max_upload_bytes_per_user', 2147483648);
        $globalBytesLimit = (int) config('ai_helper.knowledge_max_total_upload_bytes', 21474836480);
        $incomingSize = (int) ($file->getSize() ?: 0);

        if ($activeLimit > 0) {
            $activeUploads = AiHelperKnowledgeEntry::query()
                ->where('uploaded_by', $user->id)
                ->whereIn('status', [
                    AiHelperKnowledgeEntry::STATUS_PROCESSING,
                    AiHelperKnowledgeEntry::STATUS_ACTIVE,
                    AiHelperKnowledgeEntry::STATUS_DISABLED,
                ])
                ->count();

            if ($activeUploads >= $activeLimit) {
                return [
                    'ok' => false,
                    'message' => 'Ask AI knowledge upload limit reached. Delete old knowledge sources before uploading more.',
                    'code' => 'AI_HELPER_KNOWLEDGE_UPLOAD_LIMIT',
                ];
            }
        }

        if ($userBytesLimit > 0) {
            $userBytes = (int) AiHelperKnowledgeEntry::query()
                ->withTrashed()
                ->where('uploaded_by', $user->id)
                ->sum('source_size');

            if (($userBytes + $incomingSize) > $userBytesLimit) {
                return [
                    'ok' => false,
                    'message' => 'Ask AI storage limit reached for your account. Delete old knowledge sources before uploading more.',
                    'code' => 'AI_HELPER_KNOWLEDGE_STORAGE_LIMIT',
                ];
            }
        }

        if ($globalBytesLimit > 0) {
            $globalBytes = (int) AiHelperKnowledgeEntry::query()
                ->withTrashed()
                ->sum('source_size');

            if (($globalBytes + $incomingSize) > $globalBytesLimit) {
                return [
                    'ok' => false,
                    'message' => 'Ask AI storage is currently full. Please contact an administrator.',
                    'code' => 'AI_HELPER_KNOWLEDGE_GLOBAL_STORAGE_LIMIT',
                ];
            }
        }

        return ['ok' => true];
    }
}
