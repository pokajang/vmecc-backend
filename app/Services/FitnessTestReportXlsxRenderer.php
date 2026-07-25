<?php

namespace App\Services;

use ZipArchive;

final class FitnessTestReportXlsxRenderer
{
    public const CONTENT_TYPE = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    public function render(array $payload): string
    {
        if (! class_exists(ZipArchive::class)) {
            return '';
        }

        $tempPath = tempnam(sys_get_temp_dir(), 'fitness-report-xlsx');
        if ($tempPath === false) {
            return '';
        }

        $zip = new ZipArchive;
        $opened = $zip->open($tempPath, ZipArchive::OVERWRITE | ZipArchive::CREATE);
        if ($opened !== true) {
            @unlink($tempPath);

            return '';
        }

        try {
            $zip->addFromString('[Content_Types].xml', $this->buildContentTypesXml());
            $zip->addFromString('_rels/.rels', $this->buildRootRelsXml());
            $zip->addFromString('xl/_rels/workbook.xml.rels', $this->buildWorkbookRelsXml());
            $zip->addFromString('xl/workbook.xml', $this->buildWorkbookXml());
            $zip->addFromString('xl/worksheets/sheet1.xml', $this->buildWorksheetXml($payload));
        } finally {
            $zip->close();
        }

        $output = @file_get_contents($tempPath) ?: '';
        @unlink($tempPath);

        return $output;
    }

    private function buildContentTypesXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
    <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
    <Default Extension="xml" ContentType="application/xml"/>
    <Override PartName="/_rels/.rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
    <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
    <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
</Types>
XML;
    }

    private function buildRootRelsXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>
XML;
    }

    private function buildWorkbookRelsXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
</Relationships>
XML;
    }

    private function buildWorkbookXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
    <sheets>
        <sheet name="Fitness Test Report" sheetId="1" r:id="rId1"/>
    </sheets>
</workbook>
XML;
    }

    private function buildWorksheetXml(array $payload): string
    {
        $dataRows = $this->buildDataRows($payload);

        $rowTags = [];
        foreach ($dataRows as $rowIndex => $row) {
            $rowNumber = $rowIndex + 1;
            if (count($row) === 0) {
                $rowTags[] = '<row r="'.$rowNumber.'"/>';

                continue;
            }

            $cells = [];
            $maxCol = count($row);
            for ($colIndex = 0; $colIndex < $maxCol; $colIndex += 1) {
                $cellRef = $this->columnName($colIndex).$rowNumber;
                $cellValue = is_scalar($row[$colIndex]) ? (string) $row[$colIndex] : '';
                $cells[] = $this->stringCell($cellRef, $cellValue);
            }
            $rowTags[] = '<row r="'.$rowNumber.'">'.implode('', $cells).'</row>';
        }

        $maxColLetter = $this->columnName((count($dataRows[0] ?? []) > 0 ? count($dataRows[0]) : 0) - 1);
        $maxRow = count($dataRows);
        $dimension = $maxRow > 0 && $maxColLetter !== '' ? 'A1:'.$maxColLetter.$maxRow : 'A1:A1';

        $template = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
    <dimension ref="{$dimension}"/>
    <sheetData>
        {sheetRows}
    </sheetData>
</worksheet>
XML;

        return str_replace(
            ['{sheetRows}', '{dimension}'],
            [implode('', $rowTags), $dimension],
            $template,
        );
    }

    private function buildDataRows(array $payload): array
    {
        $displayId = trim((string) ($payload['displayId'] ?? ''));
        $reportingMonth = trim((string) ($payload['reportingMonth'] ?? ''));
        $documentReference = trim((string) ($payload['documentReference'] ?? ''));
        $protocolRevision = trim((string) ($payload['protocolRevision'] ?? ''));
        $revision = (string) ($payload['revision'] ?? '');
        $status = trim((string) ($payload['status'] ?? ''));
        $completion = is_array($payload['completionStatistics'] ?? null) ? $payload['completionStatistics'] : [];

        $rows = [
            ['Fitness Test Report'],
            ['Display ID', $displayId],
            ['Reporting Month', $reportingMonth],
            ['Document Reference', $documentReference],
            ['Protocol Revision', $protocolRevision],
            ['Revision', $revision],
            ['Status', $status],
            [],
            ['Completion Summary'],
            ['Participant Count', $completion['participantCount'] ?? 0],
            ['Passed Assessments', $completion['passedAssessmentCount'] ?? 0],
            ['Failed Assessments', $completion['failedAssessmentCount'] ?? 0],
            ['Incomplete Assessments', $completion['incompleteAssessmentCount'] ?? 0],
            [],
        ];

        $checkpointColumns = $this->collectCheckpointColumns($payload);

        $headerRow = [
            'Shift Group',
            'Group Name',
            'Team',
            'Assessor',
            'Participant ID',
            'Participant Name',
            'Role',
            'Source',
            'Age',
            'Fitness Sit-Ups',
            'Fitness Jumping Jacks',
            'Fitness Push-Ups',
            'Fitness Tested On',
            'Fitness Result',
            'Proficiency Duration (s)',
            'Proficiency Tested On',
            'Proficiency Result',
        ];
        foreach ($checkpointColumns as $checkpointCode) {
            $headerRow[] = $checkpointCode.' Checkpoint';
        }
        $rows[] = $headerRow;

        $shiftGroups = is_array($payload['shiftGroups'] ?? null) ? $payload['shiftGroups'] : [];
        if (! $shiftGroups) {
            $rows[] = ['No grouped participants found for this report.'];

            return $rows;
        }

        foreach ($shiftGroups as $group) {
            if (! is_array($group)) {
                continue;
            }

            $groupId = trim((string) ($group['id'] ?? ''));
            $shiftName = trim((string) ($group['shiftName'] ?? ''));
            $teamName = trim((string) ($group['teamName'] ?? ''));
            $assessor = is_array($group['assessor'] ?? null) ? $group['assessor'] : [];
            $assessorName = trim((string) ($assessor['name'] ?? ''));
            $participants = is_array($group['participants'] ?? null) ? $group['participants'] : [];

            if (! $participants) {
                $rows[] = [
                    $groupId,
                    $shiftName,
                    $teamName,
                    $assessorName,
                    '',
                    'No participants in this shift group.',
                ];

                continue;
            }

            foreach ($participants as $participant) {
                if (! is_array($participant)) {
                    continue;
                }

                $fitness = is_array($participant['fitness'] ?? null) ? $participant['fitness'] : [];
                $proficiency = is_array($participant['proficiency'] ?? null) ? $participant['proficiency'] : [];
                $checkpointRows = is_array($proficiency['checkpoints'] ?? null) ? $proficiency['checkpoints'] : [];
                $this->sortCheckpointsInCpOrder($checkpointRows);

                $checkpointValues = [];
                foreach ($checkpointRows as $checkpointRow) {
                    if (! is_array($checkpointRow)) {
                        continue;
                    }
                    $checkpointCode = strtoupper(trim((string) ($checkpointRow['checkpointCode'] ?? '')));
                    if ($checkpointCode === '') {
                        continue;
                    }
                    $checkpointValues[$checkpointCode] = $this->formatCheckpointResult($checkpointRow);
                }

                $row = [
                    $groupId,
                    $shiftName,
                    $teamName,
                    $assessorName,
                    trim((string) ($participant['id'] ?? '')),
                    trim((string) ($participant['name'] ?? '')),
                    trim((string) ($participant['role'] ?? '')),
                    trim((string) ($participant['source'] ?? '')),
                    (string) ($participant['ageSnapshot'] ?? ''),
                    (string) ($fitness['sitUps'] ?? ''),
                    (string) ($fitness['jumpingJacks'] ?? ''),
                    (string) ($fitness['pushUps'] ?? ''),
                    trim((string) ($fitness['testedOn'] ?? '')),
                    trim((string) ($fitness['result'] ?? '')),
                    (string) ($proficiency['durationSeconds'] ?? ''),
                    trim((string) ($proficiency['testedOn'] ?? '')),
                    trim((string) ($proficiency['result'] ?? '')),
                ];

                foreach ($checkpointColumns as $checkpointCode) {
                    $row[] = $checkpointValues[$checkpointCode] ?? '';
                }

                $rows[] = $row;
            }
        }

        return $rows;
    }

    private function collectCheckpointColumns(array $payload): array
    {
        $columns = [];
        $seen = [];
        $groups = is_array($payload['shiftGroups'] ?? null) ? $payload['shiftGroups'] : [];

        foreach ($groups as $group) {
            if (! is_array($group)) {
                continue;
            }

            $participants = is_array($group['participants'] ?? null) ? $group['participants'] : [];
            foreach ($participants as $participant) {
                if (! is_array($participant)) {
                    continue;
                }
                $proficiency = is_array($participant['proficiency'] ?? null) ? $participant['proficiency'] : [];
                $checkpointRows = is_array($proficiency['checkpoints'] ?? null) ? $proficiency['checkpoints'] : [];
                $this->sortCheckpointsInCpOrder($checkpointRows);
                foreach ($checkpointRows as $checkpointRow) {
                    if (! is_array($checkpointRow)) {
                        continue;
                    }
                    $checkpointCode = strtoupper(trim((string) ($checkpointRow['checkpointCode'] ?? '')));
                    if ($checkpointCode === '' || isset($seen[$checkpointCode])) {
                        continue;
                    }
                    $seen[$checkpointCode] = true;
                    $columns[] = $checkpointCode;
                }
            }
        }

        if (empty($columns)) {
            return [];
        }

        usort($columns, function (string $left, string $right): int {
            $leftOrder = $this->checkpointOrder($left);
            $rightOrder = $this->checkpointOrder($right);
            if ($leftOrder === $rightOrder) {
                return strcasecmp($left, $right);
            }

            return $leftOrder <=> $rightOrder;
        });

        return $columns;
    }

    private function formatCheckpointResult(array $checkpointRow): string
    {
        $checkpointCode = strtoupper(trim((string) ($checkpointRow['checkpointCode'] ?? '')));
        if ($checkpointCode === '') {
            return '';
        }

        $completed = (bool) ($checkpointRow['completed'] ?? false);
        $durationSeconds = trim((string) ($checkpointRow['durationSeconds'] ?? ''));
        $attempts = trim((string) ($checkpointRow['attempts'] ?? ''));

        $text = $completed ? 'Completed' : 'Missed';
        if ($durationSeconds !== '') {
            $text .= " ({$durationSeconds}s)";
        }
        if ($attempts !== '') {
            $text .= " [{$attempts} attempts]";
        }

        return $text;
    }

    private function sortCheckpointsInCpOrder(array &$checkpoints): void
    {
        usort($checkpoints, function (array $left, array $right): int {
            $leftOrder = $this->checkpointOrder((string) ($left['checkpointCode'] ?? ''));
            $rightOrder = $this->checkpointOrder((string) ($right['checkpointCode'] ?? ''));

            if ($leftOrder === $rightOrder) {
                $leftCode = strtoupper(trim((string) ($left['checkpointCode'] ?? '')));
                $rightCode = strtoupper(trim((string) ($right['checkpointCode'] ?? '')));

                return strcasecmp($leftCode, $rightCode);
            }

            return $leftOrder <=> $rightOrder;
        });
    }

    private function checkpointOrder(string $checkpointCode): int
    {
        $normalized = trim($checkpointCode);
        if (preg_match('/^(?:cp)?(\\d{1,3})/i', $normalized, $match)) {
            return (int) $match[1];
        }
        if (preg_match('/(\\d{1,3})$/', $normalized, $match)) {
            return (int) $match[1];
        }

        return 9999;
    }

    private function stringCell(string $cellRef, string $value): string
    {
        $escaped = $this->escapeXml($value);
        if ($escaped === '') {
            return '<c r="'.$cellRef.'"/>';
        }

        return '<c r="'.$cellRef.'" t="inlineStr"><is><t>'.$escaped.'</t></is></c>';
    }

    private function columnName(int $columnIndex): string
    {
        $name = '';
        $index = $columnIndex;
        do {
            $name = chr(($index % 26) + 65).$name;
            $index = intdiv($index, 26) - 1;
        } while ($index >= 0);

        return $name;
    }

    private function escapeXml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
