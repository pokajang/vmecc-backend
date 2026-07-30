<?php

namespace Tests\Feature;

use App\Models\AiHelperKnowledgeEntry;
use App\Services\AiHelperKnowledgeProcessingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiHelperMarkdownProcessingTest extends TestCase
{
    use RefreshDatabase;

    public function test_markdown_structure_is_preserved_during_ingestion(): void
    {
        config(['ai_helper.embedding_enabled' => false]);
        $markdown = <<<'MD'
# Emergency response

## Initial action

- Notify the Incident Controller.
- Call 999.

| Role | Duty |
| --- | --- |
| IC | Direct response |
MD;
        $entry = AiHelperKnowledgeEntry::create([
            'title' => 'Structured source',
            'content' => $markdown,
            'source_filename' => 'structured-source.md',
            'source_mime' => 'text/markdown',
            'source_path' => 'seed:test:structured-source',
            'status' => AiHelperKnowledgeEntry::STATUS_PROCESSING,
            'review_status' => AiHelperKnowledgeEntry::REVIEW_APPROVED,
            'active' => true,
        ]);

        $processed = app(AiHelperKnowledgeProcessingService::class)->processTextEntry($entry, $markdown);

        $this->assertTrue($processed);
        $entry->refresh()->load('chunks');
        $this->assertStringContainsString("# Emergency response\n\n## Initial action", $entry->content);
        $this->assertSame(
            ['Emergency response', 'Initial action'],
            $entry->chunks->firstWhere('content_type', 'text')->heading_path,
        );
        $this->assertStringContainsString('| IC | Direct response |', $entry->chunks->firstWhere('content_type', 'table')->content);
        $this->assertLessThan(3000, $entry->chunks->max(fn ($chunk) => mb_strlen((string) $chunk->search_text)));
    }

    public function test_ingestion_builds_an_active_corpus_entity_index_without_model_calls(): void
    {
        config(['ai_helper.embedding_enabled' => false]);
        $markdown = <<<'MD'
# Emergency roles

## EMERGENCY RESPONSE TEAM MEMBER (ERTM)

| ROLE | RESPONSIBILITIES |
| --- | --- |
| TACTICAL RESPONSE TEAM MEMBER | Conduct inspections and respond under OSC command. |
MD;
        $entry = AiHelperKnowledgeEntry::create([
            'title' => 'Role source',
            'content' => $markdown,
            'source_filename' => 'roles.md',
            'source_mime' => 'text/markdown',
            'source_path' => 'seed:test:roles',
            'status' => AiHelperKnowledgeEntry::STATUS_PROCESSING,
            'review_status' => AiHelperKnowledgeEntry::REVIEW_APPROVED,
            'active' => true,
        ]);

        $this->assertTrue(app(AiHelperKnowledgeProcessingService::class)->processTextEntry($entry, $markdown));

        $entities = $entry->entities()->where('active', true)->with('aliases')->get();
        $this->assertNotNull($entities->firstWhere('normalized_name', 'emergency response team member'));
        $this->assertNotNull($entities->firstWhere('normalized_name', 'tactical response team member'));
        $this->assertTrue($entities->flatMap->aliases->contains('normalized_alias', 'ertm'));
    }
}
