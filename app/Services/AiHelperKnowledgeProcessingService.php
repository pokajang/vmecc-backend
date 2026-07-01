<?php

namespace App\Services;

use App\Models\AiHelperKnowledgeChunk;
use App\Models\AiHelperKnowledgeEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class AiHelperKnowledgeProcessingService
{
    public function __construct(private readonly AiHelperPdfKnowledgeExtractor $pdfExtractor)
    {
    }

    public function process(int $entryId): void
    {
        $entry = AiHelperKnowledgeEntry::query()->find($entryId);
        if (! $entry || ! $entry->source_path) {
            return;
        }

        try {
            if (str_starts_with($entry->source_path, 'seed:') || $entry->source_mime === 'text/markdown') {
                if (trim((string) $entry->content) === '') {
                    $this->markFailed($entry, 'Could not process this knowledge source.');
                    return;
                }

                if (! $this->processTextEntry($entry, (string) $entry->content, $entry->summary)) {
                    $this->markFailed($entry, 'Could not prepare readable guidance from this knowledge source.');
                }

                return;
            }

            $extraction = $this->pdfExtractor->extract(
                Storage::disk('local')->path($entry->source_path),
                (int) config('ai_helper.knowledge_extract_max_characters', 30000)
            );
            $content = (string) ($extraction['text'] ?? '');

            if (trim($content) === '') {
                $this->markFailed(
                    $entry,
                    'Could not read text from this PDF. Upload a text-based PDF.',
                    $this->pdfMetadata($extraction),
                );
                return;
            }

            if ($this->isImageHeavyWithLowText($extraction)) {
                $this->markFailed(
                    $entry,
                    'This PDF appears to be mostly image-based. Ask AI can only learn readable text, so upload a text-based PDF instead.',
                    $this->pdfMetadata($extraction),
                );
                return;
            }

            if (! $this->processTextEntry($entry, $content, null, $this->pdfMetadata($extraction))) {
                $this->markFailed(
                    $entry,
                    'Could not prepare readable guidance from this PDF.',
                    $this->pdfMetadata($extraction),
                );
                return;
            }
        } catch (Throwable $e) {
            Log::warning('Ask AI knowledge processing failed', [
                'knowledge_entry_id' => $entry->id,
                'error' => $e->getMessage(),
            ]);
            $this->markFailed($entry, 'Could not process this PDF. Upload a text-based PDF and try again.');
        }
    }

    public function buildSummary(string $content): string
    {
        $text = trim((string) preg_replace('/\s+/', ' ', $content));
        if ($text === '') {
            return '';
        }

        $sentences = preg_split('/(?<=[.!?])\s+/', $text, 4) ?: [];
        $summary = trim(implode(' ', array_slice(array_filter($sentences), 0, 2)));

        return Str::limit($summary !== '' ? $summary : $text, 320, '');
    }

    public function processTextEntry(
        AiHelperKnowledgeEntry $entry,
        string $content,
        ?string $summary = null,
        array $metadata = [],
    ): bool
    {
        $chunks = $this->splitIntoChunks($content);
        if ($chunks === []) {
            return false;
        }

        DB::transaction(function () use ($entry, $content, $summary, $chunks, $metadata) {
            $entry->refresh();
            $entry->chunks()->delete();

            foreach ($chunks as $index => $chunk) {
                AiHelperKnowledgeChunk::create([
                    'knowledge_entry_id' => $entry->id,
                    'chunk_index' => $index,
                    'content' => $chunk,
                    'content_hash' => hash('sha256', $chunk),
                    'token_estimate' => $this->estimateTokens($chunk),
                    'module_key' => $entry->module_key,
                    'route_key' => $entry->route_key,
                    'active' => true,
                ]);
            }

            $entry->forceFill([
                'content' => $content,
                'summary' => $summary !== null && trim($summary) !== ''
                    ? Str::limit(trim((string) preg_replace('/\s+/', ' ', $summary)), 320, '')
                    : $this->buildSummary($content),
                'content_hash' => hash('sha256', $content),
                'status' => $entry->review_status === AiHelperKnowledgeEntry::REVIEW_REJECTED
                    ? AiHelperKnowledgeEntry::STATUS_DISABLED
                    : AiHelperKnowledgeEntry::STATUS_ACTIVE,
                'active' => $entry->review_status === AiHelperKnowledgeEntry::REVIEW_APPROVED,
                'processed_at' => now(),
                'error' => null,
            ] + $metadata)->save();
        });

        return true;
    }

    /**
     * @return array<int, string>
     */
    public function splitIntoChunks(string $content): array
    {
        $text = trim((string) preg_replace('/\s+/', ' ', $content));
        if ($text === '') {
            return [];
        }

        $targetSize = max(600, (int) config('ai_helper.knowledge_chunk_characters', 1000));
        $maxChunks = max(1, (int) config('ai_helper.knowledge_max_chunks_per_entry', 40));
        $chunks = [];
        $offset = 0;
        $length = Str::length($text);

        while ($offset < $length && count($chunks) < $maxChunks) {
            $slice = Str::substr($text, $offset, $targetSize);
            $nextOffset = $offset + Str::length($slice);

            if ($nextOffset < $length) {
                $breaks = array_filter([
                    strrpos($slice, '. '),
                    strrpos($slice, '; '),
                    strrpos($slice, "\n"),
                ], fn ($position) => $position !== false);
                $lastBreak = $breaks ? max($breaks) : false;
                if ($lastBreak !== false && $lastBreak > (int) ($targetSize * 0.55)) {
                    $slice = Str::substr($slice, 0, $lastBreak + 1);
                    $nextOffset = $offset + Str::length($slice);
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

    private function estimateTokens(string $content): int
    {
        return max(1, (int) ceil(Str::length($content) / 4));
    }

    private function isImageHeavyWithLowText(array $extraction): bool
    {
        return (int) ($extraction['image_coverage_estimate'] ?? 0) >= 90
            && (
                (int) ($extraction['readable_text_characters'] ?? 0) < 800
                || (int) ($extraction['readable_word_count'] ?? 0) < 120
            );
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
        ];
    }

    private function processingWarnings(mixed $warnings): ?array
    {
        if (! is_array($warnings)) {
            return null;
        }

        $clean = collect($warnings)
            ->filter(fn ($warning) => is_string($warning) && trim($warning) !== '')
            ->map(fn (string $warning) => Str::limit(trim($warning), 500, ''))
            ->unique()
            ->values()
            ->all();

        return $clean === [] ? null : $clean;
    }

    private function markFailed(AiHelperKnowledgeEntry $entry, string $message, array $metadata = []): void
    {
        $entry->chunks()->delete();
        $entry->forceFill([
            'status' => AiHelperKnowledgeEntry::STATUS_FAILED,
            'active' => false,
            'content' => '',
            'summary' => null,
            'processed_at' => now(),
            'error' => $message,
        ] + $metadata)->save();
    }
}
