<?php

namespace Tests\Unit;

use App\Services\AiHelperMarkdownStructureParser;
use PHPUnit\Framework\TestCase;

class AiHelperMarkdownStructureParserTest extends TestCase
{
    public function test_it_preserves_heading_and_visual_page_context(): void
    {
        $markdown = <<<'MD'
# Fire response

## Initial action

Notify the Incident Controller immediately.

![Original PDF page 4: Fire response flow](assets/fire/page-004.png)
MD;

        $chunks = (new AiHelperMarkdownStructureParser)->chunks($markdown, 1000);
        $text = collect($chunks)->firstWhere('content_type', 'text');
        $visual = collect($chunks)->firstWhere('content_type', 'visual_reference');

        $this->assertNotContains('heading', collect($chunks)->pluck('content_type')->all());
        $this->assertSame(['Fire response', 'Initial action'], $text['heading_path']);
        $this->assertNull($text['page_start']);
        $this->assertSame(4, $visual['page_start']);
        $this->assertStringContainsString('Fire response flow', $visual['search_text']);
    }

    public function test_it_keeps_a_markdown_table_together(): void
    {
        $markdown = "# Roles\n\n| Role | Duty |\n| --- | --- |\n| IC | Direct response |";
        $chunks = (new AiHelperMarkdownStructureParser)->chunks($markdown, 600);
        $table = collect($chunks)->firstWhere('content_type', 'table');

        $this->assertStringContainsString('| IC | Direct response |', $table['content']);
    }

    public function test_it_does_not_treat_plain_pipe_rows_as_a_repeating_header(): void
    {
        $rows = collect(range(1, 80))->map(fn (int $number) => "| Step {$number} | Action {$number} |")->join("\n");

        $chunks = (new AiHelperMarkdownStructureParser)->chunks("# Steps\n\n{$rows}", 600);
        $tables = collect($chunks)->where('content_type', 'table')->values();

        $this->assertGreaterThan(1, $tables->count());
        $this->assertSame(1, substr_count($tables->pluck('content')->join("\n"), '| Step 1 | Action 1 |'));
    }
}
