<?php

namespace App\Services;

use App\Models\AiHelperKnowledgeEntry;
use Illuminate\Support\Str;
use RuntimeException;

final class AiHelperReferenceCorpusCatalog
{
    /**
     * Markdown is the canonical AI source. A same-stem PDF is an optional
     * reader-facing attachment and never participates in indexing.
     *
     * @return array<int, array<string, mixed>>
     */
    public function sources(): array
    {
        $root = $this->rootPath();
        $markdownDirectory = $root.DIRECTORY_SEPARATOR.'md';
        if (! is_dir($markdownDirectory)) {
            throw new RuntimeException("AI reference Markdown directory not found: {$markdownDirectory}");
        }

        $markdownFiles = glob($markdownDirectory.DIRECTORY_SEPARATOR.'*.md') ?: [];
        sort($markdownFiles, SORT_NATURAL | SORT_FLAG_CASE);

        $sources = collect($markdownFiles)
            ->map(fn (string $markdownFile): array => $this->source($markdownFile, $root))
            ->values();
        $duplicateIdentities = $sources
            ->groupBy('source_path')
            ->filter(fn ($matches) => $matches->count() > 1)
            ->keys()
            ->values();
        if ($duplicateIdentities->isNotEmpty()) {
            throw new RuntimeException(
                'Duplicate normalized Markdown identities: '.$duplicateIdentities->join(', '),
            );
        }

        return $sources->all();
    }

    /** @return array<int, string> */
    public function orphanPdfFiles(): array
    {
        $root = $this->rootPath();
        $pdfDirectory = $root.DIRECTORY_SEPARATOR.'pdf';
        if (! is_dir($pdfDirectory)) {
            return [];
        }

        $markdownDirectory = $root.DIRECTORY_SEPARATOR.'md';
        $orphans = collect(glob($pdfDirectory.DIRECTORY_SEPARATOR.'*.pdf') ?: [])
            ->reject(fn (string $pdfFile): bool => is_file(
                $markdownDirectory.DIRECTORY_SEPARATOR.pathinfo($pdfFile, PATHINFO_FILENAME).'.md',
            ))
            ->map(fn (string $pdfFile): string => basename($pdfFile))
            ->sort(SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();

        return $orphans;
    }

    public function expectedCount(): int
    {
        return count($this->sources());
    }

    public function rootPath(): string
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

    /** @return array<string, mixed> */
    private function source(string $markdownFile, string $root): array
    {
        $markdownFile = $this->validatedFile($markdownFile, $root.DIRECTORY_SEPARATOR.'md');
        $filename = basename($markdownFile);
        $stem = pathinfo($filename, PATHINFO_FILENAME);
        $relativePath = 'md/'.$filename;
        $pdfCandidate = $root.DIRECTORY_SEPARATOR.'pdf'.DIRECTORY_SEPARATOR.$stem.'.pdf';
        $pdfFile = is_file($pdfCandidate)
            ? $this->validatedFile($pdfCandidate, $root.DIRECTORY_SEPARATOR.'pdf')
            : null;
        $metadata = $this->metadataFor($markdownFile);

        return [
            'markdown_path' => $markdownFile,
            'pdf_path' => $pdfFile,
            'relative_markdown_path' => $relativePath,
            'source_path' => 'seed:ai_knowledge:'.sha1(Str::lower($relativePath)),
            // The previous seeder keyed entries by the expected PDF filename.
            // Keep this deterministic alias even when the PDF has been removed.
            'legacy_source_path' => 'seed:ai_knowledge:'.sha1($stem.'.pdf'),
            'title' => $metadata['title'] ?? $stem,
            'visibility' => $metadata['visibility'] ?? AiHelperKnowledgeEntry::VISIBILITY_SHARED,
            'scope_type' => $metadata['scope'] ?? AiHelperKnowledgeEntry::SCOPE_GLOBAL,
            'review_status' => $metadata['review_status'] ?? AiHelperKnowledgeEntry::REVIEW_APPROVED,
            'tags' => $metadata['tags'] ?? ['seed', 'markdown', 'ai_knowledge', 'emergency-response'],
        ];
    }

    private function validatedFile(string $path, string $expectedDirectory): string
    {
        $resolvedPath = realpath($path);
        $resolvedDirectory = realpath($expectedDirectory);
        if ($resolvedPath === false || $resolvedDirectory === false || ! is_file($resolvedPath)) {
            throw new RuntimeException("AI reference source is not a readable file: {$path}");
        }

        $prefix = rtrim($resolvedDirectory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        if (! str_starts_with(Str::lower($resolvedPath), Str::lower($prefix))) {
            throw new RuntimeException("AI reference source resolves outside its corpus directory: {$path}");
        }

        return $resolvedPath;
    }

    /** @return array<string, mixed> */
    private function metadataFor(string $markdownFile): array
    {
        $metadataFile = dirname($markdownFile).DIRECTORY_SEPARATOR
            .pathinfo($markdownFile, PATHINFO_FILENAME).'.json';
        if (! is_file($metadataFile)) {
            return [];
        }
        $metadataFile = $this->validatedFile($metadataFile, dirname($markdownFile));

        $decoded = json_decode((string) file_get_contents($metadataFile), true);
        if (! is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Invalid reference metadata JSON: '.basename($metadataFile));
        }

        $unknown = array_diff(array_keys($decoded), ['title', 'visibility', 'scope', 'review_status', 'tags']);
        if ($unknown !== []) {
            throw new RuntimeException(
                'Unknown reference metadata fields in '.basename($metadataFile).': '.implode(', ', $unknown),
            );
        }

        if (isset($decoded['title']) && (! is_string($decoded['title']) || trim($decoded['title']) === '')) {
            throw new RuntimeException('Reference metadata title must be a non-empty string.');
        }
        if (isset($decoded['visibility'])
            && ! in_array($decoded['visibility'], AiHelperKnowledgeEntry::VISIBILITIES, true)) {
            throw new RuntimeException('Reference metadata visibility is invalid.');
        }
        if (isset($decoded['scope'])
            && ! in_array($decoded['scope'], [
                AiHelperKnowledgeEntry::SCOPE_GLOBAL,
                AiHelperKnowledgeEntry::SCOPE_MODULE,
                AiHelperKnowledgeEntry::SCOPE_ROUTE,
            ], true)) {
            throw new RuntimeException('Reference metadata scope is invalid.');
        }
        if (isset($decoded['review_status'])
            && ! in_array($decoded['review_status'], AiHelperKnowledgeEntry::REVIEW_STATUSES, true)) {
            throw new RuntimeException('Reference metadata review status is invalid.');
        }
        if (isset($decoded['tags'])
            && (! is_array($decoded['tags'])
                || collect($decoded['tags'])->contains(fn ($tag) => ! is_string($tag) || trim($tag) === ''))) {
            throw new RuntimeException('Reference metadata tags must be non-empty strings.');
        }

        return $decoded;
    }
}
