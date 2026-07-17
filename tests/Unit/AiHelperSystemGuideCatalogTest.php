<?php

namespace Tests\Unit;

use App\Services\AiHelperMarkdownKnowledgeParser;
use App\Services\AiHelperSystemGuideCatalog;
use RuntimeException;
use Tests\TestCase;

class AiHelperSystemGuideCatalogTest extends TestCase
{
    public function test_every_catalog_guide_has_valid_fail_closed_frontmatter_and_content(): void
    {
        $catalog = app(AiHelperSystemGuideCatalog::class);
        $parser = app(AiHelperMarkdownKnowledgeParser::class);
        $files = glob(database_path('ai-helper-system-guides/*.md')) ?: [];
        $keys = [];

        foreach ($files as $file) {
            $parsed = $parser->parseFile($file, true);
            $metadata = $catalog->validate($parsed['frontmatter'], $parsed['content'], $file);
            $this->assertSame(AiHelperSystemGuideCatalog::RELEASE_DRAFT, $metadata['release_status']);
            $this->assertFalse($metadata['active']);
            $this->assertArrayNotHasKey($metadata['key'], $keys, 'Duplicate system-guide key.');
            $keys[$metadata['key']] = true;
        }

        $this->assertCount($catalog->expectedCount(), $files);
        $this->assertEqualsCanonicalizing($catalog->keys(), array_keys($keys));
        $this->assertSame([], $catalog->validateRegistry());
    }

    public function test_unknown_permission_is_rejected(): void
    {
        $catalog = app(AiHelperSystemGuideCatalog::class);
        $parser = app(AiHelperMarkdownKnowledgeParser::class);
        $file = database_path('ai-helper-system-guides/leave-self-service.md');
        $parsed = $parser->parseFile($file, true);
        $parsed['frontmatter']['required_permissions'] = ['unknown.permission'];

        $this->expectException(RuntimeException::class);
        $catalog->validate($parsed['frontmatter'], $parsed['content'], $file);
    }

    public function test_generic_draft_cannot_be_promoted_without_a_workflow_audit(): void
    {
        $catalog = app(AiHelperSystemGuideCatalog::class);
        $parser = app(AiHelperMarkdownKnowledgeParser::class);
        $file = database_path('ai-helper-system-guides/staff-records.md');
        $parsed = $parser->parseFile($file, true);
        $parsed['frontmatter']['release_status'] = AiHelperSystemGuideCatalog::RELEASE_APPROVED;
        $parsed['frontmatter']['active'] = true;
        $parsed['frontmatter']['version'] = AiHelperSystemGuideCatalog::FINAL_VERSION;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('generic draft wording');
        $catalog->validate($parsed['frontmatter'], $parsed['content'], $file);
    }

    public function test_audited_candidate_reaches_the_hash_approval_gate(): void
    {
        $catalog = app(AiHelperSystemGuideCatalog::class);
        $parser = app(AiHelperMarkdownKnowledgeParser::class);
        $file = database_path('ai-helper-system-guides/ask-ai-usage.md');
        $parsed = $parser->parseFile($file, true);
        $parsed['frontmatter']['release_status'] = AiHelperSystemGuideCatalog::RELEASE_APPROVED;
        $parsed['frontmatter']['active'] = true;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no approval manifest record');
        $catalog->validate($parsed['frontmatter'], $parsed['content'], $file);
    }

    public function test_approved_guide_cannot_remain_inactive(): void
    {
        $catalog = app(AiHelperSystemGuideCatalog::class);
        $parser = app(AiHelperMarkdownKnowledgeParser::class);
        $file = database_path('ai-helper-system-guides/ask-ai-usage.md');
        $parsed = $parser->parseFile($file, true);
        $parsed['frontmatter']['release_status'] = AiHelperSystemGuideCatalog::RELEASE_APPROVED;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must be active');
        $catalog->validate($parsed['frontmatter'], $parsed['content'], $file);
    }
}
