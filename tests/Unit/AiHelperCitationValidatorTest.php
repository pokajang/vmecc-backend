<?php

namespace Tests\Unit;

use App\Services\AiHelperCitationValidator;
use Tests\TestCase;

class AiHelperCitationValidatorTest extends TestCase
{
    private array $sources = [
        ['source_id' => 'S1', 'document_id' => 10, 'title' => 'Annex 1'],
        ['source_id' => 'S2', 'document_id' => 11, 'title' => 'Annex 2'],
    ];

    public function test_it_accepts_cited_paragraphs_and_grouped_markdown_lists(): void
    {
        $content = <<<'MD'
According to Annex 2, the Incident Controller duties include: [S1]

- Deliver the initial incident briefing.
- Use the TIME OUT process.

The OSC must keep the IC updated. [S2]
MD;

        $result = app(AiHelperCitationValidator::class)->validate($content, $this->sources);

        $this->assertTrue($result['valid']);
        $this->assertSame('validated', $result['status']);
        $this->assertSame(['S1', 'S2'], $result['cited_source_ids']);
    }

    public function test_it_rejects_an_uncited_operational_block(): void
    {
        $content = "Call 999 for an ambulance. [S1]\n\nThe response team can handle two casualties.";

        $result = app(AiHelperCitationValidator::class)->validate($content, $this->sources);

        $this->assertFalse($result['valid']);
        $this->assertSame('uncited_operational_content', $result['reason']);
        $this->assertSame([1], $result['uncited_blocks']);
    }

    public function test_it_rejects_unknown_source_ids(): void
    {
        $result = app(AiHelperCitationValidator::class)->validate(
            'Call 999 for an ambulance. [S99]',
            $this->sources,
        );

        $this->assertFalse($result['valid']);
        $this->assertSame('unknown_source', $result['reason']);
        $this->assertSame(['S99'], $result['unknown_source_ids']);
    }

    public function test_it_accepts_grouped_source_markers(): void
    {
        $result = app(AiHelperCitationValidator::class)->validate(
            'The two annexes share this requirement. [S1, S2]',
            $this->sources,
        );

        $this->assertTrue($result['valid']);
        $this->assertSame(['S1', 'S2'], $result['cited_source_ids']);
    }

    public function test_it_rejects_a_revision_claim_cited_to_a_different_revision(): void
    {
        $sources = [
            ['source_id' => 'S1', 'document_id' => 10, 'title' => 'ANNEX 18 ERP for Man Overboard (MOB)'],
            ['source_id' => 'S2', 'document_id' => 11, 'title' => 'ANNEX 18 ERP for Man Overboard (MOB).Rev 001 - Feb 2026'],
        ];

        $result = app(AiHelperCitationValidator::class)->validate(
            'Rev 001 specifies the Destroyer Turn. [S1]',
            $sources,
        );

        $this->assertFalse($result['valid']);
        $this->assertSame('misattributed_revision', $result['reason']);
    }

    public function test_enforcement_replaces_rejected_content_and_removes_sources(): void
    {
        $result = app(AiHelperCitationValidator::class)->enforce(
            'Mobilize the response team immediately.',
            $this->sources,
            'en',
        );

        $this->assertTrue($result['rejected']);
        $this->assertSame([], $result['sources']);
        $this->assertStringContainsString('sufficiently sourced', $result['content']);
        $this->assertStringNotContainsString('Mobilize', $result['content']);
    }

    public function test_it_does_not_require_citations_when_no_sources_were_retrieved(): void
    {
        $result = app(AiHelperCitationValidator::class)->validate(
            'Credential information is not available through Ask AI.',
            [],
        );

        $this->assertTrue($result['valid']);
        $this->assertSame('not_required', $result['status']);
    }

    public function test_it_rejects_a_source_marker_when_no_sources_were_retrieved(): void
    {
        $result = app(AiHelperCitationValidator::class)->validate('No guidance is available. [S1]', []);

        $this->assertFalse($result['valid']);
        $this->assertSame('unknown_source', $result['reason']);
    }

    public function test_it_allows_non_operational_comparison_framing_before_cited_content(): void
    {
        $content = <<<'MD'
Here’s a concise comparison of Annex 1 and Annex 2.

## Shared requirement

Both documents require an incident briefing. [S1][S2]
MD;

        $result = app(AiHelperCitationValidator::class)->validate($content, $this->sources);

        $this->assertTrue($result['valid']);
    }

    public function test_it_allows_a_safe_source_limitation_before_cited_support(): void
    {
        $sources = [
            ['source_id' => 'S1', 'document_id' => 10, 'title' => 'ANNEX 18 ERP for Man Overboard (MOB)'],
            ['source_id' => 'S2', 'document_id' => 11, 'title' => 'ANNEX 18 ERP for Man Overboard (MOB).Rev 001 - Feb 2026'],
        ];
        $content = <<<'MD'
No. The available knowledge does not establish which source is authoritative.

- Annex 1 has revision not stated. [S1]
- Annex 2 is Rev 001. [S2]
MD;

        $result = app(AiHelperCitationValidator::class)->validate($content, $sources);

        $this->assertTrue($result['valid']);
    }
}
