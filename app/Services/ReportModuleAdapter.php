<?php

namespace App\Services;

use App\Models\Report;

interface ReportModuleAdapter
{
    /**
     * @return array<string, mixed>
     */
    public function validateDraft(array $payload): array;

    /**
     * @return array<string, mixed>
     */
    public function validateSubmission(array $payload): array;

    public function project(Report $report, array $payload): void;

    /**
     * @return array<string, mixed>
     */
    public function serialize(Report $report): array;

    /**
     * @return array<string, mixed>
     */
    public function serializeForLegacyReads(Report $report): array;

    /**
     * @return array<string, mixed>|null
     */
    public function generateExport(Report $report, string $format): ?array;
}
