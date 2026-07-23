<?php

namespace App\Services;

use App\Models\Report;

final class FitnessTestReportModuleAdapter implements ReportModuleAdapter
{
    public function __construct(
        private readonly FitnessTestPayloadService $fitnessTestPayloadService,
        private readonly FitnessTestResultCalculator $resultCalculator,
        private readonly ReportRevisionService $reportRevisionService,
        private readonly FitnessTestProjectionService $projectionService,
        private readonly FitnessTestReportViewBuilder $viewBuilder,
    ) {}

    public function validateDraft(array $payload): array
    {
        $payload = $this->fitnessTestPayloadService->normalizeForProjection($payload);
        $this->fitnessTestPayloadService->validateForDraft($payload);

        return $payload;
    }

    public function validateSubmission(array $payload): array
    {
        $payload = $this->fitnessTestPayloadService->normalizeForProjection($payload);
        $payload = $this->resultCalculator->calculate($payload);
        $this->fitnessTestPayloadService->validateForSubmit($payload);

        return $payload;
    }

    public function project(Report $report, array $payload): void
    {
        $normalizedPayload = $this->fitnessTestPayloadService->normalizeForProjection($payload);
        $normalizedPayload = $this->resultCalculator->calculate($normalizedPayload);
        $this->reportRevisionService->snapshot($report, $normalizedPayload);
        $this->projectionService->project($report, $normalizedPayload);
    }

    public function serialize(Report $report): array
    {
        return $this->viewBuilder->buildView($report);
    }

    public function serializeForLegacyReads(Report $report): array
    {
        return $this->viewBuilder->buildPayloadView($report);
    }

    public function generateExport(Report $report, string $format): ?array
    {
        return $this->viewBuilder->buildExportPayload($report, $format);
    }
}
