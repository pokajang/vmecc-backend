<?php

namespace App\Services;

use App\Models\Report;
use App\Models\User;

class InspectionWorkflowService
{
    private const MODULE_KEY = 'inspection';

    public function __construct(private readonly ReportingWorkflowService $reportingWorkflowService) {}

    public function loadWorkflowRules(): array
    {
        return $this->reportingWorkflowService->loadModuleWorkflowRules(self::MODULE_KEY);
    }

    public function saveWorkflowRules(array $rules): void
    {
        $this->reportingWorkflowService->saveModuleWorkflowRules(self::MODULE_KEY, $rules);
    }

    public function normalizeWorkflowRules(mixed $value): array
    {
        $normalized = $this->reportingWorkflowService->normalizeWorkflowRules([
            'modules' => [
                self::MODULE_KEY => is_array($value) ? $value : [],
            ],
        ]);

        return $normalized['modules'][self::MODULE_KEY];
    }

    public function buildWorkflowForSubmission(User $submitter): array
    {
        return $this->reportingWorkflowService->buildWorkflowForSubmission($submitter, self::MODULE_KEY);
    }

    public function submissionBlockReason(User $submitter): ?string
    {
        return $this->reportingWorkflowService->submissionBlockReason($submitter, self::MODULE_KEY);
    }

    public function draftWorkflowFields(): array
    {
        return $this->reportingWorkflowService->draftWorkflowFields();
    }

    public function effectiveWorkflow(Report $report): array
    {
        return $this->reportingWorkflowService->effectiveWorkflow($report);
    }

    public function canReview(Report $report, User $actor): bool
    {
        return $this->reportingWorkflowService->canReview($report, $actor);
    }

    public function canApprove(Report $report, User $actor): bool
    {
        return $this->reportingWorkflowService->canApprove($report, $actor);
    }

    public function canReject(Report $report, User $actor): bool
    {
        return $this->reportingWorkflowService->canReject($report, $actor);
    }

    public function authorizeAction(Report $report, User $actor, string $action): ?string
    {
        return $this->reportingWorkflowService->authorizeAction($report, $actor, $action);
    }

    public function advanceWorkflow(Report $report, string $action, User $actor, ?string $remarks = null): array
    {
        return $this->reportingWorkflowService->advanceWorkflow($report, $action, $actor, $remarks);
    }

    public function appendSubmissionHistory(array $workflowFields, User $actor, string $action, ?string $remarks = null): array
    {
        return $this->reportingWorkflowService->appendSubmissionHistory($workflowFields, $actor, $action, $remarks);
    }

    public function recipientUserIdsForNextAction(Report $report): array
    {
        return $this->reportingWorkflowService->recipientUserIdsForNextAction($report);
    }

    public function approveRole(Report $report): string
    {
        return $this->reportingWorkflowService->approveRole($report);
    }
}
