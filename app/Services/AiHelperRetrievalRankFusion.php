<?php

namespace App\Services;

class AiHelperRetrievalRankFusion
{
    /**
     * @param  array<int, array<int, int|string>>  $rankings
     * @return array<int|string, float>
     */
    public function fuse(array $rankings, int $constant = 60): array
    {
        $scores = [];
        $constant = max(1, $constant);

        foreach ($rankings as $ranking) {
            foreach (array_values(array_unique($ranking, SORT_REGULAR)) as $index => $key) {
                $scores[$key] = ($scores[$key] ?? 0.0) + (1 / ($constant + $index + 1));
            }
        }

        arsort($scores, SORT_NUMERIC);

        return $scores;
    }
}
