<?php

namespace App\Services;

use App\Models\AiHelperKnowledgeChunk;
use App\Models\AiHelperKnowledgeEntry;
use App\Models\AiHelperKnowledgePage;
use App\Services\AiHelper\PdfKnowledgeExtractionResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class AiHelperKnowledgeProcessingService
{
    public function __construct(
        private readonly AiHelperPdfKnowledgeExtractor $pdfExtractor,
        private readonly AiHelperKnowledgeRuntimeService $runtime,
    ) {}

    public function process(int $entryId, ?string $expectedRunId = null): void
    {
        $entry = AiHelperKnowledgeEntry::query()->find($entryId);
        if (! $entry || ! $this->canProcess($entry, $expectedRunId)) {
            return;
        }

        try {
            if (str_starts_with((string) $entry->source_path, 'seed:') || $entry->source_mime === 'text/markdown') {
                $this->processTextEntry($entry, (string) $entry->content, $entry->summary, [], $expectedRunId);

                return;
            }

            $sourcePath = trim((string) $entry->source_path);
            if ($sourcePath === '') {
                throw new RuntimeException('The original source file is unavailable for ingestion.');
            }

            $this->runtime->assertPdfIngestionReady();
            $extractionResult = $this->pdfExtractor->extract(
                Storage::disk('local')->path($sourcePath),
                (int) config('ai_helper.knowledge_extract_max_characters', 0),
            );
            $extraction = $extractionResult instanceof PdfKnowledgeExtractionResult
                ? $extractionResult->toArray()
                : $extractionResult;
            if (trim((string) ($extraction['text'] ?? '')) === '') {
                throw new RuntimeException('No readable text could be extracted from this PDF, including OCR.');
            }
            if (! ($extraction['extraction_complete'] ?? trim((string) ($extraction['text'] ?? '')) !== '')) {
                if ($this->hasPreviousUsableIndex($entry)) {
                    $this->retainPreviousIndex($entry->id, $expectedRunId, $extraction);

                    return;
                }

                $this->processTextEntry(
                    $entry,
                    (string) $extraction['text'],
                    null,
                    $this->pdfMetadata($extraction),
                    $expectedRunId,
                    $extraction['pages'] ?? [],
                    (string) ($extraction['extraction_mode'] ?? 'native'),
                    false,
                    false,
                );

                return;
            }

            $this->processTextEntry(
                $entry,
                (string) $extraction['text'],
                null,
                $this->pdfMetadata($extraction),
                $expectedRunId,
                $extraction['pages'] ?? [],
                (string) ($extraction['extraction_mode'] ?? 'native'),
                true,
                true,
            );
        } catch (Throwable $e) {
            Log::warning('Ask AI knowledge processing failed', [
                'knowledge_entry_id' => $entryId,
                'error' => $e->getMessage(),
            ]);
            if ($e instanceof RuntimeException) {
                $this->markFailed($entryId, $expectedRunId, $e->getMessage());

                return;
            }

            throw $e;
        }
    }

    /**
     * @param  array<int, array{number: int, text: string, extraction_mode: string}>  $pages
     */
    public function processTextEntry(
        AiHelperKnowledgeEntry $entry,
        string $content,
        ?string $summary = null,
        array $metadata = [],
        ?string $expectedRunId = null,
        array $pages = [],
        string $extractionMode = 'native',
        bool $activate = true,
        bool $extractionComplete = true,
    ): bool {
        $content = $this->normalizeText($content);
        $chunks = $this->chunkPages($pages, $content, $extractionMode);
        if ($chunks === []) {
            $this->markFailed($entry->id, $expectedRunId, 'Could not prepare readable guidance from this knowledge source.');

            return false;
        }

        try {
            return DB::transaction(function () use ($entry, $content, $summary, $metadata, $expectedRunId, $chunks, $pages, $extractionMode, $activate, $extractionComplete) {
                $locked = AiHelperKnowledgeEntry::query()->lockForUpdate()->find($entry->id);
                if (! $locked || ! $this->canProcess($locked, $expectedRunId)) {
                    return false;
                }

                $locked->chunks()->delete();
                $locked->pages()->delete();
                $ingestionVersion = max(1, (int) $locked->ingestion_version);
                foreach ($chunks as $index => $chunk) {
                    AiHelperKnowledgeChunk::create([
                        'knowledge_entry_id' => $locked->id,
                        'chunk_index' => $index,
                        'page_start' => $chunk['page_start'],
                        'page_end' => $chunk['page_end'],
                        'content' => $chunk['content'],
                        'content_hash' => hash('sha256', $chunk['content']),
                        'token_estimate' => $this->estimateTokens($chunk['content']),
                        'module_key' => $locked->module_key,
                        'route_key' => $locked->route_key,
                        'active' => $activate,
                        'extraction_mode' => $chunk['extraction_mode'],
                        'ingestion_version' => $ingestionVersion,
                    ]);
                }
                foreach ($pages as $page) {
                    if (! isset($page['outcome'])) {
                        continue;
                    }
                    AiHelperKnowledgePage::create([
                        'knowledge_entry_id' => $locked->id,
                        'ingestion_version' => $ingestionVersion,
                        'page_number' => max(1, (int) ($page['number'] ?? 1)),
                        'outcome' => (string) $page['outcome'],
                        'native_character_count' => max(0, (int) ($page['native_character_count'] ?? 0)),
                        'native_word_count' => max(0, (int) ($page['native_word_count'] ?? 0)),
                        'ocr_character_count' => max(0, (int) ($page['ocr_character_count'] ?? 0)),
                        'ocr_word_count' => max(0, (int) ($page['ocr_word_count'] ?? 0)),
                        'image_count' => max(0, (int) ($page['image_count'] ?? 0)),
                        'ocr_attempted' => (bool) ($page['ocr_attempted'] ?? false),
                        'ocr_succeeded' => (bool) ($page['ocr_succeeded'] ?? false),
                        'findings' => $this->processingFindings($page['findings'] ?? []),
                    ]);
                }

                $locked->forceFill([
                    'content' => $content,
                    'summary' => $summary !== null && trim($summary) !== ''
                        ? Str::limit($this->normalizeText($summary), 320, '')
                        : $this->buildSummary($content),
                    'content_hash' => hash('sha256', $content),
                    'status' => $activate
                        ? AiHelperKnowledgeEntry::STATUS_ACTIVE
                        : AiHelperKnowledgeEntry::STATUS_DISABLED,
                    'active' => $activate,
                    'processed_at' => now(),
                    'ingestion_completed_at' => now(),
                    'extraction_mode' => $extractionMode,
                    'extraction_complete' => $extractionComplete,
                    'quality_status' => $metadata['quality_status'] ?? ($extractionComplete ? 'ready' : 'review_required'),
                    'extracted_characters' => Str::length($content),
                    'error' => null,
                ] + $metadata)->save();

                return true;
            });
        } catch (Throwable $e) {
            Log::warning('Ask AI knowledge chunk persistence failed', [
                'knowledge_entry_id' => $entry->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /** @return array<int, array{content: string, page_start: int, page_end: int, extraction_mode: string}> */
    private function chunkPages(array $pages, string $fallbackContent, string $fallbackMode): array
    {
        if ($pages === []) {
            $pages = [['number' => 1, 'text' => $fallbackContent, 'extraction_mode' => $fallbackMode]];
        }

        $chunks = [];
        foreach ($pages as $page) {
            $pageText = $this->normalizeText((string) ($page['text'] ?? ''));
            if ($pageText === '') {
                continue;
            }
            foreach ($this->splitText($pageText) as $text) {
                $chunks[] = [
                    'content' => $text,
                    'page_start' => max(1, (int) ($page['number'] ?? 1)),
                    'page_end' => max(1, (int) ($page['number'] ?? 1)),
                    'extraction_mode' => (string) ($page['extraction_mode'] ?? $fallbackMode),
                ];
            }
        }

        $maxChunks = (int) config('ai_helper.knowledge_max_chunks_per_entry', 0);
        if ($maxChunks > 0 && count($chunks) > $maxChunks) {
            throw new RuntimeException('Knowledge source exceeds the configured chunk limit. The document was not partially indexed.');
        }

        return $chunks;
    }

    /** @return array<int, string> */
    private function splitText(string $text): array
    {
        $targetSize = max(600, (int) config('ai_helper.knowledge_chunk_characters', 1000));
        $chunks = [];
        $offset = 0;
        $length = Str::length($text);

        while ($offset < $length) {
            $slice = Str::substr($text, $offset, $targetSize);
            $nextOffset = $offset + Str::length($slice);
            if ($nextOffset < $length) {
                $breaks = array_filter([
                    strrpos($slice, '. '),
                    strrpos($slice, '; '),
                    strrpos($slice, "\n"),
                ], static fn ($position) => $position !== false);
                if ($breaks !== []) {
                    $lastBreak = max($breaks);
                    if ($lastBreak > (int) ($targetSize * 0.55)) {
                        $slice = Str::substr($slice, 0, $lastBreak + 1);
                        $nextOffset = $offset + Str::length($slice);
                    }
                }
            }
            $slice = trim($slice);
            if ($slice !== '') {
                $chunks[] = $slice;
            }
            $offset = max($nextOffset, $offset + 1);
        }

        return $chunks;
    }

    private function canProcess(AiHelperKnowledgeEntry $entry, ?string $expectedRunId): bool
    {
        return $entry->status === AiHelperKnowledgeEntry::STATUS_PROCESSING
            && ($expectedRunId === null || hash_equals((string) $entry->ingestion_run_id, $expectedRunId));
    }

    private function markFailed(int $entryId, ?string $expectedRunId, string $message): void
    {
        DB::transaction(function () use ($entryId, $expectedRunId, $message) {
            $entry = AiHelperKnowledgeEntry::query()->lockForUpdate()->find($entryId);
            if (! $entry || ! $this->canProcess($entry, $expectedRunId)) {
                return;
            }
            if ($this->hasPreviousUsableIndex($entry)) {
                $entry->forceFill([
                    'status' => AiHelperKnowledgeEntry::STATUS_ACTIVE,
                    'active' => true,
                    'ingestion_completed_at' => now(),
                    'error' => Str::limit('Re-index failed; the previous index remains active. '.$message, 1000, ''),
                ])->save();

                return;
            }
            $entry->chunks()->delete();
            $entry->pages()->delete();
            $entry->forceFill([
                'status' => AiHelperKnowledgeEntry::STATUS_FAILED,
                'active' => false,
                'content' => '',
                'summary' => null,
                'processed_at' => now(),
                'ingestion_completed_at' => now(),
                'extraction_complete' => false,
                'quality_status' => 'failed',
                'extracted_characters' => 0,
                'error' => Str::limit($message, 1000, ''),
            ])->save();
        });
    }

    public function markFailedForRun(int $entryId, ?string $expectedRunId, string $message): void
    {
        $this->markFailed($entryId, $expectedRunId, $message);
    }

    private function buildSummary(string $content): string
    {
        $sentences = preg_split('/(?<=[.!?])\s+/', $content, 4) ?: [];

        return Str::limit(trim(implode(' ', array_slice(array_filter($sentences), 0, 2))) ?: $content, 320, '');
    }

    private function normalizeText(string $text): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $text));
    }

    private function estimateTokens(string $content): int
    {
        return max(1, (int) ceil(Str::length($content) / 4));
    }

    private function pdfMetadata(array $extraction): array
    {
        return [
            'pdf_page_count' => max(0, (int) ($extraction['page_count'] ?? 0)),
            'pdf_image_count' => max(0, (int) ($extraction['image_count'] ?? 0)),
            'pdf_pages_with_images' => max(0, (int) ($extraction['pages_with_images'] ?? 0)),
            'pdf_readable_text_characters' => max(0, (int) ($extraction['readable_text_characters'] ?? 0)),
            'pdf_readable_word_count' => max(0, (int) ($extraction['readable_word_count'] ?? 0)),
            'pdf_image_coverage_estimate' => min(100, max(0, (int) ($extraction['image_coverage_estimate'] ?? 0))),
            'processing_warnings' => $this->processingWarnings($extraction['warnings'] ?? []),
            'processing_findings' => $this->processingFindings($extraction['findings'] ?? []),
            'quality_status' => (string) ($extraction['quality_status'] ?? 'ready'),
            'pages_indexed' => max(0, (int) ($extraction['pages_indexed'] ?? 0)),
            'pages_native' => max(0, (int) ($extraction['pages_native'] ?? 0)),
            'pages_ocr' => max(0, (int) ($extraction['pages_ocr'] ?? 0)),
            'pages_blank' => max(0, (int) ($extraction['pages_blank'] ?? 0)),
            'pages_visual_only' => max(0, (int) ($extraction['pages_visual_only'] ?? 0)),
            'pages_unreadable' => max(0, (int) ($extraction['pages_unreadable'] ?? 0)),
        ];
    }

    private function hasPreviousUsableIndex(AiHelperKnowledgeEntry $entry): bool
    {
        return (bool) $entry->active
            && (bool) $entry->extraction_complete
            && trim((string) $entry->content) !== ''
            && $entry->chunks()->where('active', true)->exists();
    }

    private function retainPreviousIndex(int $entryId, ?string $expectedRunId, array $extraction): void
    {
        DB::transaction(function () use ($entryId, $expectedRunId, $extraction) {
            $entry = AiHelperKnowledgeEntry::query()->lockForUpdate()->find($entryId);
            if (! $entry || ! $this->canProcess($entry, $expectedRunId) || ! $this->hasPreviousUsableIndex($entry)) {
                return;
            }

            $warnings = $this->processingWarnings($extraction['warnings'] ?? []);
            $message = $warnings
                ? 'Re-index requires review; the previous index remains active. '.implode(' ', $warnings)
                : 'Re-index requires review; the previous index remains active.';
            $entry->forceFill([
                'status' => AiHelperKnowledgeEntry::STATUS_ACTIVE,
                'active' => true,
                'ingestion_completed_at' => now(),
                'error' => Str::limit($message, 1000, ''),
            ])->save();
        });
    }

    private function processingWarnings(mixed $warnings): ?array
    {
        if (! is_array($warnings)) {
            return null;
        }
        $clean = collect($warnings)
            ->filter(static fn ($warning) => is_string($warning) && trim($warning) !== '')
            ->map(static fn (string $warning) => Str::limit(trim($warning), 500, ''))
            ->unique()
            ->values()
            ->all();

        return $clean === [] ? null : $clean;
    }

    private function processingFindings(mixed $findings): ?array
    {
        if (! is_array($findings)) {
            return null;
        }

        $clean = collect($findings)
            ->filter(static fn ($finding) => is_array($finding)
                && in_array($finding['severity'] ?? null, ['notice', 'warning', 'error'], true)
                && is_string($finding['code'] ?? null)
                && is_string($finding['message'] ?? null))
            ->map(static fn (array $finding) => [
                'severity' => $finding['severity'],
                'code' => Str::limit(trim($finding['code']), 64, ''),
                'page' => isset($finding['page']) ? max(1, (int) $finding['page']) : null,
                'message' => Str::limit(trim($finding['message']), 500, ''),
            ])
            ->unique(fn (array $finding) => implode('|', $finding))
            ->values()
            ->all();

        return $clean === [] ? null : $clean;
    }
}
