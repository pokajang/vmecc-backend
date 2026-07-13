<?php

namespace App\Services\InspectionReports;

class PdfFooterTextFitter
{
    public function fit(string $displayId, string $suffix, callable $measure, float $maxWidth): string
    {
        $displayId = preg_replace('/\s+/u', ' ', trim($displayId)) ?: 'Inspection Report';
        $fullText = $displayId.$suffix;
        if ($measure($fullText) <= $maxWidth) {
            return $fullText;
        }

        $ellipsis = '...';
        $low = 0;
        $high = mb_strlen($displayId, 'UTF-8');
        $best = $ellipsis.$suffix;
        while ($low <= $high) {
            $length = intdiv($low + $high, 2);
            $candidate = rtrim(mb_substr($displayId, 0, $length, 'UTF-8')).$ellipsis.$suffix;
            if ($measure($candidate) <= $maxWidth) {
                $best = $candidate;
                $low = $length + 1;
            } else {
                $high = $length - 1;
            }
        }

        return $best;
    }
}
