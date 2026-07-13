<?php

namespace App\Services\InspectionReports;

class InspectionReportTypeResolver
{
    public function resolve(mixed $value): InspectionReportType
    {
        $type = $this->normalize($value);

        return match (true) {
            str_contains($type, 'er aux'),
            str_contains($type, 'emergency response auxiliary') => InspectionReportType::ErAux,
            str_contains($type, 'fire extinguisher') => InspectionReportType::FireExtinguisher,
            str_contains($type, 'hydraulic') => InspectionReportType::Hydraulic,
            str_contains($type, 'frt daily'),
            str_contains($type, 'fire truck daily'),
            str_contains($type, 'fire truck readiness') => InspectionReportType::Frt,
            str_contains($type, 'high angle') => InspectionReportType::HighAngle,
            preg_match('/(^|\s)scba(\s|$)/', $type) === 1 => InspectionReportType::Scba,
            str_contains($type, 'health safety environment'),
            preg_match('/(^|\s)hse(\s|$)/', $type) === 1 => InspectionReportType::Hse,
            str_contains($type, 'general inspection') => InspectionReportType::General,
            default => InspectionReportType::Unknown,
        };
    }

    public function normalize(mixed $value): string
    {
        $normalized = strtolower((string) $value);
        $normalized = preg_replace('/[^a-z0-9]+/', ' ', $normalized);

        return trim((string) preg_replace('/\s+/', ' ', (string) $normalized));
    }
}
