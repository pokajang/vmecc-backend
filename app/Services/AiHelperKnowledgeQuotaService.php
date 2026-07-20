<?php

namespace App\Services;

use App\Models\AiHelperKnowledgeEntry;
use App\Models\User;
use Illuminate\Http\UploadedFile;

class AiHelperKnowledgeQuotaService
{
    public function __construct(private readonly AiHelperStorageCapacityService $storageCapacity) {}

    /**
     * @return array{ok: bool, message?: string, code?: string}
     */
    public function checkUpload(User $user, UploadedFile $file): array
    {
        $activeLimit = (int) config('ai_helper.knowledge_max_active_uploads_per_user', 100);
        $userBytesLimit = (int) config('ai_helper.knowledge_max_upload_bytes_per_user', 2147483648);
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

        $capacity = $this->storageCapacity->checkUpload(
            AiHelperStorageCapacityService::UPLOAD_KNOWLEDGE,
            $incomingSize,
        );
        if (! $capacity['ok']) {
            return $this->capacityFailure((string) ($capacity['code'] ?? 'AI_HELPER_STORAGE_CAPACITY_UNAVAILABLE'));
        }

        return ['ok' => true];
    }

    /** @return array{ok: false, message: string, code: string} */
    private function capacityFailure(string $code): array
    {
        return [
            'ok' => false,
            'message' => match ($code) {
                'AI_HELPER_KNOWLEDGE_GLOBAL_STORAGE_LIMIT' => 'Ask AI storage is currently full. Please contact an administrator.',
                'AI_HELPER_STORAGE_HEADROOM_LIMIT' => 'Ask AI knowledge uploads are temporarily paused to preserve server storage capacity.',
                default => 'Ask AI knowledge upload capacity could not be verified. Please try again later.',
            },
            'code' => $code,
        ];
    }
}
