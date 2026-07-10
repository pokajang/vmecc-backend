<?php

namespace App\Services;

use App\Models\AiHelperKnowledgeEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class AiHelperKnowledgeLifecycleService
{
    public function beginIngestion(AiHelperKnowledgeEntry $entry): string
    {
        $runId = (string) str()->uuid();
        $entry->forceFill([
            'status' => AiHelperKnowledgeEntry::STATUS_PROCESSING,
            'active' => false,
            'ingestion_run_id' => $runId,
            'ingestion_version' => max(1, (int) $entry->ingestion_version) + 1,
            'ingestion_started_at' => now(),
            'ingestion_completed_at' => null,
            'extraction_complete' => false,
            'extracted_characters' => 0,
            'error' => null,
        ])->save();

        return $runId;
    }

    public function purge(AiHelperKnowledgeEntry $entry): void
    {
        $sourcePath = DB::transaction(function () use ($entry) {
            $locked = AiHelperKnowledgeEntry::query()->lockForUpdate()->find($entry->id);
            if (! $locked) {
                throw new RuntimeException('Knowledge entry no longer exists.');
            }

            $sourcePath = trim((string) $locked->source_path);
            $locked->forceFill([
                'status' => AiHelperKnowledgeEntry::STATUS_DELETING,
                'active' => false,
                'ingestion_run_id' => null,
                'ingestion_version' => max(1, (int) $locked->ingestion_version) + 1,
                'extraction_complete' => false,
                'content' => '',
                'summary' => null,
                'content_hash' => null,
                'extracted_characters' => 0,
                'processing_warnings' => null,
                'error' => null,
            ])->save();
            $locked->chunks()->delete();

            return $sourcePath;
        });

        if ($sourcePath !== '' && ! str_starts_with($sourcePath, 'seed:') && Storage::disk('local')->exists($sourcePath)) {
            if (! Storage::disk('local')->delete($sourcePath)) {
                throw new RuntimeException('Could not permanently delete the original knowledge file.');
            }
        }

        DB::transaction(function () use ($entry) {
            $locked = AiHelperKnowledgeEntry::query()->lockForUpdate()->find($entry->id);
            if (! $locked) {
                return;
            }
            $locked->forceFill([
                'status' => AiHelperKnowledgeEntry::STATUS_DELETED,
                'active' => false,
                'source_path' => null,
                'source_size' => 0,
                'extraction_complete' => false,
            ])->save();
            $locked->delete();
        });
    }
}
