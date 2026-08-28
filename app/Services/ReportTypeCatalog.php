<?php

namespace App\Services;

final class ReportTypeCatalog
{
    public const TYPES = [
        'inspection',
        'erco',
        'drill',
        'fitness-test',
        'er-assessment',
    ];

    public static function normalize(mixed $reportType): string
    {
        return strtolower(trim((string) $reportType));
    }

    public static function supports(mixed $reportType): bool
    {
        return in_array(self::normalize($reportType), self::TYPES, true);
    }
}
