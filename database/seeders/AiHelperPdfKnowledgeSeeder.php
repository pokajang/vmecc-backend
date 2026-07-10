<?php

namespace Database\Seeders;

use App\Models\AiHelperKnowledgeEntry;
use App\Models\User;
use App\Services\AiHelperKnowledgeProcessingService;
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
        $files = glob($sourceDirectory.DIRECTORY_SEPARATOR.'*.pdf') ?: [];
        sort($files, SORT_NATURAL | SORT_FLAG_CASE);

        foreach ($files as $file) {
            $this->seedPdf($file, $uploader, $processor);
        }
    }

    private function seedPdf(string $file, User $uploader, AiHelperKnowledgeProcessingService $processor): void
    {
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

        $attributes = [
            'uploaded_by' => $uploader->id,
            'module_key' => null,
            'route_key' => null,
            'title' => Str::limit($title, 255, ''),
            'content' => '',
            'summary' => null,
            'source_filename' => Str::limit($sourceFilename, 255, ''),
            'source_mime' => 'application/pdf',
            'source_size' => filesize($file) ?: null,
            'source_path' => $storagePath,
            'content_hash' => null,
            'pdf_page_count' => null,
            'pdf_image_count' => null,
            'pdf_pages_with_images' => null,
            'pdf_readable_text_characters' => null,
            'pdf_readable_word_count' => null,
            'pdf_image_coverage_estimate' => null,
            'processing_warnings' => null,
            'scope_type' => AiHelperKnowledgeEntry::SCOPE_GLOBAL,
            'visibility' => AiHelperKnowledgeEntry::VISIBILITY_SHARED,
            'status' => AiHelperKnowledgeEntry::STATUS_PROCESSING,
            'review_status' => AiHelperKnowledgeEntry::REVIEW_APPROVED,
            'reviewed_by' => $uploader->id,
            'reviewed_at' => now(),
            'review_note' => 'Seeded from ai_knowledge.',
            'active' => false,
            'acknowledged_at' => now(),
            'processed_at' => null,
            'error' => null,
            'tags' => ['seed', 'pdf', 'ai_knowledge', 'erp', 'emergency-response'],
            'version' => 1,
        ];

        if ($entry) {
            if ($entry->trashed()) {
                $entry->restore();
            }

            $entry->forceFill($attributes)->save();
        } else {
            $entry = AiHelperKnowledgeEntry::create($attributes);
        }

        $processor->process($entry->id);
    }

    private function storedFilename(string $sourceFilename): string
    {
        $stem = pathinfo($sourceFilename, PATHINFO_FILENAME);
        $slug = Str::slug($stem);
        $hash = substr(sha1($sourceFilename), 0, 10);

        return ($slug !== '' ? $slug : 'knowledge').'-'.$hash.'.pdf';
    }
}
