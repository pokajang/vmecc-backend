<?php

namespace App\Services;

use App\Models\Report;

final class ReportModuleNoopAdapter implements ReportModuleAdapter
{
    public function validateDraft(array $payload): array
    {
        return $payload;
    }

    public function validateSubmission(array $payload): array
    {
        return $payload;
    }

    public function project(Report $report, array $payload): void
    {
        // Reserved for module-specific projection in future migrations.
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
