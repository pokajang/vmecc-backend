<?php

namespace App\Services\InspectionReports;

readonly class InspectionReportPhotoSanitizationResult
{
    public function __construct(
        public array $record,
        public int $imageCount,
        public int $unavailableImageCount,
        public int $omittedImageCount,
        public int $totalImageBytes,
    ) {}
}
