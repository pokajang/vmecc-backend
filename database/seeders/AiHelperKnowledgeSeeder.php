<?php

namespace Database\Seeders;

use App\Models\AiHelperKnowledgeEntry;
use App\Services\AiHelperMarkdownKnowledgeParser;
use App\Services\AiHelperKnowledgeProcessingService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use RuntimeException;

class AiHelperKnowledgeSeeder extends Seeder
{
    public function run(): void
    {
        $processor = app(AiHelperKnowledgeProcessingService::class);
        $parser = app(AiHelperMarkdownKnowledgeParser::class);
        $files = glob(database_path('ai-helper-knowledge/*.md')) ?: [];
        sort($files);

        foreach ($files as $file) {
            $parsed = $parser->parseFile($file, true);
            $frontmatter = $parsed['frontmatter'];
            $content = $parsed['content'];
            $key = $parser->requiredString($frontmatter, 'key', $file);
            $title = $parser->requiredString($frontmatter, 'title', $file);
            $scopeType = $parser->requiredString($frontmatter, 'scope_type', $file);

            if (! preg_match('/^[a-z0-9][a-z0-9_-]*$/', $key)) {
                throw new RuntimeException("Invalid Ask AI knowledge key in {$file}.");
            }

            if (! in_array($scopeType, [
                AiHelperKnowledgeEntry::SCOPE_GLOBAL,
                AiHelperKnowledgeEntry::SCOPE_MODULE,
                AiHelperKnowledgeEntry::SCOPE_ROUTE,
            ], true)) {
                throw new RuntimeException("Invalid Ask AI knowledge scope_type in {$file}.");
            }

            $moduleKey = trim((string) ($frontmatter['module_key'] ?? ''));
            $routeKey = trim((string) ($frontmatter['route_key'] ?? ''));

            if ($scopeType === AiHelperKnowledgeEntry::SCOPE_GLOBAL) {
                $moduleKey = '';
                $routeKey = '';
            }

            if ($scopeType === AiHelperKnowledgeEntry::SCOPE_MODULE && $moduleKey === '') {
                throw new RuntimeException("Ask AI module knowledge requires module_key in {$file}.");
            }

            if ($scopeType === AiHelperKnowledgeEntry::SCOPE_ROUTE && $routeKey === '') {
                throw new RuntimeException("Ask AI route knowledge requires route_key in {$file}.");
            }

            $sourcePath = 'seed:'.$key;
            $tags = $parser->tags($frontmatter['tags'] ?? null, ['seed', 'seed:'.$key, $scopeType, $moduleKey, $routeKey]);
            $active = $parser->booleanValue($frontmatter['active'] ?? true);
            $version = max(1, (int) ($frontmatter['version'] ?? 1));
            $summary = trim((string) ($frontmatter['summary'] ?? '')) ?: null;

            $entry = AiHelperKnowledgeEntry::withTrashed()
                ->where('source_path', $sourcePath)
                ->orWhere(function ($query) use ($moduleKey, $routeKey, $title) {
                    $query
                        ->whereNull('uploaded_by')
                        ->where('title', $title)
                        ->where('module_key', $moduleKey !== '' ? $moduleKey : null)
                        ->where('route_key', $routeKey !== '' ? $routeKey : null);
                })
                ->first();

            $attributes = [
                'uploaded_by' => null,
                'module_key' => $moduleKey !== '' ? $moduleKey : null,
                'route_key' => $routeKey !== '' ? $routeKey : null,
                'title' => Str::limit($title, 255, ''),
                'content' => $content,
                'summary' => $summary,
                'source_filename' => basename($file),
                'source_mime' => 'text/markdown',
                'source_size' => filesize($file) ?: null,
                'source_path' => $sourcePath,
                'pdf_page_count' => null,
                'pdf_image_count' => null,
                'pdf_pages_with_images' => null,
                'pdf_readable_text_characters' => null,
                'pdf_readable_word_count' => null,
                'pdf_image_coverage_estimate' => null,
                'processing_warnings' => null,
                'scope_type' => $scopeType,
                'visibility' => AiHelperKnowledgeEntry::VISIBILITY_SHARED,
                'status' => $active ? AiHelperKnowledgeEntry::STATUS_ACTIVE : AiHelperKnowledgeEntry::STATUS_DISABLED,
                'review_status' => AiHelperKnowledgeEntry::REVIEW_APPROVED,
                'reviewed_by' => null,
                'reviewed_at' => now(),
                'review_note' => null,
                'active' => $active,
                'acknowledged_at' => null,
                'error' => null,
                'tags' => $tags,
                'version' => $version,
            ];

            if ($entry) {
                if ($entry->trashed()) {
                    $entry->restore();
                }

                $entry->forceFill($attributes)->save();
            } else {
                $entry = AiHelperKnowledgeEntry::create($attributes);
            }

            if ($active) {
                $processor->processTextEntry($entry, $content, $summary);
            } else {
                $entry->chunks()->delete();
                $entry->forceFill([
                    'content_hash' => hash('sha256', $content),
                    'processed_at' => now(),
                ])->save();
            }
        }
    }
}
