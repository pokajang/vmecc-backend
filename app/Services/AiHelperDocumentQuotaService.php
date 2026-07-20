<?php

namespace App\Services;

use App\Models\AiHelperDocument;
use App\Models\User;
use Illuminate\Http\UploadedFile;

class AiHelperDocumentQuotaService
{
    public function __construct(private readonly AiHelperStorageCapacityService $storageCapacity) {}

    /** @return array{ok: bool, message?: string, code?: string} */
    public function checkUpload(User $user, UploadedFile $file): array
    {
        $activeLimit = (int) config('ai_helper.document_max_active_uploads_per_user', 100);
        $userBytesLimit = (int) config('ai_helper.document_max_upload_bytes_per_user', 2147483648);
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

        $capacity = $this->storageCapacity->checkUpload(
            AiHelperStorageCapacityService::UPLOAD_DOCUMENTS,
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
                'AI_HELPER_DOCUMENT_GLOBAL_STORAGE_LIMIT' => 'Reference document storage is currently full. Please contact an administrator.',
                'AI_HELPER_STORAGE_HEADROOM_LIMIT' => 'Reference document uploads are temporarily paused to preserve server storage capacity.',
                default => 'Reference document upload capacity could not be verified. Please try again later.',
            },
            'code' => $code,
        ];
    }
}
