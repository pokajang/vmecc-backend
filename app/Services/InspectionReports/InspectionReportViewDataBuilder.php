<?php

namespace App\Services\InspectionReports;

class InspectionReportViewDataBuilder
{
    public function __construct(
        private readonly InspectionReportTypeResolver $typeResolver,
        private readonly InspectionReportEvidenceViewDataBuilder $evidenceBuilder,
        private readonly InspectionReportSectionDataBuilder $sectionDataBuilder,
    ) {}

    public function build(array $record): array
    {
        $inspectionType = $this->text(
            $record['incidentType'] ?? $record['inspectionType'] ?? $record['inspection_type'] ?? ''
        );
        $type = $this->typeResolver->resolve($inspectionType);
        $inspectionTypeKey = $this->typeResolver->normalize($inspectionType);
        $reportEvidence = $this->evidenceBuilder->build($record);

        return [
            'displayId' => (string) ($record['displayId'] ?? '-'),
            'status' => (string) ($record['status'] ?? 'Submitted'),
            'inspectionType' => $inspectionType,
            'inspectionTypeKey' => $inspectionTypeKey,
            'type' => $type,
            'location' => $this->text($record['location'] ?? $record['selectedLocation'] ?? ''),
            'description' => (string) ($record['description'] ?? ''),
            'reportRemarks' => $this->text($record['reportRemarks'] ?? $record['report_remarks'] ?? ''),
            'reportEvidence' => $reportEvidence,
            'sections' => $this->sectionDataBuilder->build($record, $inspectionTypeKey),
            'isErAuxInspection' => $type === InspectionReportType::ErAux,
            'isFireExtinguisherInspection' => $type === InspectionReportType::FireExtinguisher,
            'isHydraulicInspection' => $type === InspectionReportType::Hydraulic,
            'isFrtInspection' => $type === InspectionReportType::Frt,
            'isHighAngleInspection' => $type === InspectionReportType::HighAngle,
            'isScbaInspection' => $type === InspectionReportType::Scba,
            'isHseInspection' => $type === InspectionReportType::Hse,
            'isGeneralInspection' => $type === InspectionReportType::General,
        ];
    }

    private function text(mixed $value): string
    {
        return trim((string) $value);
    }
}
