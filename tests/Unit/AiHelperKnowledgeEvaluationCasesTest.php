<?php

namespace Tests\Unit;

use App\Support\AiHelperKnowledgeEvaluationCases;
use PHPUnit\Framework\TestCase;

class AiHelperKnowledgeEvaluationCasesTest extends TestCase
{
    public function test_the_evaluation_matrix_covers_every_document_with_four_retrieval_questions(): void
    {
        $coverage = collect(AiHelperKnowledgeEvaluationCases::corpusCoverage());

        $this->assertCount(140, $coverage);
        $this->assertCount(140, $coverage->pluck('id')->unique());
        $this->assertCount(35, $coverage->flatMap(fn (array $case) => $case['titles'])->unique());
        $this->assertTrue($coverage->every(fn (array $case) => $case['retrieval_only'] === true));
        $this->assertCount(156, AiHelperKnowledgeEvaluationCases::all());
    }
}
