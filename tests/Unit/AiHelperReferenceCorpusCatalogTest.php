<?php

namespace Tests\Unit;

use App\Models\AiHelperKnowledgeEntry;
use App\Services\AiHelperReferenceCorpusCatalog;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class AiHelperReferenceCorpusCatalogTest extends TestCase
{
    public function test_markdown_is_canonical_and_the_pdf_directory_is_optional(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('catalog/md/Operational note.md', "# Operational note\n");
        Storage::disk('local')->put('catalog/md/Operational note.json', json_encode([
            'title' => 'Operational Note',
            'visibility' => 'shared',
            'scope' => 'global',
            'review_status' => 'approved',
            'tags' => ['operations'],
        ], JSON_THROW_ON_ERROR));
        config(['ai_helper.reference_corpus_path' => Storage::disk('local')->path('catalog')]);

        $sources = app(AiHelperReferenceCorpusCatalog::class)->sources();

        $this->assertCount(1, $sources);
        $this->assertNull($sources[0]['pdf_path']);
        $this->assertSame('Operational Note', $sources[0]['title']);
        $this->assertSame(AiHelperKnowledgeEntry::VISIBILITY_SHARED, $sources[0]['visibility']);
        $this->assertSame(AiHelperKnowledgeEntry::SCOPE_GLOBAL, $sources[0]['scope_type']);
        $this->assertSame(['operations'], $sources[0]['tags']);
        $this->assertSame([], app(AiHelperReferenceCorpusCatalog::class)->orphanPdfFiles());
    }

    public function test_it_reports_a_pdf_without_canonical_markdown(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('catalog/md/Valid.md', "# Valid\n");
        Storage::disk('local')->put('catalog/pdf/Orphan.pdf', "%PDF-1.4\n%%EOF");
        config(['ai_helper.reference_corpus_path' => Storage::disk('local')->path('catalog')]);

        $this->assertSame(
            ['Orphan.pdf'],
            app(AiHelperReferenceCorpusCatalog::class)->orphanPdfFiles(),
        );
    }

    public function test_it_rejects_unknown_sidecar_metadata(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('catalog/md/Invalid.md', "# Invalid\n");
        Storage::disk('local')->put('catalog/md/Invalid.json', '{"unexpected":true}');
        config(['ai_helper.reference_corpus_path' => Storage::disk('local')->path('catalog')]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unknown reference metadata fields');

        app(AiHelperReferenceCorpusCatalog::class)->sources();
    }
}
