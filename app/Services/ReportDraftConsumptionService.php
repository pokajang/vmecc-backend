<?php

namespace App\Services;

use App\Models\ReportDraft;

final class ReportDraftConsumptionService
{
    public function __construct(private readonly ReportMediaService $reportMediaService) {}

    public function consumeOwnedDraft(
        int $userId,
        string $draftId,
        string $reportType,
    ): ?string {
        $normalizedDraftId = trim($draftId);
        $normalizedReportType = strtolower(trim($reportType));
        if ($userId <= 0 || $normalizedDraftId === '' || $normalizedReportType === '') {
            return null;
        }

        $draft = ReportDraft::query()
            ->where('user_id', $userId)
            ->where('draft_id', $normalizedDraftId)
            ->where('report_type', $normalizedReportType)
            ->lockForUpdate()
            ->first();
        if (! $draft) {
            return null;
        }

        $this->reportMediaService->removeParentLinks(
            'report_draft',
            (string) $draft->draft_id,
        );
        $draft->delete();

        return (string) $draft->draft_id;
    }
}
