<?php

namespace Tests\Feature;

use App\Models\AiHelperDocument;
use App\Models\AiHelperKnowledgeEntry;
use App\Services\AiHelperSystemGuideCatalog;
use Database\Seeders\AiHelperSystemGuideSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiHelperSystemGuideSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_keeps_unaudited_drafts_disabled_and_does_not_modify_reference_knowledge(): void
    {
        config(['ai_helper.embedding_enabled' => false]);
        $document = AiHelperDocument::create([
            'title' => 'Reference',
            'source_filename' => 'reference.pdf',
            'source_mime' => 'application/pdf',
            'visibility' => AiHelperDocument::VISIBILITY_SHARED,
        ]);
        $reference = AiHelperKnowledgeEntry::create([
            'source_document_id' => $document->id,
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
        $legacy = AiHelperKnowledgeEntry::create([
            'knowledge_type' => AiHelperKnowledgeEntry::KNOWLEDGE_SYSTEM_GUIDE,
            'title' => 'Legacy module summary',
            'content' => 'Legacy content.',
            'source_mime' => 'text/markdown',
            'source_path' => 'seed:legacy-module-summary',
            'visibility' => AiHelperKnowledgeEntry::VISIBILITY_SHARED,
            'status' => AiHelperKnowledgeEntry::STATUS_ACTIVE,
            'review_status' => AiHelperKnowledgeEntry::REVIEW_APPROVED,
            'active' => true,
        ]);

        $this->seed(AiHelperSystemGuideSeeder::class);
        $firstChunkIds = AiHelperKnowledgeEntry::query()
            ->where('knowledge_type', AiHelperKnowledgeEntry::KNOWLEDGE_SYSTEM_GUIDE)
            ->where('source_path', 'like', 'seed:system-guide:%')
            ->with('chunks:id,knowledge_entry_id,heading_path')
            ->get()
            ->flatMap->chunks
            ->pluck('id')
            ->sort()
            ->values()
            ->all();
        $this->seed(AiHelperSystemGuideSeeder::class);

        $expected = app(AiHelperSystemGuideCatalog::class)->expectedCount();
        $guides = AiHelperKnowledgeEntry::query()
            ->where('knowledge_type', AiHelperKnowledgeEntry::KNOWLEDGE_SYSTEM_GUIDE)
            ->where('source_path', 'like', 'seed:system-guide:%')
            ->get();
        $this->assertCount($expected, $guides);
        $this->assertSame($firstChunkIds, $guides->flatMap->chunks->pluck('id')->sort()->values()->all());
        $this->assertTrue($guides->every(fn (AiHelperKnowledgeEntry $guide) => ! $guide->active
            && $guide->status === AiHelperKnowledgeEntry::STATUS_DISABLED
            && $guide->review_status === AiHelperKnowledgeEntry::REVIEW_PENDING
            && $guide->source_document_id === null
            && $guide->chunks()->exists()
            && ! $guide->chunks()->where('active', true)->exists()));
        $this->assertFalse($guides->flatMap->chunks->contains(fn ($chunk) => collect($chunk->heading_path ?? [])
            ->intersect([
                'Source-of-truth code references for maintainers',
                'Guide maintenance',
            ])->isNotEmpty()));
        $this->assertSame('Reference content.', $reference->fresh()->content);
        $this->assertTrue($reference->fresh()->active);
        $this->assertFalse($legacy->fresh()->active);
        $this->assertSame(AiHelperKnowledgeEntry::STATUS_DISABLED, $legacy->fresh()->status);
    }
}
