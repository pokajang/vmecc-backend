<?php

namespace Database\Seeders;

use App\Models\AiHelperKnowledgeEntry;
use App\Models\User;
use App\Services\AiHelperKnowledgeLifecycleService;
use App\Services\AiHelperKnowledgeProcessingService;
use App\Services\AiHelperKnowledgeRuntimeService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class AiHelperPdfKnowledgeSeeder extends Seeder
{
    private const UPLOADER_EMAIL = 'azam@amiosh.com';

    private const SOURCE_DIRECTORY = '../ai_knowledge';

    private const STORAGE_DIRECTORY = 'ai-helper/knowledge/seeded-ai-knowledge-pdfs';

    public function run(): void
    {
        app(AiHelperKnowledgeRuntimeService::class)->assertPdfIngestionReady();

        $uploader = User::query()
            ->where('email', self::UPLOADER_EMAIL)
            ->first();

        if (! $uploader) {
            throw new RuntimeException(
                'Admin user not found. Run AdminUserSeeder before AiHelperPdfKnowledgeSeeder.'
            );
        }

        $sourceDirectory = base_path(self::SOURCE_DIRECTORY);
        if (! is_dir($sourceDirectory)) {
            throw new RuntimeException("AI knowledge directory not found: {$sourceDirectory}");
        }

        $processor = app(AiHelperKnowledgeProcessingService::class);
        $lifecycle = app(AiHelperKnowledgeLifecycleService::class);
        $files = glob($sourceDirectory.DIRECTORY_SEPARATOR.'*.pdf') ?: [];
        sort($files, SORT_NATURAL | SORT_FLAG_CASE);

        foreach ($files as $file) {
            $this->seedPdf($file, $uploader, $processor, $lifecycle);
        }
    }

    private function seedPdf(
        string $file,
        User $uploader,
        AiHelperKnowledgeProcessingService $processor,
        AiHelperKnowledgeLifecycleService $lifecycle,
    ): void {
        $sourceFilename = basename($file);
        $title = pathinfo($sourceFilename, PATHINFO_FILENAME) ?: 'Seeded PDF knowledge';
        $storagePath = self::STORAGE_DIRECTORY.'/'.$this->storedFilename($sourceFilename);
        $contents = file_get_contents($file);

        if ($contents === false) {
            throw new RuntimeException("Could not read AI knowledge PDF: {$file}");
        }

        Storage::disk('local')->put($storagePath, $contents);

        $entry = AiHelperKnowledgeEntry::withTrashed()
            ->where('source_path', $storagePath)
            ->first();

        $sourceAttributes = [
            'uploaded_by' => $uploader->id,
            'module_key' => null,
            'route_key' => null,
            'title' => Str::limit($title, 255, ''),
            'source_filename' => Str::limit($sourceFilename, 255, ''),
            'source_mime' => 'application/pdf',
            'source_size' => filesize($file) ?: null,
            'source_path' => $storagePath,
            'scope_type' => AiHelperKnowledgeEntry::SCOPE_GLOBAL,
            'visibility' => AiHelperKnowledgeEntry::VISIBILITY_SHARED,
            'review_status' => AiHelperKnowledgeEntry::REVIEW_APPROVED,
            'reviewed_by' => $uploader->id,
            'reviewed_at' => now(),
            'review_note' => 'Seeded from ai_knowledge.',
            'acknowledged_at' => now(),
            'tags' => ['seed', 'pdf', 'ai_knowledge', 'erp', 'emergency-response'],
        ];

        if ($entry) {
            if ($entry->trashed()) {
                $entry->restore();
            }

            $entry->forceFill($sourceAttributes)->save();
        } else {
            $entry = AiHelperKnowledgeEntry::create($sourceAttributes + [
                'content' => '',
                'summary' => null,
                'content_hash' => null,
                'status' => AiHelperKnowledgeEntry::STATUS_DISABLED,
                'active' => false,
                'processed_at' => null,
                'error' => null,
                'version' => 1,
            ]);
        }

        $runId = $lifecycle->beginIngestion($entry);
        $processor->process($entry->id, $runId);
        $processed = $entry->fresh();
        if (! $processed
            || $processed->status !== AiHelperKnowledgeEntry::STATUS_ACTIVE
            || ! $processed->extraction_complete
            || trim((string) $processed->error) !== '') {
            throw new RuntimeException(sprintf(
                'Failed to seed AI knowledge PDF "%s": %s',
                $sourceFilename,
                trim((string) ($processed?->error ?: 'the document did not produce a complete active index')),
            ));
        }
    }

    private function storedFilename(string $sourceFilename): string
    {
        $stem = pathinfo($sourceFilename, PATHINFO_FILENAME);
        $slug = Str::slug($stem);
        $hash = substr(sha1($sourceFilename), 0, 10);

        return ($slug !== '' ? $slug : 'knowledge').'-'.$hash.'.pdf';
    }
}
