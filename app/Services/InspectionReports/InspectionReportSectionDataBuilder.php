<?php

namespace App\Services\InspectionReports;

class InspectionReportSectionDataBuilder
{
    public function __construct(
        private readonly InspectionReportTypeResolver $typeResolver,
    ) {}

    public function build(array $record, string $inspectionTypeKey): array
    {
        $checklist = array_values(array_filter(
            $this->rows($record, 'checklist'),
            fn (array $item): bool => ($item['selected'] ?? true) !== false
                && trim((string) ($item['label'] ?? '')) !== ''
                && $this->matchesInspectionType($item, $inspectionTypeKey),
        ));

        return [
            'checklist' => $checklist,
            'erAuxChecks' => $this->filterByText($this->rows($record, 'erAuxChecks', 'er_aux_checks'), 'equipment'),
            'fireExtinguisherChecks' => array_values(array_filter(
                $this->rows($record, 'fireExtinguisherChecks', 'fire_extinguisher_checks'),
                fn (array $item): bool => $this->hasText($item, 'idLocNo', 'id_loc_no')
                    || $this->hasText($item, 'barcodeNo', 'barcode_no'),
            )),
            'hydraulicChecks' => $this->filterByText($this->rows($record, 'hydraulicChecks', 'hydraulic_checks'), 'equipment'),
            'frtDailyChecks' => $this->filterByText($this->rows($record, 'frtDailyChecks', 'frt_daily_checks'), 'equipment'),
            'frtOneOffChecks' => $this->filterByText($this->rows($record, 'frtOneOffChecks', 'frt_one_off_checks'), 'equipment'),
            'highAngleChecks' => $this->filterByText($this->rows($record, 'highAngleChecks', 'high_angle_checks'), 'equipment'),
            'scbaBackPlateChecks' => $this->filterByText($this->rows($record, 'scbaBackPlateChecks', 'scba_back_plate_checks'), 'serialNo', 'serial_no'),
            'scbaCylinderChecks' => $this->filterByText($this->rows($record, 'scbaCylinderChecks', 'scba_cylinder_checks'), 'serialNo', 'serial_no'),
            'scbaFaceMaskChecks' => $this->filterByText($this->rows($record, 'scbaFaceMaskChecks', 'scba_face_mask_checks'), 'serialNo', 'serial_no'),
            'scbaCustomSections' => array_values(array_filter(
                $this->rows($record, 'scbaCustomSections', 'scba_custom_sections'),
                fn (array $item): bool => ($item['removed'] ?? false) !== true
                    && trim((string) ($item['title'] ?? '')) !== '',
            )),
            'hseSelections' => $this->values($record, 'hseSelections', 'hse_selections'),
        ];
    }

    private function rows(array $record, string $camel, ?string $snake = null): array
    {
        $rows = $record[$camel] ?? ($snake !== null ? ($record[$snake] ?? []) : []);

        return array_values(array_filter(is_array($rows) ? $rows : [], 'is_array'));
    }

    private function values(array $record, string $camel, string $snake): array
    {
        $values = $record[$camel] ?? $record[$snake] ?? [];

        return is_array($values) ? array_values($values) : [];
    }

    private function filterByText(array $rows, string $camel, ?string $snake = null): array
    {
        return array_values(array_filter(
            $rows,
            fn (array $item): bool => $this->hasText($item, $camel, $snake),
        ));
    }

    private function hasText(array $item, string $camel, ?string $snake = null): bool
    {
        return trim((string) ($item[$camel] ?? ($snake !== null ? ($item[$snake] ?? '') : ''))) !== '';
    }

    private function matchesInspectionType(array $item, string $inspectionTypeKey): bool
    {
        if ($inspectionTypeKey === '') {
            return true;
        }
        $itemType = trim((string) ($item['inspectionType'] ?? $item['incidentType'] ?? $item['type'] ?? ''));

        return $itemType === '' || $this->typeResolver->normalize($itemType) === $inspectionTypeKey;
    }
}
