<?php

namespace App\Services\InspectionFireExtinguishers;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Shared\Converter;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\SimpleType\VerticalJc;
use RuntimeException;

class FireExtinguisherExceptionDocxRenderer
{
    /** @param array<string, mixed> $data */
    public function render(array $data): string
    {
        $startedAt = microtime(true);
        $phpWord = new PhpWord;
        $phpWord->setDefaultFontName('Aptos');
        $phpWord->setDefaultFontSize(9);
        $this->registerStyles($phpWord);
        $section = $phpWord->addSection([
            'paperSize' => 'A4',
            'marginTop' => Converter::cmToTwip(1.4),
            'marginRight' => Converter::cmToTwip(1.4),
            'marginBottom' => Converter::cmToTwip(1.8),
            'marginLeft' => Converter::cmToTwip(1.4),
        ]);
        $footer = $section->addFooter();
        $footer->addPreserveText(
            trim((string) ($data['title'] ?? 'Fire Extinguisher Exception Report')).' | Page {PAGE} of {NUMPAGES}',
            ['size' => 7, 'color' => '6B7280'],
            ['alignment' => Jc::CENTER],
        );

        $section->addTitle((string) ($data['title'] ?? 'Fire Extinguisher Exception Report'), 1);
        $section->addText(
            'Generated '.($data['generatedAtDisplay'] ?? '').' by '.($data['generatedBy'] ?? ''),
            'Muted',
        );
        $section->addText('Certification status as of '.($data['asOfDateDisplay'] ?? '').'.', 'Muted');
        $this->addSummary($section, (array) ($data['summary'] ?? []));

        $filters = is_array($data['appliedFilters'] ?? null) ? $data['appliedFilters'] : [];
        if ($filters !== []) {
            $section->addText('Applied filters', 'Heading2');
            $section->addText(implode(' | ', array_map(
                fn (array $filter): string => (string) ($filter['label'] ?? ''),
                $filters,
            )), 'Muted');
        }

        $layoutMode = in_array(($data['layoutMode'] ?? ''), ['issues', 'expired', 'combined'], true)
            ? (string) $data['layoutMode']
            : 'issues';
        $items = array_values(array_filter((array) ($data['items'] ?? []), 'is_array'));
        if ($layoutMode === 'issues') {
            $this->addIssueItems($section, $items);
        } else {
            $this->addRegister($section, $items, $layoutMode === 'combined');
        }

        $temporaryPath = tempnam(sys_get_temp_dir(), 'fe-exception-');
        if ($temporaryPath === false) {
            throw new RuntimeException('Unable to create a temporary DOCX file.');
        }
        $docxPath = $temporaryPath.'.docx';
        @unlink($temporaryPath);

        try {
            IOFactory::createWriter($phpWord, 'Word2007')->save($docxPath);
            $output = file_get_contents($docxPath);
            if (! is_string($output) || $output === '') {
                throw new RuntimeException('The generated DOCX is empty.');
            }
        } finally {
            @unlink($docxPath);
        }

        Log::info('fire_extinguisher_exception_docx_rendered', [
            'record_count' => (int) data_get($data, 'summary.total', 0),
            'output_bytes' => strlen($output),
            'image_count' => (int) data_get($data, 'renderMeta.imageCount', 0),
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);

        return $output;
    }

    private function registerStyles(PhpWord $phpWord): void
    {
        $phpWord->addTitleStyle(1, ['size' => 16, 'bold' => true, 'color' => '0B948F'], ['spaceAfter' => 80]);
        $phpWord->addTitleStyle(2, ['size' => 12, 'bold' => true, 'color' => '0B948F'], ['spaceBefore' => 160, 'spaceAfter' => 40, 'keepNext' => true]);
        $phpWord->addTitleStyle(3, ['size' => 10, 'bold' => true, 'color' => '374151'], ['spaceBefore' => 100, 'spaceAfter' => 30, 'keepNext' => true]);
        $phpWord->addFontStyle('Heading2', ['size' => 10, 'bold' => true, 'color' => '374151']);
        $phpWord->addFontStyle('Muted', ['size' => 8, 'color' => '6B7280']);
        $phpWord->addFontStyle('Issue', ['size' => 8, 'bold' => true, 'color' => 'B42318']);
        $phpWord->addFontStyle('Expired', ['size' => 8, 'bold' => true, 'color' => 'B42318']);
        $phpWord->addTableStyle('SummaryTable', [
            'borderColor' => 'D1D5DB', 'borderSize' => 4, 'cellMargin' => 70,
        ], ['bgColor' => 'F3F4F6']);
        $phpWord->addTableStyle('ItemTable', [
            'borderColor' => 'D1D5DB', 'borderSize' => 4, 'cellMargin' => 80,
            'cantSplit' => false,
        ]);
        $phpWord->addTableStyle('ExceptionRegister', [
            'borderColor' => 'D1D5DB', 'borderSize' => 4, 'cellMargin' => 45,
            'cantSplit' => false, 'layout' => 'fixed',
        ]);
    }

    private function addSummary($section, array $summary): void
    {
        $table = $section->addTable('SummaryTable');
        $table->addRow(null, ['cantSplit' => true]);
        foreach (['Unique', 'Issues', 'Expired', 'Both'] as $label) {
            $table->addCell(2200, ['valign' => VerticalJc::CENTER])->addText($label, ['bold' => true, 'size' => 8]);
        }
        $table->addRow(null, ['cantSplit' => true]);
        foreach (['total', 'issues', 'expired', 'overlap'] as $key) {
            $table->addCell(2200)->addText((string) ((int) ($summary[$key] ?? 0)), ['bold' => true, 'size' => 11, 'color' => '111827']);
        }
    }

    /** @param array<int, array<string, mixed>> $items */
    private function addIssueItems($section, array $items): void
    {
        $lastZone = null;
        $lastLocation = null;
        foreach ($items as $item) {
            $zone = trim((string) ($item['zone'] ?? '')) ?: 'Unspecified zone';
            $location = trim((string) ($item['location'] ?? '')) ?: 'Unspecified location';
            if ($zone !== $lastZone) {
                $section->addTitle($zone, 2);
                $lastZone = $zone;
                $lastLocation = null;
            }
            if ($location !== $lastLocation) {
                $section->addTitle($location, 3);
                $lastLocation = $location;
            }
            $this->addItem($section, $item);
        }
    }

    /** @param array<int, array<string, mixed>> $items */
    private function addRegister($section, array $items, bool $combined): void
    {
        $table = $section->addTable('ExceptionRegister');
        $widths = [400, 750, 900, 1050, 900, 700, 1500, 1000, 650, 1150];
        $headers = [
            '#',
            'Zone',
            'Location',
            $combined ? 'ID Loc No. / Status' : 'ID Loc No.',
            'Sub-location',
            'FE type',
            'Barcode / S/N',
            'Certification validity',
            'Days expired',
            'Latest inspection / Inspector',
        ];
        $header = $table->addRow(null, ['tblHeader' => true, 'cantSplit' => true]);
        foreach ($headers as $index => $label) {
            $header->addCell($widths[$index], [
                'bgColor' => 'E5E7EB',
                'valign' => VerticalJc::CENTER,
            ])->addText($label, ['bold' => true, 'size' => 7, 'color' => '374151'], ['spaceAfter' => 0]);
        }

        foreach ($items as $index => $item) {
            $keepWithFinding = $combined && ($item['isIssue'] ?? false);
            $row = $table->addRow(null, ['cantSplit' => true]);
            $this->addRegisterTextCell($row, $widths[0], (string) ($index + 1), ['color' => '6B7280'], Jc::CENTER, $keepWithFinding);
            $this->addRegisterTextCell($row, $widths[1], trim((string) ($item['zone'] ?? '')) ?: '-', keepWithNext: $keepWithFinding);
            $this->addRegisterTextCell($row, $widths[2], trim((string) ($item['location'] ?? '')) ?: '-', keepWithNext: $keepWithFinding);

            $idCell = $row->addCell($widths[3], ['valign' => VerticalJc::TOP]);
            $idCell->addText(
                trim((string) ($item['idLocNo'] ?? '')) ?: '-',
                ['bold' => true, 'size' => 7.5],
                ['spaceAfter' => 0, 'keepNext' => $keepWithFinding],
            );
            if ($combined) {
                $status = [];
                if ($item['isExpired'] ?? false) {
                    $status[] = 'EXPIRED';
                }
                if ($item['isIssue'] ?? false) {
                    $status[] = 'ISSUE';
                }
                $idCell->addText(implode(' | ', $status), 'Issue', ['spaceAfter' => 0, 'keepNext' => $keepWithFinding]);
            }

            $this->addRegisterTextCell($row, $widths[4], trim((string) ($item['subLocation'] ?? '')) ?: '-', keepWithNext: $keepWithFinding);
            $this->addRegisterTextCell($row, $widths[5], trim((string) ($item['feType'] ?? '')) ?: '-', keepWithNext: $keepWithFinding);
            $this->addRegisterTextCell($row, $widths[6], trim((string) ($item['barcodeNo'] ?? '')) ?: '-', keepWithNext: $keepWithFinding);
            $this->addRegisterTextCell($row, $widths[7], trim((string) ($item['certificationValidity'] ?? '')) ?: '-', keepWithNext: $keepWithFinding);
            $this->addRegisterTextCell(
                $row,
                $widths[8],
                ($item['isExpired'] ?? false) ? (string) ((int) ($item['daysExpired'] ?? 0)) : '-',
                [],
                Jc::CENTER,
                $keepWithFinding,
            );

            $inspectionCell = $row->addCell($widths[9], ['valign' => VerticalJc::TOP]);
            $inspectionCell->addText(
                $this->formatDate($item['latestInspectionAt'] ?? ''),
                ['size' => 7.5],
                ['spaceAfter' => 0, 'keepNext' => $keepWithFinding],
            );
            $inspectionCell->addText(
                trim((string) ($item['inspectedBy'] ?? '')) ?: '-',
                ['size' => 7, 'color' => '6B7280'],
                ['spaceAfter' => 0, 'keepNext' => $keepWithFinding],
            );

            if ($combined && ($item['isIssue'] ?? false)) {
                $defects = array_values(array_filter((array) ($item['defects'] ?? []), 'is_array'));
                if ($defects === []) {
                    $cell = $table->addRow(null, ['cantSplit' => true])->addCell(9000, ['gridSpan' => 10, 'bgColor' => 'F8FAFC']);
                    $cell->addText('Issue details are unavailable.', ['size' => 8, 'italic' => true, 'color' => '6B7280']);
                } else {
                    foreach ($defects as $check) {
                        $this->addDefectRows($table, $check, 10);
                    }
                }
            }
        }
    }

    private function addRegisterTextCell(
        $row,
        int $width,
        string $value,
        array $font = [],
        ?string $alignment = null,
        bool $keepWithNext = false,
    ): void {
        $row->addCell($width, ['valign' => VerticalJc::TOP])->addText(
            $value,
            ['size' => 7.5, ...$font],
            ['spaceAfter' => 0, 'keepNext' => $keepWithNext, ...($alignment ? ['alignment' => $alignment] : [])],
        );
    }

    /** @param array<string, mixed> $check */
    private function addDefectRows($table, array $check, int $gridSpan): void
    {
        $photos = $this->preparePhotos((array) ($check['photos'] ?? []));
        $cell = $table->addRow(null, ['cantSplit' => true])->addCell(9000, [
            'gridSpan' => $gridSpan,
            'bgColor' => 'F8FAFC',
        ]);
        $run = $cell->addTextRun(['keepNext' => true, 'spaceAfter' => 0]);
        $run->addText((string) ($check['label'] ?? 'Inspection check'), ['bold' => true, 'size' => 9]);
        $run->addText('  ISSUE', 'Issue');
        $remarks = trim((string) ($check['remarks'] ?? ''));
        $cell->addText(
            $remarks !== '' ? $remarks : 'No finding description provided.',
            ['size' => 8, 'color' => '374151'],
            ['keepNext' => $photos !== []],
        );
        $this->addPhotoRows($table, $photos, $gridSpan);
    }

    private function addItem($section, array $item): void
    {
        $defects = array_values(array_filter((array) ($item['defects'] ?? []), 'is_array'));
        $table = $section->addTable('ItemTable');
        $header = $table->addRow(null, ['cantSplit' => true]);
        $titleCell = $header->addCell(6800, ['bgColor' => 'F3F4F6']);
        $titleCell->addText(
            trim((string) ($item['idLocNo'] ?? '')) ?: 'Unnumbered extinguisher',
            ['bold' => true, 'size' => 10],
            ['keepNext' => true],
        );
        $badgeCell = $header->addCell(2200, ['bgColor' => 'F3F4F6', 'valign' => VerticalJc::CENTER]);
        $badges = [];
        if ($item['isIssue'] ?? false) {
            $badges[] = 'ISSUE';
        }
        if ($item['isExpired'] ?? false) {
            $badges[] = 'EXPIRED';
        }
        $badgeCell->addText(implode(' | ', $badges), 'Issue', ['alignment' => Jc::END, 'keepNext' => true]);

        $meta = $table->addRow(null, ['cantSplit' => true])->addCell(9000, ['gridSpan' => 2]);
        $metaLines = [
            'Sub-location: '.($item['subLocation'] ?: '-'),
            'FE type: '.($item['feType'] ?: '-'),
            'Barcode / S/N: '.($item['barcodeNo'] ?: '-'),
            'Certification validity: '.($item['certificationValidity'] ?: '-'),
            'Latest inspection: '.($this->formatDate($item['latestInspectionAt'] ?? '').' | '.($item['inspectedBy'] ?: '-')),
        ];
        if ($item['isExpired'] ?? false) {
            $metaLines[] = 'Days expired: '.(int) ($item['daysExpired'] ?? 0);
        }
        $lastMetaIndex = count($metaLines) - 1;
        foreach ($metaLines as $index => $line) {
            $meta->addText($line, ['size' => 8, 'color' => '374151'], [
                'spaceAfter' => 0,
                'keepNext' => $defects !== [] && $index === $lastMetaIndex,
            ]);
        }

        foreach ($defects as $check) {
            $this->addDefectRows($table, $check, 2);
        }
        $section->addTextBreak(1);
    }

    /**
     * @param  array<int, array{binary: string|null, description: string, unavailable: bool}>  $photos
     */
    private function addPhotoRows($table, array $photos, int $gridSpan): void
    {
        foreach (array_chunk($photos, 2) as $photoPair) {
            $cell = $table->addRow(null, ['cantSplit' => true])->addCell(9000, [
                'gridSpan' => $gridSpan,
                'bgColor' => 'F8FAFC',
            ]);
            $photoTable = $cell->addTable([
                'borderSize' => 0,
                'cellMargin' => 45,
                'layout' => 'fixed',
            ]);
            $photoRow = $photoTable->addRow(null, ['cantSplit' => true]);
            foreach ($photoPair as $photo) {
                $photoCell = $photoRow->addCell(4500, ['valign' => VerticalJc::CENTER]);
                if ($photo['unavailable']) {
                    $photoCell->addText(
                        'Image unavailable',
                        ['size' => 8, 'italic' => true, 'color' => '6B7280'],
                        ['alignment' => Jc::CENTER],
                    );
                } elseif ($photo['binary'] !== null) {
                    [$width, $height] = $this->containedImageSize($photo['binary'], 205, 150);
                    $photoCell->addImage($photo['binary'], [
                        'width' => $width,
                        'height' => $height,
                        'alignment' => Jc::CENTER,
                    ]);
                }
                if ($photo['description'] !== '') {
                    $photoCell->addText(
                        $photo['description'],
                        ['size' => 7, 'color' => '6B7280'],
                        ['alignment' => Jc::CENTER],
                    );
                }
            }
            if (count($photoPair) === 1) {
                $photoRow->addCell(4500);
            }
        }
    }

    /** @return array<int, array{binary: string|null, description: string, unavailable: bool}> */
    private function preparePhotos(array $photos): array
    {
        $prepared = [];
        foreach ($photos as $photo) {
            if (! is_array($photo)) {
                continue;
            }
            $binary = $this->imageBinary((string) ($photo['url'] ?? ''));
            $unavailable = ($photo['imageUnavailable'] ?? false) === true;
            if ($binary === null && ! $unavailable) {
                continue;
            }
            $prepared[] = [
                'binary' => $binary,
                'description' => trim((string) ($photo['description'] ?? '')),
                'unavailable' => $unavailable,
            ];
        }

        return $prepared;
    }

    private function imageBinary(string $dataUri): ?string
    {
        if (preg_match('/^data:image\/(?:jpeg|jpg|png|webp);base64,(.+)$/is', trim($dataUri), $match) !== 1) {
            return null;
        }
        $binary = base64_decode($match[1], true);
        if (! is_string($binary) || $binary === '') {
            return null;
        }
        $info = @getimagesizefromstring($binary);
        if (($info['mime'] ?? '') !== 'image/webp') {
            return $binary;
        }
        $image = @imagecreatefromstring($binary);
        if ($image === false) {
            return null;
        }
        ob_start();
        imagepng($image);
        imagedestroy($image);
        $converted = ob_get_clean();

        return is_string($converted) && $converted !== '' ? $converted : null;
    }

    /** @return array{0: int, 1: int} */
    private function containedImageSize(string $binary, int $maxWidth = 240, int $maxHeight = 180): array
    {
        $info = @getimagesizefromstring($binary);
        $width = max(1, (int) ($info[0] ?? 1));
        $height = max(1, (int) ($info[1] ?? 1));
        $scale = min($maxWidth / $width, $maxHeight / $height, 1);

        return [max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale))];
    }

    private function formatDate(mixed $value): string
    {
        try {
            return trim((string) $value) !== '' ? Carbon::parse((string) $value)->format('d M Y, H:i') : '-';
        } catch (\Throwable) {
            return '-';
        }
    }
}
