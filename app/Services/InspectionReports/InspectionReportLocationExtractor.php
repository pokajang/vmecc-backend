<?php

namespace App\Services\InspectionReports;

class InspectionReportLocationExtractor
{
    public function __construct(
        private readonly InspectionReportLocationService $locationService,
    ) {}

    public function extract(array $record, InspectionReportType $type): array
    {
        $locations = match ($type) {
            InspectionReportType::FireExtinguisher => $this->hierarchicalRows(
                $this->filterByAnyText(
                    $this->rows($record, 'fireExtinguisherChecks', 'fire_extinguisher_checks'),
                    [['idLocNo', 'id_loc_no'], ['barcodeNo', 'barcode_no']],
                ),
            ),
            InspectionReportType::Hydraulic => $this->flatRows(
                $this->filterByAnyText(
                    $this->rows($record, 'hydraulicChecks', 'hydraulic_checks'),
                    [['equipment']],
                ),
            ),
            InspectionReportType::ErAux => $this->flatRows(
                $this->filterByAnyText(
                    $this->rows($record, 'erAuxChecks', 'er_aux_checks'),
                    [['equipment']],
                ),
            ),
            InspectionReportType::HighAngle => $this->hierarchicalRows(
                $this->filterByAnyText(
                    $this->rows($record, 'highAngleChecks', 'high_angle_checks'),
                    [['equipment']],
                ),
            ),
            InspectionReportType::Scba => $this->scbaRows($record),
            default => [],
        };

        $locations = array_values(array_filter($locations, $this->hasLocationValue(...)));
        if ($locations !== []) {
            return $locations;
        }

        return in_array($type, [
            InspectionReportType::FireExtinguisher,
            InspectionReportType::Hydraulic,
            InspectionReportType::ErAux,
            InspectionReportType::HighAngle,
            InspectionReportType::Scba,
        ], true)
            ? $this->hierarchicalRows($this->rows($record, 'inspectionLocations', 'inspection_locations'))
            : [];
    }

    private function hierarchicalRows(array $rows): array
    {
        return array_map($this->locationService->fromRow(...), $rows);
    }

    private function flatRows(array $rows): array
    {
        return $this->hierarchicalRows($rows);
    }

    private function scbaRows(array $record): array
    {
        $rows = $this->filterByAnyText(array_merge(
            $this->rows($record, 'scbaBackPlateChecks', 'scba_back_plate_checks'),
            $this->rows($record, 'scbaCylinderChecks', 'scba_cylinder_checks'),
            $this->rows($record, 'scbaFaceMaskChecks', 'scba_face_mask_checks'),
        ), [
            ['serialNo', 'serial_no'],
        ]);
        foreach (array_filter(
            $this->rows($record, 'scbaCustomSections', 'scba_custom_sections'),
            fn (array $section): bool => ($section['removed'] ?? false) !== true,
        ) as $section) {
            $sectionRows = array_values(array_filter(
                is_array($section['rows'] ?? null) ? $section['rows'] : [],
                fn (mixed $row): bool => is_array($row) && ($row['removed'] ?? false) !== true,
            ));
            $rows = array_merge($rows, $this->filterByAnyText($sectionRows, [
                ['serialNo', 'serial_no'],
                ['brand'],
            ]));
        }

        return $this->flatRows($rows);
    }

    private function filterByAnyText(array $rows, array $aliases): array
    {
        return array_values(array_filter($rows, function (array $row) use ($aliases): bool {
            foreach ($aliases as $fields) {
                foreach ($fields as $field) {
                    if (trim((string) ($row[$field] ?? '')) !== '') {
                        return true;
                    }
                }
            }

            return false;
        }));
    }

    private function hasLocationValue(array $location): bool
    {
        return trim((string) ($location['zone'] ?? '')) !== ''
            || trim((string) ($location['mainLocation'] ?? '')) !== ''
            || trim((string) ($location['subLocation'] ?? '')) !== '';
    }

    private function rows(array $record, string $camel, string $snake): array
    {
        $rows = $record[$camel] ?? $record[$snake] ?? [];

        return array_values(array_filter(is_array($rows) ? $rows : [], 'is_array'));
    }
}
