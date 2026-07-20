<?php

namespace App\Services;

use App\Jobs\EmbedAiHelperKnowledgeEntry;
use App\Models\AiHelperKnowledgeChunk;
use App\Models\AiHelperKnowledgeEntry;
use App\Models\AiHelperKnowledgePage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class AiHelperKnowledgeProcessingService
{
    public const SYSTEM_GUIDE_MAINTAINER_HEADINGS = [
        'Source-of-truth code references for maintainers',
        'Guide maintenance',
    ];

    public function __construct(
        private readonly AiHelperMarkdownStructureParser $markdownStructure,
        private readonly AiHelperEmbeddingService $embeddings,
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

            throw new RuntimeException('PDF ingestion is disabled. Upload PDFs to the reference document library and index approved Markdown instead.');
        } catch (Throwable $e) {
            Log::warning('Ask AI knowledge processing failed', [
                'knowledge_entry_id' => $entryId,
                'exception_class' => $e::class,
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
        $content = $this->normalizeSourceContent($content, $entry);
        $chunks = $this->chunkPages($pages, $content, $extractionMode, $entry);
        if ($chunks === []) {
            $this->markFailed($entry->id, $expectedRunId, 'Could not prepare readable guidance from this knowledge source.');

            return false;
        }

        $requiresEmbedding = $activate && $this->embeddings->isAvailable();

        try {
            $result = DB::transaction(function () use ($entry, $content, $summary, $metadata, $expectedRunId, $chunks, $pages, $extractionMode, $activate, $extractionComplete, $requiresEmbedding) {
                $locked = AiHelperKnowledgeEntry::query()->lockForUpdate()->find($entry->id);
                if (! $locked || ! $this->canProcess($locked, $expectedRunId)) {
                    return ['processed' => false];
                }

                $ingestionVersion = max(1, (int) $locked->ingestion_version);
                $previousUsable = $this->hasPreviousUsableIndex($locked);
                $activeVersion = $previousUsable
                    ? $locked->chunks()->where('active', true)->max('ingestion_version')
                    : null;

                if ($requiresEmbedding) {
                    // Keep the serving version intact while the replacement is
                    // embedded. A repeated/stale ingestion run may have left an
                    // inactive stage, which is safe to replace.
                    $locked->chunks()->where('active', false)->delete();
                    if ($activeVersion === null) {
                        $locked->pages()->delete();
                    } else {
                        $locked->pages()->where('ingestion_version', '!=', $activeVersion)->delete();
                    }
                    if ($activeVersion !== null && $ingestionVersion <= (int) $activeVersion) {
                        $ingestionVersion = (int) $activeVersion + 1;
                        $locked->ingestion_version = $ingestionVersion;
                    }
                } else {
                    // Lexical-only indexing is completed in this transaction,
                    // so readers move directly from the old version to the new.
                    $locked->chunks()->delete();
                    $locked->pages()->delete();
                }

                foreach ($chunks as $index => $chunk) {
                    AiHelperKnowledgeChunk::create([
                        'knowledge_entry_id' => $locked->id,
                        'chunk_index' => $index,
                        'page_start' => $chunk['page_start'],
                        'page_end' => $chunk['page_end'],
                        'content' => $chunk['content'],
                        'heading_path' => $chunk['heading_path'] ?? null,
                        'content_type' => $chunk['content_type'] ?? 'text',
                        'search_text' => $chunk['search_text'] ?? $chunk['content'],
                        'content_hash' => hash('sha256', $chunk['content']),
                        'token_estimate' => $this->estimateTokens($chunk['content']),
                        'module_key' => $locked->module_key,
                        'route_key' => $locked->route_key,
                        'active' => $activate && ! $requiresEmbedding,
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

                $attributes = [
                    'content' => $content,
                    'summary' => $summary !== null && trim($summary) !== ''
                        ? Str::limit($this->normalizeText($summary), 320, '')
                        : $this->buildSummary($content),
                    'content_hash' => hash('sha256', $content),
                    'status' => $requiresEmbedding
                        ? AiHelperKnowledgeEntry::STATUS_PROCESSING
                        : ($activate
                            ? AiHelperKnowledgeEntry::STATUS_ACTIVE
                            : AiHelperKnowledgeEntry::STATUS_DISABLED),
                    'active' => $requiresEmbedding ? $previousUsable : $activate,
                    'processed_at' => $requiresEmbedding ? $locked->processed_at : now(),
                    'ingestion_completed_at' => $requiresEmbedding ? null : now(),
                    'extraction_mode' => $extractionMode,
                    'extraction_complete' => $requiresEmbedding && ! $previousUsable
                        ? false
                        : $extractionComplete,
                    'quality_status' => $metadata['quality_status'] ?? ($extractionComplete ? 'ready' : 'review_required'),
                    'retrieval_metadata' => $this->retrievalMetadata($locked, $chunks),
                    'extracted_characters' => Str::length($content),
                    'error' => null,
                    'embedding_status' => $requiresEmbedding ? 'processing' : 'pending',
                    'embedding_error' => null,
                    'ingestion_version' => $ingestionVersion,
                ] + $metadata;

                if (! $requiresEmbedding) {
                    $attributes += [
                        'embedding' => null,
                        'embedding_model' => null,
                        'embedding_hash' => null,
                        'embedded_at' => null,
                    ];
                }

                $locked->forceFill($attributes)->save();

                return [
                    'processed' => true,
                    'requires_embedding' => $requiresEmbedding,
                    'ingestion_version' => $ingestionVersion,
                    'ingestion_run_id' => $locked->ingestion_run_id,
                ];
            });

            if (($result['processed'] ?? false) && ($result['requires_embedding'] ?? false)) {
                try {
                    EmbedAiHelperKnowledgeEntry::dispatch(
                        $entry->id,
                        (int) $result['ingestion_version'],
                        $result['ingestion_run_id'],
                    )->afterCommit();
                } catch (Throwable $e) {
                    Log::warning('Ask AI knowledge semantic indexing failed; lexical index remains active', [
                        'knowledge_entry_id' => $entry->id,
                        'exception_class' => $e::class,
                    ]);
                    $this->markFailed(
                        $entry->id,
                        $result['ingestion_run_id'],
                        'Semantic indexing could not be queued: '.$e->getMessage(),
                    );
                }
            }

            return (bool) ($result['processed'] ?? false);
        } catch (Throwable $e) {
            Log::warning('Ask AI knowledge chunk persistence failed', [
                'knowledge_entry_id' => $entry->id,
                'exception_class' => $e::class,
            ]);
            throw $e;
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function chunkPages(
        array $pages,
        string $fallbackContent,
        string $fallbackMode,
        AiHelperKnowledgeEntry $entry,
    ): array {
        if ($pages === []) {
            $chunks = collect($this->markdownStructure->chunks(
                $fallbackContent,
                max(600, (int) config('ai_helper.knowledge_chunk_characters', 1500)),
            ));
            if ($entry->knowledge_type === AiHelperKnowledgeEntry::KNOWLEDGE_SYSTEM_GUIDE) {
                $chunks = $chunks->reject(fn (array $chunk) => collect($chunk['heading_path'] ?? [])
                    ->contains(fn (string $heading) => in_array(
                        $heading,
                        self::SYSTEM_GUIDE_MAINTAINER_HEADINGS,
                        true,
                    )));
            }

            return $chunks
                ->map(fn (array $chunk) => $chunk + ['extraction_mode' => $fallbackMode])
                ->values()
                ->all();
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
                    'heading_path' => [],
                    'content_type' => 'text',
                    'search_text' => $text,
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
                $entry->chunks()
                    ->where('ingestion_version', $entry->ingestion_version)
                    ->where('active', false)
                    ->delete();
                $entry->pages()
                    ->where('ingestion_version', $entry->ingestion_version)
                    ->delete();
                $entry->forceFill([
                    'status' => AiHelperKnowledgeEntry::STATUS_ACTIVE,
                    'active' => true,
                    'ingestion_completed_at' => now(),
                    'embedding_status' => is_array($entry->embedding) && $entry->embedding !== []
                        ? 'ready'
                        : 'failed',
                    'embedding_error' => Str::limit($message, 1000, ''),
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

    private function normalizeSourceContent(string $content, AiHelperKnowledgeEntry $entry): string
    {
        $isMarkdown = $entry->source_mime === 'text/markdown'
            || str_ends_with(Str::lower((string) $entry->source_filename), '.md')
            || str_starts_with((string) $entry->source_path, 'seed:');
        if (! $isMarkdown) {
            return $this->normalizeText($content);
        }

        $content = str_replace(["\r\n", "\r", "\0"], ["\n", "\n", ''], $content);
        $lines = array_map(static fn (string $line) => rtrim($line), explode("\n", $content));

        return trim(implode("\n", $lines));
    }

    private function estimateTokens(string $content): int
    {
        return max(1, (int) ceil(Str::length($content) / 4));
    }

    private function retrievalMetadata(AiHelperKnowledgeEntry $entry, array $chunks): array
    {
        $identity = trim($entry->title.' '.($entry->source_filename ?? ''));
        preg_match('/\bannex(?:e)?\s*0*(\d{1,3})\b/i', $identity, $annex);
        preg_match('/\brev(?:ision)?[.\s:-]*0*(\d{1,4})\b/i', $identity, $revision);
        preg_match_all('/\b(?:[A-Z]{2,}(?:-[A-Z0-9]+){2,}|PRO-\d{4,})\b/i', $identity, $codes);

        return [
            'annex_number' => isset($annex[1]) ? (int) $annex[1] : null,
            'revision' => isset($revision[1]) ? (ltrim((string) $revision[1], '0') ?: '0') : null,
            'document_codes' => collect($codes[0] ?? [])->map(fn ($code) => Str::upper($code))->unique()->values()->all(),
            'headings' => collect($chunks)->pluck('heading_path')->flatten()->filter()->unique()->values()->all(),
            'visual_reference_count' => collect($chunks)->where('content_type', 'visual_reference')->count(),
        ];
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
