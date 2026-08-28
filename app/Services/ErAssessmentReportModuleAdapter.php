<?php

namespace App\Services;

use App\Models\Report;

final class ErAssessmentReportModuleAdapter implements ReportModuleAdapter
{
    public function __construct(
        private readonly ErAssessmentPayloadService $payloadService,
        private readonly ReportRevisionService $revisionService,
    ) {}

    public function validateDraft(array $payload): array
    {
        return $this->payloadService->validateForDraft($payload);
    }

    public function validateSubmission(array $payload): array
    {
        return $this->payloadService->validateForSubmit($payload);
    }

    public function project(Report $report, array $payload): void
    {
        $this->revisionService->snapshot($report, $payload);
    }

    public function serialize(Report $report): array
    {
        return is_array($report->payload) ? $report->payload : [];
    }

    public function serializeForLegacyReads(Report $report): array
    {
        return $this->serialize($report);
    }

    public function generateExport(Report $report, string $format): ?array
    {
        return null;
    }
}
