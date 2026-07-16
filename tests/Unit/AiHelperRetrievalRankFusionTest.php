<?php

namespace Tests\Unit;

use App\Services\AiHelperRetrievalRankFusion;
use Tests\TestCase;

class AiHelperRetrievalRankFusionTest extends TestCase
{
    public function test_it_combines_independent_rankings_without_comparing_raw_scores(): void
    {
        $scores = app(AiHelperRetrievalRankFusion::class)->fuse([
            [10, 20, 30],
            [20, 10, 40],
            [20, 30, 10],
        ]);

        $this->assertSame(20, array_key_first($scores));
        $this->assertGreaterThan($scores[40], $scores[10]);
        $this->assertGreaterThan($scores[30], $scores[20]);
    }
}
