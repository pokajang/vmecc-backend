<?php

namespace App\Services;

use App\Models\FitnessTestReport;
use App\Models\FitnessTestShiftGroup;
use App\Models\Report;

final class FitnessTestReportViewBuilder
{
    public function __construct(
        private readonly FitnessTestPayloadService $fitnessTestPayloadService,
    ) {}

    public function buildView(Report $report): array
    {
        $payload = is_array($report->payload)
            ? $this->fitnessTestPayloadService->normalizeForProjection((array) $report->payload)
            : [];

        $fitnessReport = FitnessTestReport::query()
            ->where('report_id', (int) $report->id)
            ->with([
                'shiftGroups.assessor:id,name',
                'shiftGroups.team:id,name',
                'shiftGroups.participants.user:id,name',
                'shiftGroups.participants.checkpointResults',
            ])
            ->first();
        if (! $fitnessReport instanceof FitnessTestReport) {
            return $this->buildPayloadView($payload, $report);
        }

        return $this->buildFromProjection($payload, $report, $fitnessReport);
    }

    public function buildPayloadView(Report $report): array
    {
        $payload = is_array($report->payload)
            ? $this->fitnessTestPayloadService->normalizeForProjection((array) $report->payload)
            : [];

        return $this->buildPayloadViewFromPayload($payload, $report);
    }

    public function buildPayloadViewFromPayload(array $payload, Report $report): array
    {
        return $this->buildPayloadViewInternal($payload, $report);
    }

    public function buildExportPayload(Report $report, string $format): ?array
    {
        if (strtolower(trim($format)) !== 'json') {
            return null;
        }

        return array_merge($this->buildView($report), [
            'exportedAt' => now()->toIso8601String(),
            'exportFormat' => 'json',
        ]);
    }

    private function buildFromProjection(array $payload, Report $report, FitnessTestReport $fitnessReport): array
    {
        return array_merge($payload, $this->buildReportEnvelope($report), [
            'reportingMonth' => $fitnessReport->reporting_month,
            'documentReference' => $fitnessReport->document_reference,
            'protocolRevision' => $fitnessReport->protocol_revision,
            'participantCount' => (int) $fitnessReport->participant_count,
            'passedAssessmentCount' => (int) $fitnessReport->passed_assessment_count,
            'failedAssessmentCount' => (int) $fitnessReport->failed_assessment_count,
            'incompleteAssessmentCount' => (int) $fitnessReport->incomplete_assessment_count,
            'completionStatistics' => [
                'participantCount' => (int) $fitnessReport->participant_count,
                'passedAssessmentCount' => (int) $fitnessReport->passed_assessment_count,
                'failedAssessmentCount' => (int) $fitnessReport->failed_assessment_count,
                'incompleteAssessmentCount' => (int) $fitnessReport->incomplete_assessment_count,
            ],
            'signoff' => $this->buildSignoff($report),
            'shiftGroups' => $this->buildShiftGroups($fitnessReport->shiftGroups->values()->all()),
            'groupCount' => count($fitnessReport->shiftGroups),
            'groupOrderingStable' => true,
            'participantOrderingStable' => $this->hasParticipants($fitnessReport->shiftGroups->values()->all()),
        ]);
    }

    private function buildPayloadViewInternal(array $payload, Report $report): array
    {
        $normalizedPayload = $this->normalizeFallbackPayloadForExport($payload);
        $statistics = $this->calculateCompletionStatistics($normalizedPayload);

        return array_merge($normalizedPayload, $this->buildReportEnvelope($report), [
            'signoff' => $this->buildSignoff($report),
            'completionStatistics' => $statistics,
            'participantCount' => (int) $statistics['participantCount'],
            'passedAssessmentCount' => (int) $statistics['passedAssessmentCount'],
            'failedAssessmentCount' => (int) $statistics['failedAssessmentCount'],
            'incompleteAssessmentCount' => (int) $statistics['incompleteAssessmentCount'],
            'groupCount' => $this->countGroups($payload),
            'groupOrderingStable' => true,
            'participantOrderingStable' => (bool) $statistics['participantCount'],
        ]);
    }

    private function buildSignoff(Report $report): array
    {
        return [
            'submittedAt' => optional($report->submitted_at)->toIso8601String(),
            'reviewedAt' => optional($report->reviewed_at)->toIso8601String(),
            'approvedAt' => optional($report->approved_at)->toIso8601String(),
            'rejectedAt' => optional($report->rejected_at)->toIso8601String(),
            'ownerUserId' => $report->owner_user_id !== null ? (int) $report->owner_user_id : null,
        ];
    }

    private function buildReportEnvelope(Report $report): array
    {
        return [
            'id' => (string) $report->report_uid,
            'displayId' => (string) $report->display_id,
            'reportType' => 'fitness-test',
            'status' => (string) $report->status,
            'version' => (int) $report->version,
            'revision' => (int) $report->revision,
            'workflowStatus' => strtolower(trim((string) $report->status)),
            'ownerUserId' => $report->owner_user_id !== null ? (int) $report->owner_user_id : null,
            'submittedAt' => optional($report->submitted_at)->toIso8601String(),
            'reviewedAt' => optional($report->reviewed_at)->toIso8601String(),
            'approvedAt' => optional($report->approved_at)->toIso8601String(),
            'rejectedAt' => optional($report->rejected_at)->toIso8601String(),
        ];
    }

    private function normalizeFallbackPayloadForExport(array $payload): array
    {
        $shiftGroups = is_array($payload['shiftGroups'] ?? null) ? $payload['shiftGroups'] : [];
        if (empty($shiftGroups)) {
            return $payload;
        }

        $normalizedGroups = [];
        foreach ($shiftGroups as $groupIndex => $group) {
            if (! is_array($group)) {
                continue;
            }

            $normalizedParticipants = [];
            $participants = is_array($group['participants'] ?? null) ? $group['participants'] : [];
            foreach ($participants as $participant) {
                if (! is_array($participant)) {
                    continue;
                }

                $normalizedParticipant = $participant;
                $fitness = is_array($normalizedParticipant['fitness'] ?? null) ? $normalizedParticipant['fitness'] : [];
                $proficiency = is_array($normalizedParticipant['proficiency'] ?? null) ? $normalizedParticipant['proficiency'] : [];
                $checkpoints = is_array($fitness['checkpoints'] ?? null) ? $fitness['checkpoints'] : (
                    is_array($fitness['checkpointResults'] ?? null)
                        ? $fitness['checkpointResults']
                        : (is_array($proficiency['checkpoints'] ?? null)
                            ? $proficiency['checkpoints']
                            : (is_array($proficiency['checkpointResults'] ?? null) ? $proficiency['checkpointResults'] : []))
                );
                if (! empty($checkpoints)) {
                    $this->sortCheckpointsInCpOrder($checkpoints);
                    $fitness['checkpoints'] = array_values($checkpoints);
                    $normalizedParticipant['fitness'] = $fitness;
                    $proficiency['checkpoints'] = array_values($checkpoints);
                    $normalizedParticipant['proficiency'] = $proficiency;
                }
                $normalizedParticipant['assessmentStatus'] = $this->combineAssessmentStatus(
                    $this->normalizeResultText((string) ($fitness['result'] ?? '')),
                    $this->normalizeResultText((string) ($proficiency['result'] ?? '')),
                );

                $normalizedParticipants[] = $normalizedParticipant;
            }

            $group['participants'] = $normalizedParticipants;
            $normalizedGroups[] = $group;
        }

        $payload['shiftGroups'] = $normalizedGroups;

        return $payload;
    }

    /**
     * @param array<FitnessTestShiftGroup> $shiftGroups
     * @return array<int, mixed>
     */
    private function buildShiftGroups(array $shiftGroups): array
    {
        $groups = [];

        foreach ($shiftGroups as $groupIndex => $shiftGroup) {
            if (! $shiftGroup instanceof FitnessTestShiftGroup) {
                continue;
            }

            $assessor = $shiftGroup->assessor;
            $team = $shiftGroup->team;
            $participants = [];
            foreach ($shiftGroup->participants as $participant) {
                if (! is_object($participant)) {
                    continue;
                }

                $participants[] = $this->buildParticipantResult($participant);
            }

            $groups[] = [
                'id' => $shiftGroup->source_group_uid !== null && $shiftGroup->source_group_uid !== ''
                    ? $shiftGroup->source_group_uid
                    : "group-{$groupIndex}-{$shiftGroup->id}",
                'teamId' => $shiftGroup->team_id,
                'teamName' => $team?->name,
                'shiftName' => $shiftGroup->shift_name_snapshot,
                'assessor' => [
                    'userId' => $shiftGroup->assessor_user_id,
                    'name' => $shiftGroup->assessor_name_snapshot ?: ($assessor?->name),
                ],
                'participants' => $participants,
            ];
        }

        return $groups;
    }

    private function buildParticipantResult(object $participant): array
    {
        $checkpointRows = [];
        foreach ($participant->checkpointResults as $checkpoint) {
            if (! is_object($checkpoint)) {
                continue;
            }

            $checkpointRows[] = [
                'checkpointCode' => (string) $checkpoint->checkpoint_code,
                'completed' => (bool) $checkpoint->completed,
                'durationSeconds' => $checkpoint->duration_seconds,
                'attempts' => $checkpoint->attempts,
                'remarks' => $checkpoint->remarks,
            ];
        }
        $this->sortCheckpointsInCpOrder($checkpointRows);

        $fitnessResult = is_string($participant->fitness_result) ? strtolower(trim($participant->fitness_result)) : null;
        $proficiencyResult = is_string($participant->proficiency_result) ? strtolower(trim($participant->proficiency_result)) : null;

        return [
            'id' => $participant->source_participant_uid !== null && $participant->source_participant_uid !== ''
                ? $participant->source_participant_uid
                : "participant-{$participant->id}",
            'userId' => $participant->user_id,
            'name' => $participant->participant_name_snapshot,
            'role' => $participant->role_snapshot,
            'source' => $participant->source,
            'ageSnapshot' => $participant->age_snapshot,
            'evaluationSource' => $participant->fitness_result_source ?? $participant->proficiency_result_source,
            'fitness' => [
                'sitUps' => $participant->sit_ups,
                'jumpingJacks' => $participant->jumping_jacks,
                'pushUps' => $participant->push_ups,
                'testedOn' => optional($participant->fitness_tested_on)->toDateString(),
                'result' => $fitnessResult,
            ],
            'proficiency' => [
                'durationSeconds' => $participant->proficiency_duration_seconds,
                'testedOn' => optional($participant->proficiency_tested_on)->toDateString(),
                'result' => $proficiencyResult,
                'checkpoints' => $checkpointRows,
            ],
            'assessmentStatus' => $this->combineAssessmentStatus($fitnessResult, $proficiencyResult),
        ];
    }

    private function calculateCompletionStatistics(array $payload): array
    {
        $groups = is_array($payload['shiftGroups'] ?? null) ? $payload['shiftGroups'] : [];
        $stats = [
            'participantCount' => 0,
            'passedAssessmentCount' => 0,
            'failedAssessmentCount' => 0,
            'incompleteAssessmentCount' => 0,
        ];

        foreach ($groups as $group) {
            if (! is_array($group)) {
                continue;
            }
            $participants = is_array($group['participants'] ?? null) ? $group['participants'] : [];
            foreach ($participants as $participant) {
                if (! is_array($participant)) {
                    continue;
                }
                $status = $this->assessmentStatusFromPayload($participant);
                if ($status === 'passed') {
                    $stats['passedAssessmentCount'] += 1;
                } elseif ($status === 'failed') {
                    $stats['failedAssessmentCount'] += 1;
                } else {
                    $stats['incompleteAssessmentCount'] += 1;
                }
                $stats['participantCount'] += 1;
            }
        }

        return $stats;
    }

    private function assessmentStatusFromPayload(array $participant): string
    {
        $fitness = is_array($participant['fitness'] ?? null) ? $participant['fitness'] : [];
        $proficiency = is_array($participant['proficiency'] ?? null) ? $participant['proficiency'] : [];
        $fitnessResult = $this->normalizeResultText((string) ($fitness['result'] ?? ''));
        $proficiencyResult = $this->normalizeResultText((string) ($proficiency['result'] ?? ''));

        return $this->combineAssessmentStatus($fitnessResult, $proficiencyResult);
    }

    private function normalizeResultText(string $result): ?string
    {
        $result = strtolower(trim($result));
        if ($result === '') {
            return null;
        }
        if (in_array($result, ['passed', 'pass', 'passes', 'pass/fail'], true)) {
            return 'passed';
        }
        if (in_array($result, ['failed', 'fail', 'failed result'], true)) {
            return 'failed';
        }

        return null;
    }

    private function countGroups(array $payload): int
    {
        return is_array($payload['shiftGroups'] ?? null) ? count($payload['shiftGroups']) : 0;
    }

    private function hasParticipants(array $shiftGroups): bool
    {
        $count = 0;
        foreach ($shiftGroups as $shiftGroup) {
            if (! $shiftGroup instanceof FitnessTestShiftGroup) {
                continue;
            }
            $count += count($shiftGroup->participants);
        }

        return $count > 0;
    }

    private function combineAssessmentStatus(?string $fitnessResult, ?string $proficiencyResult): string
    {
        if ($fitnessResult === 'passed' || $proficiencyResult === 'passed') {
            if ($fitnessResult === 'failed' || $proficiencyResult === 'failed') {
                return 'failed';
            }
            if ($fitnessResult === 'passed' && $proficiencyResult === 'passed') {
                return 'passed';
            }

            return 'passed';
        }
        if ($fitnessResult === 'failed' || $proficiencyResult === 'failed') {
            return 'failed';
        }

        return 'incomplete';
    }

    private function sortCheckpointsInCpOrder(array &$checkpoints): void
    {
        usort($checkpoints, function (array $left, array $right): int {
            $leftOrder = $this->checkpointOrder((string) $left['checkpointCode']);
            $rightOrder = $this->checkpointOrder((string) $right['checkpointCode']);
            if ($leftOrder === $rightOrder) {
                return 0;
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
}
