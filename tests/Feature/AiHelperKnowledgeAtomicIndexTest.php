<?php

namespace Tests\Feature;

use App\Jobs\EmbedAiHelperKnowledgeEntry;
use App\Models\AiHelperKnowledgeChunk;
use App\Models\AiHelperKnowledgeEntry;
use App\Services\AiHelperEmbeddingService;
use App\Services\AiHelperKnowledgeLifecycleService;
use App\Services\AiHelperKnowledgeProcessingService;
use App\Services\AiHelperRequestDeadline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class AiHelperKnowledgeAtomicIndexTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config([
            'ai_helper.embedding_enabled' => true,
            'ai_helper.api_key' => 'test-key',
            'ai_helper.embedding_model' => 'test-embedding',
            'ai_helper.embedding_dimensions' => 2,
            'ai_helper.embedding_batch_size' => 32,
        ]);
    }

    public function test_reindex_stages_a_new_version_without_deactivating_the_serving_chunks(): void
    {
        [$entry, $oldChunk] = $this->readyEntry();
        $runId = app(AiHelperKnowledgeLifecycleService::class)->beginIngestion($entry);

        $processed = app(AiHelperKnowledgeProcessingService::class)->processTextEntry(
            $entry,
            '# New guide'."\n\n".'Use the new approved workflow.',
            expectedRunId: $runId,
        );

        $this->assertTrue($processed);
        $this->assertTrue($oldChunk->fresh()->active);
        $this->assertDatabaseHas('ai_helper_knowledge_chunks', [
            'knowledge_entry_id' => $entry->id,
            'ingestion_version' => 2,
            'chunk_index' => 0,
            'active' => false,
        ]);
        $this->assertSame(AiHelperKnowledgeEntry::STATUS_PROCESSING, $entry->fresh()->status);
        $this->assertTrue($entry->fresh()->active);
    }

    public function test_failed_semantic_indexing_discards_only_the_stage_and_keeps_the_old_version_active(): void
    {
        [$entry, $oldChunk] = $this->readyEntry();
        $runId = app(AiHelperKnowledgeLifecycleService::class)->beginIngestion($entry);
        app(AiHelperKnowledgeProcessingService::class)->processTextEntry(
            $entry,
            'Replacement guidance that cannot be embedded.',
            expectedRunId: $runId,
        );

        (new EmbedAiHelperKnowledgeEntry($entry->id, 2, $runId))
            ->failed(new RuntimeException('provider unavailable'));

        $entry->refresh();
        $this->assertSame(AiHelperKnowledgeEntry::STATUS_ACTIVE, $entry->status);
        $this->assertTrue($entry->active);
        $this->assertTrue($oldChunk->fresh()->active);
        $this->assertFalse($entry->chunks()->where('ingestion_version', 2)->exists());
        $this->assertStringContainsString('previous index remains active', (string) $entry->error);
    }

    public function test_provider_becoming_unavailable_never_promotes_unembedded_staged_chunks(): void
    {
        [$entry, $oldChunk] = $this->readyEntry();
        $runId = app(AiHelperKnowledgeLifecycleService::class)->beginIngestion($entry);
        app(AiHelperKnowledgeProcessingService::class)->processTextEntry(
            $entry,
            'Replacement that still requires semantic indexing.',
            expectedRunId: $runId,
        );
        config(['ai_helper.api_key' => '']);

        (new EmbedAiHelperKnowledgeEntry($entry->id, 2, $runId))->handle(
            app(AiHelperEmbeddingService::class),
            app(AiHelperKnowledgeProcessingService::class),
        );

        $entry->refresh();
        $this->assertSame(AiHelperKnowledgeEntry::STATUS_ACTIVE, $entry->status);
        $this->assertTrue($oldChunk->fresh()->active);
        $this->assertFalse($entry->chunks()->where('ingestion_version', 2)->exists());
        $this->assertStringContainsString('staged index was not activated', (string) $entry->error);
    }

    public function test_successful_embeddings_atomically_promote_the_staged_version(): void
    {
        [$entry, $oldChunk] = $this->readyEntry();
        $runId = app(AiHelperKnowledgeLifecycleService::class)->beginIngestion($entry);
        app(AiHelperKnowledgeProcessingService::class)->processTextEntry(
            $entry,
            'Replacement guidance ready for production.',
            expectedRunId: $runId,
        );

        $promoted = $this->successfulEmbeddingService()->embedEntry(
            $entry->fresh(),
            2,
            $runId,
        );

        $entry->refresh();
        $this->assertTrue($promoted);
        $this->assertSame(AiHelperKnowledgeEntry::STATUS_ACTIVE, $entry->status);
        $this->assertSame('ready', $entry->embedding_status);
        $this->assertTrue($entry->extraction_complete);
        $this->assertNull($oldChunk->fresh());
        $this->assertFalse($entry->chunks()->where('ingestion_version', '!=', 2)->exists());
        $this->assertTrue($entry->chunks()->where('ingestion_version', 2)->get()->every(
            fn (AiHelperKnowledgeChunk $chunk) => $chunk->active
                && is_array($chunk->embedding)
                && count($chunk->embedding) === 2,
        ));
    }

    public function test_stale_embedding_job_cannot_promote_or_alter_a_newer_ingestion_run(): void
    {
        [$entry, $oldChunk] = $this->readyEntry();
        $firstRunId = app(AiHelperKnowledgeLifecycleService::class)->beginIngestion($entry);
        app(AiHelperKnowledgeProcessingService::class)->processTextEntry(
            $entry,
            'First staged replacement.',
            expectedRunId: $firstRunId,
        );

        $secondRunId = app(AiHelperKnowledgeLifecycleService::class)->beginIngestion($entry->fresh());
        (new EmbedAiHelperKnowledgeEntry($entry->id, 2, $firstRunId))
            ->handle(
                $this->successfulEmbeddingService(),
                app(AiHelperKnowledgeProcessingService::class),
            );

        $entry->refresh();
        $this->assertSame(3, $entry->ingestion_version);
        $this->assertSame($secondRunId, $entry->ingestion_run_id);
        $this->assertSame(AiHelperKnowledgeEntry::STATUS_PROCESSING, $entry->status);
        $this->assertTrue($oldChunk->fresh()->active);
        $this->assertFalse($entry->chunks()->where('ingestion_version', 2)->firstOrFail()->active);

        $this->assertTrue(app(AiHelperKnowledgeProcessingService::class)->processTextEntry(
            $entry,
            'Newest staged replacement.',
            expectedRunId: $secondRunId,
        ));
        $this->assertFalse($entry->chunks()->where('ingestion_version', 2)->exists());
        $this->assertFalse($entry->chunks()->where('ingestion_version', 3)->firstOrFail()->active);
        $this->assertTrue($oldChunk->fresh()->active);
    }

    public function test_first_ingestion_becomes_active_after_its_staged_embeddings_succeed(): void
    {
        $runId = (string) Str::uuid();
        $entry = AiHelperKnowledgeEntry::create([
            'title' => 'First ingestion',
            'content' => 'First ingestion content.',
            'source_filename' => 'first.md',
            'source_mime' => 'text/markdown',
            'source_path' => 'seed:test:first',
            'status' => AiHelperKnowledgeEntry::STATUS_PROCESSING,
            'review_status' => AiHelperKnowledgeEntry::REVIEW_APPROVED,
            'active' => false,
            'ingestion_run_id' => $runId,
            'ingestion_version' => 1,
        ]);

        app(AiHelperKnowledgeProcessingService::class)->processTextEntry(
            $entry,
            'First ingestion content.',
            expectedRunId: $runId,
        );
        $this->assertFalse($entry->fresh()->active);
        $this->assertFalse($entry->chunks()->firstOrFail()->active);

        $this->assertTrue($this->successfulEmbeddingService()->embedEntry($entry->fresh(), 1, $runId));
        $this->assertTrue($entry->fresh()->active);
        $this->assertTrue($entry->chunks()->firstOrFail()->active);
    }

    public function test_entity_index_is_staged_and_promoted_with_the_same_atomic_version_as_chunks(): void
    {
        $runId = (string) Str::uuid();
        $entry = AiHelperKnowledgeEntry::create([
            'title' => 'Role ingestion',
            'content' => 'Role content.',
            'source_filename' => 'role.md',
            'source_mime' => 'text/markdown',
            'source_path' => 'seed:test:role-ingestion',
            'status' => AiHelperKnowledgeEntry::STATUS_PROCESSING,
            'review_status' => AiHelperKnowledgeEntry::REVIEW_APPROVED,
            'active' => false,
            'ingestion_run_id' => $runId,
            'ingestion_version' => 1,
        ]);
        app(AiHelperKnowledgeProcessingService::class)->processTextEntry(
            $entry,
            "# Roles\n\n## EMERGENCY RESPONSE TEAM MEMBER (ERTM)\n\nAssist the OSC.",
            expectedRunId: $runId,
        );

        $staged = $entry->entities()->where('ingestion_version', 1)->get();
        $this->assertNotEmpty($staged);
        $this->assertTrue($staged->every(fn ($entity) => ! $entity->active));

        $this->assertTrue($this->successfulEmbeddingService()->embedEntry($entry->fresh(), 1, $runId));

        $active = $entry->entities()->where('ingestion_version', 1)->get();
        $this->assertNotEmpty($active);
        $this->assertTrue($active->every(fn ($entity) => $entity->active));
        $this->assertTrue($active->flatMap->aliases->contains('normalized_alias', 'ertm'));
    }

    public function test_embedding_disabled_ingestion_replaces_the_lexical_index_immediately(): void
    {
        config(['ai_helper.embedding_enabled' => false]);
        [$entry, $oldChunk] = $this->readyEntry();
        $runId = app(AiHelperKnowledgeLifecycleService::class)->beginIngestion($entry);

        app(AiHelperKnowledgeProcessingService::class)->processTextEntry(
            $entry,
            'Lexical replacement content.',
            expectedRunId: $runId,
        );

        $entry->refresh();
        $this->assertSame(AiHelperKnowledgeEntry::STATUS_ACTIVE, $entry->status);
        $this->assertNull($oldChunk->fresh());
        $this->assertTrue($entry->chunks()->where('ingestion_version', 2)->firstOrFail()->active);
    }

    public function test_stuck_reconciler_requeues_a_staged_first_ingestion_by_version(): void
    {
        $runId = (string) Str::uuid();
        $entry = AiHelperKnowledgeEntry::create([
            'title' => 'Staged first ingestion',
            'content' => 'Staged first ingestion content.',
            'source_filename' => 'staged-first.md',
            'source_mime' => 'text/markdown',
            'source_path' => 'seed:test:staged-first',
            'status' => AiHelperKnowledgeEntry::STATUS_PROCESSING,
            'review_status' => AiHelperKnowledgeEntry::REVIEW_APPROVED,
            'active' => false,
            'ingestion_run_id' => $runId,
            'ingestion_version' => 1,
        ]);
        app(AiHelperKnowledgeProcessingService::class)->processTextEntry(
            $entry,
            'Staged first ingestion content.',
            expectedRunId: $runId,
        );
        Queue::fake();
        DB::table('ai_helper_knowledge_entries')->where('id', $entry->id)->update([
            'updated_at' => now()->subHour(),
        ]);

        $this->artisan('ai-helper:reconcile-stuck-embeddings --minutes=20 --retry')
            ->assertSuccessful();

        $entry->refresh();
        $this->assertSame(AiHelperKnowledgeEntry::STATUS_PROCESSING, $entry->status);
        $this->assertSame('pending', $entry->embedding_status);
        $this->assertFalse($entry->active);
        Queue::assertPushed(EmbedAiHelperKnowledgeEntry::class, 1);
    }

    public function test_full_reindex_does_not_reactivate_disabled_knowledge(): void
    {
        $disabled = AiHelperKnowledgeEntry::create([
            'title' => 'Retired guide',
            'content' => 'This guide has been intentionally retired.',
            'source_filename' => 'retired.md',
            'source_mime' => 'text/markdown',
            'source_path' => 'seed:test:retired',
            'status' => AiHelperKnowledgeEntry::STATUS_DISABLED,
            'review_status' => AiHelperKnowledgeEntry::REVIEW_APPROVED,
            'active' => false,
            'ingestion_version' => 1,
        ]);

        $this->artisan('ai-helper:reindex-knowledge --semantic')
            ->expectsOutput('Queued 0 knowledge documents.')
            ->assertSuccessful();

        $disabled->refresh();
        $this->assertSame(AiHelperKnowledgeEntry::STATUS_DISABLED, $disabled->status);
        $this->assertFalse($disabled->active);
        Queue::assertNothingPushed();
    }

    /** @return array{AiHelperKnowledgeEntry, AiHelperKnowledgeChunk} */
    private function readyEntry(): array
    {
        $entry = AiHelperKnowledgeEntry::create([
            'title' => 'Existing guide',
            'content' => 'Existing approved guidance.',
            'source_filename' => 'existing.md',
            'source_mime' => 'text/markdown',
            'source_path' => 'seed:test:existing',
            'status' => AiHelperKnowledgeEntry::STATUS_ACTIVE,
            'review_status' => AiHelperKnowledgeEntry::REVIEW_APPROVED,
            'active' => true,
            'extraction_complete' => true,
            'processed_at' => now(),
            'ingestion_version' => 1,
            'embedding' => [0.1, 0.2],
            'embedding_model' => 'test-embedding',
            'embedding_hash' => 'old-routing-hash',
            'embedding_status' => 'ready',
            'embedded_at' => now(),
        ]);
        $chunk = AiHelperKnowledgeChunk::create([
            'knowledge_entry_id' => $entry->id,
            'chunk_index' => 0,
            'content' => 'Existing approved guidance.',
            'search_text' => 'Existing approved guidance.',
            'content_hash' => hash('sha256', 'Existing approved guidance.'),
            'token_estimate' => 10,
            'active' => true,
            'ingestion_version' => 1,
            'embedding' => [0.1, 0.2],
            'embedding_model' => 'test-embedding',
            'embedding_hash' => 'old-chunk-hash',
            'embedded_at' => now(),
        ]);

        return [$entry, $chunk];
    }

    private function successfulEmbeddingService(): AiHelperEmbeddingService
    {
        return new class extends AiHelperEmbeddingService
        {
            public function isAvailable(): bool
            {
                return true;
            }

            protected function embedTexts(array $texts, ?AiHelperRequestDeadline $deadline = null): array
            {
                return collect($texts)
                    ->values()
                    ->map(fn (string $text, int $index) => [0.25 + $index, 0.75 + mb_strlen($text)])
                    ->all();
            }
        };
    }
}
