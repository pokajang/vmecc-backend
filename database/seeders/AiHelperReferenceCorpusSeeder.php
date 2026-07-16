<?php

namespace Database\Seeders;

use App\Models\AiHelperDocument;
use App\Models\AiHelperKnowledgeEntry;
use App\Models\User;
use App\Services\AiHelperKnowledgeLifecycleService;
use App\Services\AiHelperKnowledgeProcessingService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class AiHelperReferenceCorpusSeeder extends Seeder
{
    private const UPLOADER_EMAIL = 'azam@amiosh.com';

    private const STORAGE_DIRECTORY = 'ai-helper/documents/seeded-ai-knowledge-pdfs';

    public function run(): void
    {
        $sourceDirectory = $this->sourceDirectory();
        $pdfDirectory = $sourceDirectory.DIRECTORY_SEPARATOR.'pdf';
        $markdownDirectory = $sourceDirectory.DIRECTORY_SEPARATOR.'md';
        if (! is_dir($pdfDirectory) || ! is_dir($markdownDirectory)) {
            throw new RuntimeException("AI reference corpus directories not found under: {$sourceDirectory}");
        }

        $uploaderId = User::query()->where('email', self::UPLOADER_EMAIL)->value('id');
        $processor = app(AiHelperKnowledgeProcessingService::class);
        $lifecycle = app(AiHelperKnowledgeLifecycleService::class);
        $pdfFiles = glob($pdfDirectory.DIRECTORY_SEPARATOR.'*.pdf') ?: [];
        sort($pdfFiles, SORT_NATURAL | SORT_FLAG_CASE);

        foreach ($pdfFiles as $pdfFile) {
            $markdownFile = $markdownDirectory.DIRECTORY_SEPARATOR.pathinfo($pdfFile, PATHINFO_FILENAME).'.md';
            if (! is_file($markdownFile)) {
                throw new RuntimeException('Matching Markdown source not found for '.basename($pdfFile));
            }

            $this->seedPair(
                $pdfFile,
                $markdownFile,
                $uploaderId ? (int) $uploaderId : null,
                $processor,
                $lifecycle,
            );
        }
    }

    private function sourceDirectory(): string
    {
        $configuredPath = trim((string) config('ai_helper.reference_corpus_path', ''));
        if ($configuredPath === '') {
            return base_path('../ai_knowledge');
        }

        $isAbsolute = str_starts_with($configuredPath, '/')
            || str_starts_with($configuredPath, '\\')
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $configuredPath) === 1;

        return $isAbsolute ? $configuredPath : base_path($configuredPath);
    }

    private function seedPair(
        string $pdfFile,
        string $markdownFile,
        ?int $uploaderId,
        AiHelperKnowledgeProcessingService $processor,
        AiHelperKnowledgeLifecycleService $lifecycle,
    ): void {
        $pdfFilename = basename($pdfFile);
        $title = pathinfo($pdfFilename, PATHINFO_FILENAME) ?: 'Reference document';
        $storedFilename = $this->storedFilename($pdfFilename);
        $storagePath = self::STORAGE_DIRECTORY.'/'.$storedFilename;
        $legacyStoragePath = 'ai-helper/knowledge/seeded-ai-knowledge-pdfs/'.$storedFilename;
        $pdfContents = file_get_contents($pdfFile);
        $markdownContent = file_get_contents($markdownFile);

        if ($pdfContents === false || $markdownContent === false) {
            throw new RuntimeException("Could not read reference corpus pair: {$pdfFilename}");
        }

        Storage::disk('local')->put($storagePath, $pdfContents);
        $document = AiHelperDocument::withTrashed()
            ->where(function ($query) use ($storagePath, $legacyStoragePath) {
                $query->where('source_path', $storagePath)
                    ->orWhere('source_path', $legacyStoragePath);
            })
            ->first();
        $documentAttributes = [
            'uploaded_by' => $uploaderId,
            'title' => Str::limit($title, 140, ''),
            'source_filename' => Str::limit($pdfFilename, 255, ''),
            'source_mime' => 'application/pdf',
            'source_size' => filesize($pdfFile) ?: null,
            'source_path' => $storagePath,
            'source_hash' => hash('sha256', $pdfContents),
            'visibility' => AiHelperDocument::VISIBILITY_SHARED,
            'acknowledged_at' => now(),
        ];

        if ($document) {
            $previousPath = (string) $document->source_path;
            if ($document->trashed()) {
                $document->restore();
            }
            $document->forceFill($documentAttributes)->save();
            if ($previousPath !== '' && $previousPath !== $storagePath) {
                Storage::disk('local')->delete($previousPath);
            }
        } else {
            $document = AiHelperDocument::create($documentAttributes);
        }

        $sourcePath = 'seed:ai_knowledge:'.sha1($pdfFilename);
        $entry = AiHelperKnowledgeEntry::withTrashed()
            ->where(function ($query) use ($sourcePath, $document, $markdownFile) {
                $query->where('source_path', $sourcePath)
                    ->orWhere(function ($linked) use ($document, $markdownFile) {
                        $linked
                            ->where('source_document_id', $document->id)
                            ->where('source_mime', 'text/markdown')
                            ->where('source_filename', basename($markdownFile));
                    });
            })
            ->first();
        $entryAttributes = [
            'uploaded_by' => $uploaderId,
            'source_document_id' => $document->id,
            'module_key' => null,
            'route_key' => null,
            'title' => Str::limit($title, 140, ''),
            'content' => $markdownContent,
            'summary' => null,
            'source_filename' => Str::limit(basename($markdownFile), 255, ''),
            'source_mime' => 'text/markdown',
            'source_size' => filesize($markdownFile) ?: null,
            'source_path' => $sourcePath,
            'content_hash' => hash('sha256', $markdownContent),
            'scope_type' => AiHelperKnowledgeEntry::SCOPE_GLOBAL,
            'visibility' => AiHelperKnowledgeEntry::VISIBILITY_SHARED,
            'status' => AiHelperKnowledgeEntry::STATUS_ACTIVE,
            'review_status' => AiHelperKnowledgeEntry::REVIEW_APPROVED,
            'reviewed_by' => $uploaderId,
            'reviewed_at' => now(),
            'review_note' => 'Seeded from the source-fidelity ai_knowledge corpus.',
            'active' => true,
            'acknowledged_at' => now(),
            'error' => null,
            'tags' => ['seed', 'markdown', 'ai_knowledge', 'emergency-response'],
            'version' => 1,
        ];

        if ($entry) {
            if ($entry->trashed()) {
                $entry->restore();
            }
            $entry->forceFill($entryAttributes)->save();
        } else {
            $entry = AiHelperKnowledgeEntry::create($entryAttributes);
        }

        $runId = $lifecycle->beginIngestion($entry);
        $processor->processTextEntry($entry, $markdownContent, null, [], $runId);
    }

    private function storedFilename(string $sourceFilename): string
    {
        $stem = pathinfo($sourceFilename, PATHINFO_FILENAME);
        $slug = Str::slug($stem) ?: 'document';

        return $slug.'-'.substr(sha1($sourceFilename), 0, 10).'.pdf';
    }
}
