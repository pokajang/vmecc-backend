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
}
