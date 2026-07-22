<?php

namespace Tests\Feature;

use App\Models\AiHelperKnowledgeEntry;
use App\Services\AiHelperSystemGuideCatalog;
use Database\Seeders\AiHelperSystemGuideSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiHelperSystemGuideSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_creates_the_complete_final_corpus_idempotently_without_touching_references(): void
    {
        config(['ai_helper.embedding_enabled' => false]);
        $reference = AiHelperKnowledgeEntry::create([
            'knowledge_type' => AiHelperKnowledgeEntry::KNOWLEDGE_REFERENCE_DOCUMENT,
            'title' => 'Reference',
            'content' => 'Reference content.',
            'source_mime' => 'text/markdown',
            'source_path' => 'seed:ai_knowledge:reference',
            'visibility' => AiHelperKnowledgeEntry::VISIBILITY_SHARED,
            'status' => AiHelperKnowledgeEntry::STATUS_ACTIVE,
            'review_status' => AiHelperKnowledgeEntry::REVIEW_APPROVED,
            'active' => true,
        ]);

        $this->seed(AiHelperSystemGuideSeeder::class);

        $guides = AiHelperKnowledgeEntry::query()
            ->where('knowledge_type', AiHelperKnowledgeEntry::KNOWLEDGE_SYSTEM_GUIDE)
            ->orderBy('source_path')
            ->get();
        $this->assertCount(app(AiHelperSystemGuideCatalog::class)->expectedCount(), $guides);
        $this->assertTrue($guides->every(fn (AiHelperKnowledgeEntry $entry) => $entry->active
            && $entry->status === AiHelperKnowledgeEntry::STATUS_ACTIVE
            && $entry->review_status === AiHelperKnowledgeEntry::REVIEW_APPROVED
            && $entry->version === 3
            && $entry->chunks()->where('active', true)->exists()));

        $before = $guides->mapWithKeys(fn (AiHelperKnowledgeEntry $entry) => [$entry->source_path => [
            'id' => $entry->id,
            'content_hash' => $entry->content_hash,
            'chunks' => $entry->chunks()->count(),
        ]])->all();

        $this->seed(AiHelperSystemGuideSeeder::class);

        $after = AiHelperKnowledgeEntry::query()
            ->where('knowledge_type', AiHelperKnowledgeEntry::KNOWLEDGE_SYSTEM_GUIDE)
            ->orderBy('source_path')
            ->get()
            ->mapWithKeys(fn (AiHelperKnowledgeEntry $entry) => [$entry->source_path => [
                'id' => $entry->id,
                'content_hash' => $entry->content_hash,
                'chunks' => $entry->chunks()->count(),
            ]])->all();
        $this->assertSame($before, $after);
        $this->assertSame('Reference content.', $reference->fresh()->content);
        $this->assertTrue($reference->fresh()->active);
    }
}
