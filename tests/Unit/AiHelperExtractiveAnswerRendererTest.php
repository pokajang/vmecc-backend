<?php

namespace Tests\Unit;

use App\Services\AiHelperExtractiveAnswerRenderer;
use Tests\TestCase;

class AiHelperExtractiveAnswerRendererTest extends TestCase
{
    public function test_it_removes_frontmatter_headings_and_markdown_tables_but_preserves_steps(): void
    {
        $guidance = [[
            'source_id' => 'S1',
            'content' => <<<'MD'
---
title: Fire Extinguisher Management
---
# Fire Extinguisher Management
| Field | Value |
|---|---|
| Owner | Operations |

1. Go to **Inspection** and open **Fire Extinguishers**.
2. Search for the asset to prevent duplicates.
MD,
        ]];
        $sources = [['source_id' => 'S1', 'title' => 'Fire Extinguisher Management']];

        $result = app(AiHelperExtractiveAnswerRenderer::class)->render(
            $guidance,
            $sources,
            'en',
            'validation_failed',
        );

        $this->assertNotNull($result);
        $this->assertStringNotContainsString('| Field |', $result['content']);
        $this->assertStringNotContainsString('Owner | Operations', $result['content']);
        $this->assertStringContainsString('1. Go to **Inspection**', $result['content']);
        $this->assertStringContainsString('[S1]', $result['content']);
    }

    public function test_it_labels_an_english_verbatim_extract_when_bahasa_melayu_is_selected(): void
    {
        $result = app(AiHelperExtractiveAnswerRenderer::class)->render(
            [[
                'source_id' => 'S1',
                'content' => 'Open Inspection and select New Inspection. Complete every required check and submit the report.',
            ]],
            [['source_id' => 'S1', 'title' => 'Inspection guide']],
            'bm',
            'validation_failed',
        );

        $this->assertNotNull($result);
        $this->assertStringContainsString('tersedia dalam bahasa Inggeris', $result['content']);
        $this->assertStringContainsString('[S1]', $result['content']);
    }
}
