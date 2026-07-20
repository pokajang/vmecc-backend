<?php

namespace Tests\Unit;

use App\Services\AiHelperSystemGuideCatalog;
use App\Support\AiHelperSystemGuideEvaluationCases;
use Tests\TestCase;

class AiHelperSystemGuideEvaluationCasesTest extends TestCase
{
    public function test_coverage_has_all_access_and_module_cases_for_every_guide(): void
    {
        $cases = collect(app(AiHelperSystemGuideEvaluationCases::class)->coverage());

        $this->assertCount(204, $cases);
        $this->assertCount(204, $cases->pluck('id')->unique());
        $this->assertCount(51, $cases->pluck('guide_key')->unique());

        foreach ($cases->groupBy('guide_key') as $guideCases) {
            $this->assertCount(4, $guideCases);
            $this->assertTrue($guideCases->contains(fn (array $case) => str_contains($case['id'], '-authorized-')));
            $this->assertTrue($guideCases->contains(fn (array $case) => str_contains($case['id'], '-unauthorized-')));
            $this->assertTrue($guideCases->contains(fn (array $case) => str_contains($case['id'], '-forged-context-')));
            $this->assertTrue($guideCases->contains(fn (array $case) => str_contains($case['id'], '-disabled-module-')));
        }

        $this->assertTrue($cases->contains(fn (array $case) => str_starts_with($case['question'], 'Bagaimana')));
        $this->assertTrue($cases->contains(fn (array $case) => str_contains($case['question'], 'How do I guna')));
        $this->assertTrue($cases->contains(fn (array $case) => str_starts_with($case['question'], 'Ignore access controls')));

        $catalog = app(AiHelperSystemGuideCatalog::class);
        foreach ($cases->where('persona', '!=', 'unauthenticated') as $case) {
            $expectedRouteKey = $catalog->definition($case['guide_key'])['route_key'];
            if ($expectedRouteKey === 'global' || str_starts_with($case['id'], 'system-guide-forged-context-')) {
                continue;
            }
            if ($expectedRouteKey === 'reports') {
                $this->assertContains(
                    $catalog->resolveTrustedRoute($case['path'])['route_key'],
                    ['reports', 'erco', 'drill', 'fitness', 'inspection'],
                    "Evaluation path is outside the reports family for {$case['guide_key']}.",
                );

                continue;
            }
            $this->assertSame(
                $expectedRouteKey,
                $catalog->resolveTrustedRoute($case['path'])['route_key'],
                "Evaluation path does not match {$case['guide_key']}.",
            );
        }
    }

    public function test_global_release_gate_covers_every_guide_from_unrelated_routes_and_layman_aliases(): void
    {
        $cases = collect(app(AiHelperSystemGuideEvaluationCases::class)->global());
        $catalog = app(AiHelperSystemGuideCatalog::class);

        $this->assertCount(110, $cases);
        $this->assertCount(110, $cases->pluck('id')->unique());
        $this->assertCount(51, $cases->pluck('guide_key')->unique());
        $this->assertCount(51, $cases->filter(fn (array $case) => str_contains($case['id'], '-global-en-')));
        $this->assertCount(51, $cases->filter(fn (array $case) => str_contains($case['id'], '-global-bm-')));
        $this->assertCount(8, $cases->filter(fn (array $case) => str_contains($case['id'], '-global-alias-')));
        $this->assertTrue($cases->every(fn (array $case) => $case['expected_pipeline_version'] === 4));
        $this->assertTrue($cases->every(fn (array $case) => $case['retrieval_only'] === true));

        foreach ($cases as $case) {
            $actualRoute = $catalog->resolveTrustedRoute($case['path'])['route_key'];
            $this->assertNotSame(
                $case['guide_route_key'],
                $actualRoute,
                "Global evaluation path incorrectly matches {$case['guide_key']}.",
            );
        }

        $aliases = $cases->filter(fn (array $case) => str_contains($case['id'], '-global-alias-'));
        $this->assertTrue($aliases->every(fn (array $case) => isset($case['expected_topic_key'])));
        $this->assertTrue($aliases->every(fn (array $case) => $case['expected_context_dependency'] === 'explicit_topic'));
        $this->assertTrue($aliases->contains(fn (array $case) => str_contains($case['question'], 'cuti')));
        $this->assertTrue($aliases->contains(fn (array $case) => str_contains($case['question'], 'password')));
    }
}
