<?php

namespace Database\Seeders;

use App\Models\AiHelperDocument;
use App\Models\AiHelperKnowledgeEntry;
use App\Models\User;
use App\Services\AiHelperKnowledgeLifecycleService;
use App\Services\AiHelperKnowledgeProcessingService;
use App\Services\AiHelperReferenceCorpusCatalog;
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
        $catalog = app(AiHelperReferenceCorpusCatalog::class);
        $sources = $catalog->sources();
        if ($sources === []) {
            throw new RuntimeException('The AI reference Markdown corpus is empty.');
        }
        if ($catalog->orphanPdfFiles() !== []) {
            throw new RuntimeException(
                'Reference PDFs without matching Markdown: '.implode(', ', $catalog->orphanPdfFiles()),
            );
        }

        $uploaderId = User::query()->where('email', self::UPLOADER_EMAIL)->value('id');
        $processor = app(AiHelperKnowledgeProcessingService::class);
        $lifecycle = app(AiHelperKnowledgeLifecycleService::class);

        foreach ($sources as $source) {
            $this->seedSource(
                $source,
                $uploaderId ? (int) $uploaderId : null,
                $processor,
                $lifecycle,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private function seedSource(
        array $source,
        ?int $uploaderId,
        AiHelperKnowledgeProcessingService $processor,
        AiHelperKnowledgeLifecycleService $lifecycle,
    ): void {
        $markdownFile = (string) $source['markdown_path'];
        $markdownContent = file_get_contents($markdownFile);
        if ($markdownContent === false || trim($markdownContent) === '') {
            throw new RuntimeException('Could not read non-empty reference Markdown: '.basename($markdownFile));
        }
        $maximumBytes = max(1, (int) config('ai_helper.markdown_upload_max_kb', 1024)) * 1024;
        if (strlen($markdownContent) > $maximumBytes) {
            throw new RuntimeException('Reference Markdown exceeds the configured size limit: '.basename($markdownFile));
        }

        $sourcePath = (string) $source['source_path'];
        $legacySourcePath = (string) $source['legacy_source_path'];
        $entry = AiHelperKnowledgeEntry::withTrashed()
            ->where('knowledge_type', AiHelperKnowledgeEntry::KNOWLEDGE_REFERENCE_DOCUMENT)
            ->where(function ($query) use ($sourcePath, $legacySourcePath, $markdownFile) {
                $query->whereIn('source_path', [$sourcePath, $legacySourcePath])
                    ->orWhere(function ($linked) use ($markdownFile) {
                        $linked
                            ->where('source_path', 'like', 'seed:ai_knowledge:%')
                            ->where('source_mime', 'text/markdown')
                            ->where('source_filename', basename($markdownFile));
                    });
            })
            ->first();
        $previousDocument = $entry?->sourceDocument;
        $document = $this->syncOptionalPdf($source, $uploaderId, $previousDocument);
        $title = Str::limit(trim((string) $source['title']), 140, '');
        $entryAttributes = [
            'uploaded_by' => $uploaderId,
            'source_document_id' => $document?->id,
            'knowledge_type' => AiHelperKnowledgeEntry::KNOWLEDGE_REFERENCE_DOCUMENT,
            'module_key' => null,
            'route_key' => null,
            'title' => $title,
            'content' => $markdownContent,
            'summary' => null,
            'source_filename' => Str::limit(basename($markdownFile), 255, ''),
            'source_mime' => 'text/markdown',
            'source_size' => filesize($markdownFile) ?: null,
            'source_path' => $sourcePath,
            'content_hash' => hash('sha256', $markdownContent),
            'scope_type' => (string) $source['scope_type'],
            'visibility' => (string) $source['visibility'],
            'status' => AiHelperKnowledgeEntry::STATUS_ACTIVE,
            'review_status' => (string) $source['review_status'],
            'reviewed_by' => $uploaderId,
            'reviewed_at' => now(),
            'review_note' => $document
                ? 'Seeded from canonical Markdown with an optional PDF attachment.'
                : 'Seeded from canonical Markdown without a PDF attachment.',
            'active' => true,
            'acknowledged_at' => now(),
            'error' => null,
            'tags' => collect($source['tags'] ?? [])->map(fn ($tag) => trim((string) $tag))->filter()->unique()->values()->all(),
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

        if ($previousDocument && $previousDocument->id !== $document?->id) {
            $this->deleteUnusedSeededDocument($previousDocument);
        }

        $runId = $lifecycle->beginIngestion($entry);
        $processor->processTextEntry($entry, $markdownContent, null, [], $runId);
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private function syncOptionalPdf(
        array $source,
        ?int $uploaderId,
        ?AiHelperDocument $linkedDocument,
    ): ?AiHelperDocument {
        $pdfFile = $source['pdf_path'] ?? null;
        if (! is_string($pdfFile) || $pdfFile === '') {
            return null;
        }

        $pdfContents = file_get_contents($pdfFile);
        if ($pdfContents === false) {
            throw new RuntimeException('Could not read reference PDF attachment: '.basename($pdfFile));
        }
        $pdfFilename = basename($pdfFile);
        $storedFilename = $this->storedFilename($pdfFilename);
        $storagePath = self::STORAGE_DIRECTORY.'/'.$storedFilename;
        $legacyStoragePath = 'ai-helper/knowledge/seeded-ai-knowledge-pdfs/'.$storedFilename;
        Storage::disk('local')->put($storagePath, $pdfContents);

        $document = $linkedDocument
            ?: AiHelperDocument::withTrashed()
                ->where(fn ($query) => $query
                    ->where('source_path', $storagePath)
                    ->orWhere('source_path', $legacyStoragePath))
                ->first();
        $attributes = [
            'uploaded_by' => $uploaderId,
            'title' => Str::limit(trim((string) $source['title']), 140, ''),
            'source_filename' => Str::limit($pdfFilename, 255, ''),
            'source_mime' => 'application/pdf',
            'source_size' => filesize($pdfFile) ?: null,
            'source_path' => $storagePath,
            'source_hash' => hash('sha256', $pdfContents),
            'visibility' => (string) $source['visibility'],
            'acknowledged_at' => now(),
        ];

        if ($document) {
            $previousPath = (string) $document->source_path;
            if ($document->trashed()) {
                $document->restore();
            }
            $document->forceFill($attributes)->save();
            if ($previousPath !== '' && $previousPath !== $storagePath) {
                Storage::disk('local')->delete($previousPath);
            }

            return $document;
        }

        return AiHelperDocument::create($attributes);
    }

    private function deleteUnusedSeededDocument(AiHelperDocument $document): void
    {
        $document->refresh();
        if ($document->knowledgeEntries()->exists()) {
            return;
        }

        $path = trim((string) $document->source_path);
        $allowedPrefixes = [
            self::STORAGE_DIRECTORY.'/',
            'ai-helper/knowledge/seeded-ai-knowledge-pdfs/',
        ];
        if (collect($allowedPrefixes)->contains(fn (string $prefix): bool => str_starts_with($path, $prefix))) {
            Storage::disk('local')->delete($path);
            $document->delete();
        }
    }

    private function storedFilename(string $sourceFilename): string
    {
        $stem = pathinfo($sourceFilename, PATHINFO_FILENAME);
        $slug = Str::slug($stem) ?: 'document';

        return $slug.'-'.substr(sha1($sourceFilename), 0, 10).'.pdf';
    }
}
