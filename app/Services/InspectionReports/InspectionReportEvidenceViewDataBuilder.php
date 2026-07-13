<?php

namespace App\Services\InspectionReports;

class InspectionReportEvidenceViewDataBuilder
{
    public function build(array $record): array
    {
        $remarks = trim((string) ($record['reportRemarks'] ?? $record['report_remarks'] ?? ''));
        $photos = array_values(array_filter(
            is_array($record['photos'] ?? null) ? $record['photos'] : [],
            fn (mixed $photo): bool => $this->isDisplayablePhoto($photo),
        ));

        $groups = [];
        foreach ($photos as $index => $photo) {
            $groups[] = [
                'kind' => 'Report photograph',
                'title' => 'Figure '.($index + 1),
                'remarks' => '',
                'photos' => [$photo],
                'alt' => 'Inspection report photo',
            ];
        }

        return [
            'visible' => $remarks !== '' || $photos !== [],
            'remarks' => $remarks,
            'photos' => $photos,
            'groups' => $groups,
        ];
    }

    private function isDisplayablePhoto(mixed $photo): bool
    {
        if (! is_array($photo)) {
            return false;
        }
        if (($photo['imageUnavailable'] ?? false) === true) {
            return true;
        }

        return preg_match(
            '/^data:image\/[a-z0-9.+-]+;base64,/i',
            trim((string) ($photo['url'] ?? '')),
        ) === 1;
    }
}
