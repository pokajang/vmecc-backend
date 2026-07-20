<?php

namespace Database\Seeders;

use App\Models\AiHelperKnowledgeEntry;
use App\Models\AuditLog;
use App\Services\AiHelperKnowledgeLifecycleService;
use App\Services\AiHelperKnowledgeProcessingService;
use App\Services\AiHelperMarkdownKnowledgeParser;
use App\Services\AiHelperSystemGuideCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class AiHelperSystemGuideSeeder extends Seeder
{
    public function run(): void
    {
        $processor = app(AiHelperKnowledgeProcessingService::class);
        $lifecycle = app(AiHelperKnowledgeLifecycleService::class);
        $parser = app(AiHelperMarkdownKnowledgeParser::class);
        $catalog = app(AiHelperSystemGuideCatalog::class);
        $registryErrors = $catalog->validateRegistry();
        if ($registryErrors !== []) {
            throw new RuntimeException('System-guide catalog is invalid: '.implode('; ', $registryErrors));
        }
        $files = glob(database_path('ai-helper-system-guides/*.md')) ?: [];
        sort($files);

        $parsedGuides = [];
        foreach ($files as $file) {
            $parsed = $parser->parseFile($file, true);
            $metadata = $catalog->validate($parsed['frontmatter'], $parsed['content'], $file);
            if (isset($parsedGuides[$metadata['key']])) {
                throw new RuntimeException("Duplicate system-guide key: {$metadata['key']}");
            }
            $parsedGuides[$metadata['key']] = compact('file', 'parsed', 'metadata');
        }

        $missing = array_values(array_diff($catalog->keys(), array_keys($parsedGuides)));
        $unknown = array_values(array_diff(array_keys($parsedGuides), $catalog->keys()));
        if (count($files) !== $catalog->expectedCount() || $missing !== [] || $unknown !== []) {
            throw new RuntimeException('System-guide files do not match the catalog. Missing: '
                .implode(', ', $missing).'. Unknown: '.implode(', ', $unknown).'.');
        }

        $notFinal = collect($parsedGuides)
            ->filter(fn (array $guide) => $guide['metadata']['version'] !== AiHelperSystemGuideCatalog::FINAL_VERSION
                || $guide['metadata']['release_status'] !== AiHelperSystemGuideCatalog::RELEASE_FINAL
                || $guide['metadata']['active'] !== true)
            ->keys()
            ->values()
            ->all();
        if ($notFinal !== []) {
            throw new RuntimeException(
                'System-guide seeding requires the complete final and active version '
                .AiHelperSystemGuideCatalog::FINAL_VERSION.' corpus with '
                .$catalog->expectedCount().' validated guides. Non-final guides: '
                .implode(', ', $notFinal).'.'
            );
        }

        DB::beginTransaction();
        try {
            foreach ($parsedGuides as $key => ['file' => $file, 'parsed' => $parsed, 'metadata' => $metadata]) {
                $sourcePath = 'seed:system-guide:'.$key;
                $content = $parsed['content'];
                $entry = AiHelperKnowledgeEntry::withTrashed()->firstOrNew(['source_path' => $sourcePath]);
                $before = $entry->exists ? [
                    'active' => (bool) $entry->active,
                    'version' => (int) $entry->version,
                    'content_hash' => $entry->content_hash,
                ] : null;
                if ($entry->trashed()) {
                    $entry->restore();
                }

                $expectedStatus = $metadata['active']
                    ? AiHelperKnowledgeEntry::STATUS_ACTIVE
                    : AiHelperKnowledgeEntry::STATUS_DISABLED;
                $expectedReviewStatus = AiHelperKnowledgeEntry::REVIEW_APPROVED;
                $chunksMatchActivation = $entry->exists
                    && $entry->chunks()->exists()
                    && ! $entry->chunks()->where('active', ! $metadata['active'])->exists();
                $embeddingCurrent = ! $metadata['active']
                    || ! (bool) config('ai_helper.embedding_enabled', true)
                    || ($entry->embedding_status === 'ready'
                        && $entry->embedding_model === (string) config('ai_helper.embedding_model'));
                $unchanged = $entry->exists
                    && hash_equals((string) $entry->content_hash, hash('sha256', $content))
                    && $catalog->matchesStoredMetadata($entry)
                    && (int) $entry->version === $metadata['version']
                    && (bool) $entry->active === $metadata['active']
                    && $entry->status === $expectedStatus
                    && $entry->review_status === $expectedReviewStatus
                    && $entry->review_due_at?->toDateString() === $metadata['review_due_on']->toDateString()
                    && $chunksMatchActivation
                    && $embeddingCurrent;
                if ($unchanged) {
                    $this->command?->info(sprintf(
                        '%s: unchanged (v%d, %d chunks)',
                        $key,
                        $metadata['version'],
                        $entry->chunks()->count(),
                    ));

                    continue;
                }

                $entry->forceFill([
                    'uploaded_by' => null,
                    'source_document_id' => null,
                    'knowledge_type' => AiHelperKnowledgeEntry::KNOWLEDGE_SYSTEM_GUIDE,
                    'required_permissions' => $metadata['required_permissions'],
                    'permission_match' => $metadata['permission_match'],
                    'allowed_roles' => $metadata['allowed_roles'],
                    'module_gate' => $metadata['module_gate'],
                    'guide_owner' => $metadata['owner'],
                    'review_due_at' => $metadata['review_due_on'],
                    'module_key' => $metadata['module_key'],
                    'route_key' => $metadata['route_key'],
                    'title' => Str::limit($metadata['title'], 255, ''),
                    'content' => $content,
                    'source_filename' => basename($file),
                    'source_mime' => 'text/markdown',
                    'source_size' => filesize($file) ?: null,
                    'scope_type' => $metadata['scope_type'],
                    'visibility' => AiHelperKnowledgeEntry::VISIBILITY_SHARED,
                    'status' => AiHelperKnowledgeEntry::STATUS_PROCESSING,
                    'review_status' => AiHelperKnowledgeEntry::REVIEW_APPROVED,
                    'reviewed_by' => null,
                    'reviewed_at' => $metadata['reviewed_on'],
                    'review_note' => null,
                    'active' => $metadata['active'],
                    'error' => null,
                    'tags' => array_values(array_unique([
                        ...$metadata['tags'],
                        'system-guide',
                        'system-guide:'.$key,
                    ])),
                    'version' => $metadata['version'],
                ])->save();

                $runId = $lifecycle->beginIngestion($entry);
                $processor->processTextEntry(
                    $entry,
                    $content,
                    expectedRunId: $runId,
                    activate: $metadata['active'],
                );
                $fresh = $entry->fresh();
                $this->auditChange($fresh, $before);
                $this->command?->info(sprintf(
                    '%s: %s (v%d, %d chunks)',
                    $key,
                    $fresh?->status ?? 'unknown',
                    $metadata['version'],
                    $fresh?->chunks()->count() ?? 0,
                ));
            }

            $retired = AiHelperKnowledgeEntry::query()
                ->where('knowledge_type', AiHelperKnowledgeEntry::KNOWLEDGE_SYSTEM_GUIDE)
                ->where(function ($query) {
                    $query->where('source_path', 'not like', 'seed:system-guide:%')
                        ->orWhereNotIn('source_path', collect(array_keys($this->catalogGuides()))
                            ->map(fn (string $key) => 'seed:system-guide:'.$key));
                })
                ->where(fn ($query) => $query->where('active', true)
                    ->orWhere('status', '!=', AiHelperKnowledgeEntry::STATUS_DISABLED))
                ->get();
            foreach ($retired as $entry) {
                $entry->forceFill([
                    'active' => false,
                    'status' => AiHelperKnowledgeEntry::STATUS_DISABLED,
                ])->save();
                $entry->chunks()->update(['active' => false]);
                $this->auditChange($entry, ['active' => true, 'version' => $entry->version, 'content_hash' => $entry->content_hash]);
            }
            DB::commit();
        } catch (Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }
    }

    private function catalogGuides(): array
    {
        return array_fill_keys(app(AiHelperSystemGuideCatalog::class)->keys(), true);
    }

    private function auditChange(?AiHelperKnowledgeEntry $entry, ?array $before): void
    {
        if (! $entry || ! Schema::hasTable('audit_logs')) {
            return;
        }
        $after = [
            'active' => (bool) $entry->active,
            'version' => (int) $entry->version,
            'content_hash' => $entry->content_hash,
        ];
        if ($before === $after) {
            return;
        }

        $action = match (true) {
            $before === null => 'ai_system_guide_seeded',
            $before['active'] === false && $after['active'] === true => 'ai_system_guide_activated',
            $before['active'] === true && $after['active'] === false => 'ai_system_guide_deactivated',
            default => 'ai_system_guide_updated',
        };

        AuditLog::create([
            'actor_user_id' => null,
            'action' => $action,
            'subject_type' => AiHelperKnowledgeEntry::class,
            'subject_id' => $entry->id,
            'metadata' => [
                'guide_key' => Str::after((string) $entry->source_path, 'seed:system-guide:'),
                'before' => $before,
                'after' => $after,
            ],
        ]);
    }
}
