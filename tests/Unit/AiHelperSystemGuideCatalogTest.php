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
            $this->assertSame(AiHelperSystemGuideCatalog::RELEASE_FINAL, $metadata['release_status']);
            $this->assertTrue($metadata['active']);
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
        $parsed['content'] .= "\n\nOpen the stated page.";

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('generic draft wording');
        $catalog->validate($parsed['frontmatter'], $parsed['content'], $file);
    }

    public function test_maintainer_language_is_rejected_from_user_content(): void
    {
        $catalog = app(AiHelperSystemGuideCatalog::class);
        $parser = app(AiHelperMarkdownKnowledgeParser::class);
        $file = database_path('ai-helper-system-guides/ask-ai-usage.md');
        $parsed = $parser->parseFile($file, true);
        $parsed['content'] .= "\n\nCall the API controller endpoint.";

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('maintainer-oriented wording');
        $catalog->validate($parsed['frontmatter'], $parsed['content'], $file);
    }

    public function test_final_guide_cannot_remain_inactive(): void
    {
        $catalog = app(AiHelperSystemGuideCatalog::class);
        $parser = app(AiHelperMarkdownKnowledgeParser::class);
        $file = database_path('ai-helper-system-guides/ask-ai-usage.md');
        $parsed = $parser->parseFile($file, true);
        $parsed['frontmatter']['active'] = false;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must be active');
        $catalog->validate($parsed['frontmatter'], $parsed['content'], $file);
    }

    public function test_every_final_guide_has_a_separate_dossier_with_existing_code_references(): void
    {
        $catalog = app(AiHelperSystemGuideCatalog::class);
        $parser = app(AiHelperMarkdownKnowledgeParser::class);
        foreach ($catalog->keys() as $key) {
            $file = database_path("ai-helper-system-guides/{$key}.md");
            $parsed = $parser->parseFile($file, true);
            $dossier = base_path("docs/ai-helper-system-guide-reviews/{$key}.md");
            $this->assertFileExists($dossier);
            $this->assertDoesNotMatchRegularExpression('/vmecc-(?:backend|frontend)\//', $parsed['content']);

            $dossierContent = (string) file_get_contents($dossier);
            foreach (['Verified user workflow', 'Verification coverage', 'Discrepancies'] as $heading) {
                $this->assertMatchesRegularExpression(
                    '/^## '.preg_quote($heading, '/').'\s*$/m',
                    $dossierContent,
                    "Missing {$heading} dossier section for {$key}.",
                );
            }
            $this->assertMatchesRegularExpression(
                '/`vmecc-frontend\/src\/routes\.js`/',
                $dossierContent,
                "Missing frontend route evidence for {$key}.",
            );
            $this->assertMatchesRegularExpression(
                '/`vmecc-backend\/routes\/api\.php`/',
                $dossierContent,
                "Missing backend route evidence for {$key}.",
            );

            preg_match_all('/`vmecc-(backend|frontend)\/([^`]+)`/', $dossierContent, $references, PREG_SET_ORDER);
            $this->assertNotEmpty($references, "Missing source references for {$key}.");
            foreach ($references as $reference) {
                $root = $reference[1] === 'backend' ? base_path() : dirname(base_path()).DIRECTORY_SEPARATOR.'vmecc-frontend';
                $this->assertFileExists($root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $reference[2]));
            }
        }
    }
}
