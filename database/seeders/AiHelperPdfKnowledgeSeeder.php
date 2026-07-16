<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Backward-compatible seeder name. PDFs are reference documents; only matching Markdown is indexed.
 */
class AiHelperPdfKnowledgeSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(AiHelperReferenceCorpusSeeder::class);
    }
}
