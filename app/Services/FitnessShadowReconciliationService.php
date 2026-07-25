<?php

namespace App\Services;

use App\Models\FitnessShadowReconciliation;
use App\Models\FitnessTestCheckpointResult;
use App\Models\FitnessTestParticipantResult;
use App\Models\FitnessTestReport;
use App\Models\Report;

final class FitnessShadowReconciliationService
{
    private const STATUS_MATCHED = 'matched';

    private const STATUS_MISMATCHED = 'mismatched';

    private const STATUS_MISSING_PROJECTION = 'missing_projection';

    public function reconcile(Report $report): FitnessShadowReconciliation
    {
        $payload = is_array($report->payload) ? $report->payload : [];
        $payloadCanonical = $this->canonicalPayload((array) $payload, (int) $report->revision);
        $payloadHash = $this->hash($payloadCanonical);

        $fitnessReport = $this->loadProjection((int) $report->id);
        $projectionCanonical = $fitnessReport === null ? null : $this->canonicalProjection($fitnessReport);
        $projectionHash = $projectionCanonical === null ? str_repeat('0', 64) : $this->hash($projectionCanonical);

        $mismatchTypes = [];
        $mismatchDetails = [
            'revision_mismatches' => [],
            'ordering_differences' => [],
            'missing_participants' => [],
            'missing_assessor_references' => [],
            'result_calculations' => [],
            'cp_mismatches' => [],
        ];

        if ($projectionCanonical === null) {
            $this->addMismatch(
                $mismatchTypes,
                $mismatchDetails,
                'missing_projection',
                [
                    'report_uid' => (string) $report->report_uid,
                    'message' => 'Projection missing for fitness report.',
                ],
            );
            $status = self::STATUS_MISSING_PROJECTION;
        } else {
            $projectionRevision = (int) ($report->domain_projection_version ?? 0);
            $this->compareCanonicalizedPayloadProjection(
                $payloadCanonical,
                $projectionCanonical,
                (int) $report->revision,
                $projectionRevision,
                $mismatchTypes,
                $mismatchDetails,
            );
            $status = $mismatchTypes === [] ? self::STATUS_MATCHED : self::STATUS_MISMATCHED;
        }

        $previous = $this->latestForReport((int) $report->id);
        $reconciliation = FitnessShadowReconciliation::query()->create([
            'report_id' => (int) $report->id,
            'report_uid' => (string) $report->report_uid,
            'report_revision' => (int) $report->revision,
            'report_version' => (int) $report->version,
            'payload_hash' => $payloadHash,
            'projection_hash' => $projectionHash,
            'status' => $status,
            'mismatch_types' => $mismatchTypes === [] ? null : array_values(array_unique($mismatchTypes)),
            'mismatch_details' => $mismatchDetails,
            'run_at' => now(),
        ]);

        if (
            $status === self::STATUS_MATCHED
            && $previous !== null
            && in_array($previous->status, [self::STATUS_MISMATCHED, self::STATUS_MISSING_PROJECTION], true)
            && $previous->resolved_at === null
        ) {
            $previous->resolved_at = now();
            $previous->saveQuietly();
        }

        return $reconciliation;
    }

    private function compareCanonicalizedPayloadProjection(
        array $payload,
        array $projection,
        int $reportRevision,
        int $projectionRevision,
        array &$mismatchTypes,
        array &$mismatchDetails,
    ): void {
        if ($reportRevision !== $projectionRevision) {
            $this->addMismatch(
                $mismatchTypes,
                $mismatchDetails,
                'revision_mismatches',
                [
                    'message' => 'Revision mismatch.',
                    'payloadRevision' => $reportRevision,
                    'projectionRevision' => $projectionRevision,
                ],
            );
        }

        $payloadGroups = array_values($payload['shiftGroups'] ?? []);
        $projectionGroups = array_values($projection['shiftGroups'] ?? []);
        $maxGroups = max(count($payloadGroups), count($projectionGroups));

        if (count($payloadGroups) !== count($projectionGroups)) {
            $this->addMismatch(
                $mismatchTypes,
                $mismatchDetails,
                'ordering_differences',
                [
                    'message' => sprintf(
                        'Group count differs (payload=%d, projection=%d).',
                        count($payloadGroups),
                        count($projectionGroups),
                    ),
                ],
            );
        }

        if (($payload['resultCounts'] ?? []) !== ($projection['resultCounts'] ?? [])) {
            $this->addMismatch(
                $mismatchTypes,
                $mismatchDetails,
                'result_calculations',
                [
                    'message' => 'Result summary mismatch.',
                    'payload' => $payload['resultCounts'] ?? [],
                    'projection' => $projection['resultCounts'] ?? [],
                ],
            );
        }

        for ($groupIndex = 0; $groupIndex < $maxGroups; $groupIndex++) {
            $payloadGroup = $payloadGroups[$groupIndex] ?? null;
            $projectionGroup = $projectionGroups[$groupIndex] ?? null;

            if (! is_array($payloadGroup) || ! is_array($projectionGroup)) {
                $this->addMismatch(
                    $mismatchTypes,
                    $mismatchDetails,
                    'ordering_differences',
                    [
                        'message' => "Group missing at index {$groupIndex} on one side.",
                        'index' => $groupIndex,
                    ],
                );

                continue;
            }

            if (($payloadGroup['id'] ?? null) !== ($projectionGroup['id'] ?? null)) {
                $this->addMismatch(
                    $mismatchTypes,
                    $mismatchDetails,
                    'ordering_differences',
                    [
                        'message' => 'Group ordering identity mismatch.',
                        'index' => $groupIndex,
                        'payloadGroupId' => $payloadGroup['id'] ?? null,
                        'projectionGroupId' => $projectionGroup['id'] ?? null,
                    ],
                );
            }

            $payloadAssessor = $payloadGroup['assessor'] ?? [];
            $projectionAssessor = $projectionGroup['assessor'] ?? [];
            $payloadAssessorUserId = $this->normalizeInteger($payloadAssessor['userId'] ?? null);
            $projectionAssessorUserId = $this->normalizeInteger($projectionAssessor['userId'] ?? null);

            if ($payloadAssessorUserId !== null || $projectionAssessorUserId !== null) {
                if ($payloadAssessorUserId !== $projectionAssessorUserId) {
                    $this->addMismatch(
                        $mismatchTypes,
                        $mismatchDetails,
                        'missing_assessor_references',
                        [
                            'message' => 'Assessor user mismatch.',
                            'groupIndex' => $groupIndex,
                            'groupId' => $payloadGroup['id'] ?? $projectionGroup['id'] ?? null,
                            'payloadAssessorUserId' => $payloadAssessorUserId,
                            'projectionAssessorUserId' => $projectionAssessorUserId,
                        ],
                    );
                }
            } else {
                if ($this->normalizeString($payloadAssessor['name'] ?? null) !== '') {
                    $this->addMismatch(
                        $mismatchTypes,
                        $mismatchDetails,
                        'missing_assessor_references',
                        [
                            'message' => 'Assessor name mismatch.',
                            'groupIndex' => $groupIndex,
                            'groupId' => $payloadGroup['id'] ?? $projectionGroup['id'] ?? null,
                            'payloadAssessorName' => $this->normalizeString($payloadAssessor['name'] ?? null),
                            'projectionAssessorName' => $this->normalizeString($projectionAssessor['name'] ?? null),
                        ],
                    );
                }
            }

            $this->compareParticipants(
                $payloadGroup,
                $projectionGroup,
                $groupIndex,
                $mismatchTypes,
                $mismatchDetails,
            );
        }
    }

    private function compareParticipants(
        array $payloadGroup,
        array $projectionGroup,
        int $groupIndex,
        array &$mismatchTypes,
        array &$mismatchDetails,
    ): void {
        $payloadParticipants = array_values($payloadGroup['participants'] ?? []);
        $projectionParticipants = array_values($projectionGroup['participants'] ?? []);
        $maxParticipants = max(count($payloadParticipants), count($projectionParticipants));

        if (count($payloadParticipants) !== count($projectionParticipants)) {
            $this->addMismatch(
                $mismatchTypes,
                $mismatchDetails,
                'missing_participants',
                [
                    'message' => 'Participant count differs.',
                    'groupIndex' => $groupIndex,
                    'payloadCount' => count($payloadParticipants),
                    'projectionCount' => count($projectionParticipants),
                ],
            );
        }

        for ($participantIndex = 0; $participantIndex < $maxParticipants; $participantIndex++) {
            $payloadParticipant = $payloadParticipants[$participantIndex] ?? null;
            $projectionParticipant = $projectionParticipants[$participantIndex] ?? null;

            if (! is_array($payloadParticipant) || ! is_array($projectionParticipant)) {
                $this->addMismatch(
                    $mismatchTypes,
                    $mismatchDetails,
                    'ordering_differences',
                    [
                        'message' => 'Participant missing at group index.',
                        'groupIndex' => $groupIndex,
                        'participantIndex' => $participantIndex,
                    ],
                );

                continue;
            }

            if (($payloadParticipant['id'] ?? null) !== ($projectionParticipant['id'] ?? null)) {
                $this->addMismatch(
                    $mismatchTypes,
                    $mismatchDetails,
                    'ordering_differences',
                    [
                        'message' => 'Participant identity differs at the same index.',
                        'groupIndex' => $groupIndex,
                        'participantIndex' => $participantIndex,
                        'payloadParticipantId' => $payloadParticipant['id'] ?? null,
                        'projectionParticipantId' => $projectionParticipant['id'] ?? null,
                    ],
                );
            }

            if (($payloadParticipant['assessmentStatus'] ?? null) !== ($projectionParticipant['assessmentStatus'] ?? null)) {
                $this->addMismatch(
                    $mismatchTypes,
                    $mismatchDetails,
                    'result_calculations',
                    [
                        'message' => 'Assessment status differs.',
                        'groupIndex' => $groupIndex,
                        'participantIndex' => $participantIndex,
                        'payloadStatus' => $payloadParticipant['assessmentStatus'] ?? null,
                        'projectionStatus' => $projectionParticipant['assessmentStatus'] ?? null,
                    ],
                );
            }

            $payloadCheckpoints = $payloadParticipant['fitness']['checkpoints'] ?? [];
            $projectionCheckpoints = $projectionParticipant['fitness']['checkpoints'] ?? [];
            if ($this->hash($payloadCheckpoints) !== $this->hash($projectionCheckpoints)) {
                $this->addMismatch(
                    $mismatchTypes,
                    $mismatchDetails,
                    'cp_mismatches',
                    [
                        'message' => 'Checkpoint data mismatch.',
                        'groupIndex' => $groupIndex,
                        'participantIndex' => $participantIndex,
                        'payloadParticipantId' => $payloadParticipant['id'] ?? null,
                        'projectionParticipantId' => $projectionParticipant['id'] ?? null,
                        'payload' => $payloadCheckpoints,
                        'projection' => $projectionCheckpoints,
                    ],
                );
            }
        }
    }

    private function canonicalPayload(array $payload, int $revision): array
    {
        $normalizedGroups = [];
        $sourceGroups = array_values(is_array($payload['shiftGroups'] ?? null) ? $payload['shiftGroups'] : []);
        foreach ($sourceGroups as $groupIndex => $group) {
            if (! is_array($group)) {
                continue;
            }
            $normalizedGroups[] = $this->canonicalPayloadGroup((array) $group, $groupIndex);
        }

        return [
            'revision' => $revision,
            'reportingMonth' => $this->normalizeString($payload['reportingMonth'] ?? null),
            'documentReference' => $this->normalizeString($payload['documentReference'] ?? null),
            'protocolRevision' => $this->normalizeString($payload['protocolRevision'] ?? null),
            'shiftGroups' => $normalizedGroups,
            'resultCounts' => $this->computeResultCounts($normalizedGroups),
        ];
    }

    private function canonicalPayloadGroup(array $group, int $groupIndex): array
    {
        $participants = [];
        $sourceParticipants = array_values(is_array($group['participants'] ?? null) ? $group['participants'] : []);
        foreach ($sourceParticipants as $participantIndex => $participant) {
            if (! is_array($participant)) {
                continue;
            }
            $participants[] = $this->canonicalPayloadParticipant((array) $participant, $participantIndex);
        }

        $assessor = is_array($group['assessor'] ?? null) ? $group['assessor'] : [];

        return [
            'id' => $this->normalizeString($group['id'] ?? null, "group-{$groupIndex}"),
            'teamId' => $this->normalizeInteger($group['teamId'] ?? null),
            'assessor' => [
                'userId' => $this->normalizeInteger($assessor['userId'] ?? $assessor['user_id'] ?? null),
                'name' => $this->normalizeString($assessor['name'] ?? null),
            ],
            'participants' => $participants,
        ];
    }

    private function canonicalPayloadParticipant(array $participant, int $index): array
    {
        $fitness = is_array($participant['fitness'] ?? null) ? $participant['fitness'] : [];
        $proficiency = is_array($participant['proficiency'] ?? null) ? $participant['proficiency'] : [];
        $fitnessResult = $this->normalizeResult($fitness['result'] ?? null);
        $proficiencyResult = $this->normalizeResult($proficiency['result'] ?? null);

        return [
            'id' => $this->normalizeString($participant['id'] ?? null, "participant-{$index}"),
            'userId' => $this->normalizeInteger($participant['userId'] ?? $participant['memberId'] ?? null),
            'fitness' => [
                'checkpoints' => $this->normalizePayloadCheckpoints($fitness),
                'result' => $fitnessResult,
            ],
            'proficiency' => [
                'checkpoints' => $this->normalizePayloadCheckpoints($proficiency),
            ],
            'assessmentStatus' => $this->combineAssessmentStatus($fitnessResult, $proficiencyResult),
        ];
    }

    private function canonicalProjection(FitnessTestReport $fitnessReport): array
    {
        $fitnessReport->loadMissing([
            'shiftGroups.participants.checkpointResults',
        ]);
        $groups = [];
        foreach ($fitnessReport->shiftGroups as $group) {
            if (! is_object($group)) {
                continue;
            }
            $participants = [];
            foreach ($group->participants as $participant) {
                if (! is_object($participant)) {
                    continue;
                }
                $participants[] = $this->canonicalProjectionParticipant((object) $participant);
            }
            $groups[] = [
                'id' => $this->normalizeString($group->source_group_uid ?? null, "group-{$group->id}"),
                'teamId' => is_numeric($group->team_id) ? (int) $group->team_id : null,
                'assessor' => [
                    'userId' => is_numeric($group->assessor_user_id) ? (int) $group->assessor_user_id : null,
                    'name' => $this->normalizeString($group->assessor_name_snapshot ?? null),
                ],
                'participants' => $participants,
            ];
        }

        return [
            'revision' => (int) ($fitnessReport->report->domain_projection_version ?? 0),
            'reportingMonth' => $this->normalizeString($fitnessReport->reporting_month),
            'documentReference' => $this->normalizeString($fitnessReport->document_reference),
            'protocolRevision' => $this->normalizeString($fitnessReport->protocol_revision),
            'shiftGroups' => $groups,
            'resultCounts' => [
                'participantCount' => (int) $fitnessReport->participant_count,
                'passedAssessmentCount' => (int) $fitnessReport->passed_assessment_count,
                'failedAssessmentCount' => (int) $fitnessReport->failed_assessment_count,
                'incompleteAssessmentCount' => (int) $fitnessReport->incomplete_assessment_count,
            ],
        ];
    }

    private function canonicalProjectionParticipant(object $participant): array
    {
        if (! $participant instanceof FitnessTestParticipantResult) {
            $snapshot = (array) $participant;
            $participant = new FitnessTestParticipantResult((array) $snapshot);
        }

        $fitnessResult = $this->normalizeResult($participant->fitness_result);
        $proficiencyResult = $this->normalizeResult($participant->proficiency_result);
        $checkpointRows = $this->normalizeProjectionCheckpoints($participant->checkpointResults ?? []);

        return [
            'id' => $this->normalizeString($participant->source_participant_uid ?? null, "participant-{$participant->id}"),
            'userId' => is_numeric($participant->user_id) ? (int) $participant->user_id : null,
            'fitness' => [
                'checkpoints' => $checkpointRows,
                'result' => $fitnessResult,
            ],
            'proficiency' => [
                'checkpoints' => $checkpointRows,
            ],
            'assessmentStatus' => $this->combineAssessmentStatus($fitnessResult, $proficiencyResult),
        ];
    }

    private function normalizePayloadCheckpoints(array $payload): array
    {
        $checkpoints = [];
        foreach ($payload as $checkpoint) {
            if (! is_array($checkpoint)) {
                continue;
            }
            $checkpointCode = $this->normalizeString($checkpoint['checkpointCode'] ?? $checkpoint['checkpoint_code'] ?? $checkpoint['code'] ?? null);
            if ($checkpointCode === '') {
                continue;
            }
            $checkpoints[] = [
                'checkpointCode' => $checkpointCode,
                'completed' => (bool) ($checkpoint['completed'] ?? false),
                'durationSeconds' => $this->normalizeInteger($checkpoint['durationSeconds'] ?? $checkpoint['duration_seconds'] ?? null),
                'attempts' => $this->normalizeInteger($checkpoint['attempts'] ?? null),
                'remarks' => $this->normalizeString($checkpoint['remarks'] ?? null),
            ];
        }

        usort($checkpoints, function (array $left, array $right): int {
            return $this->checkpointOrder($left['checkpointCode']) <=> $this->checkpointOrder($right['checkpointCode']);
        });

        return $checkpoints;
    }

    private function normalizeProjectionCheckpoints(object|array $checkpointResults): array
    {
        $checkpoints = [];
        if (is_iterable($checkpointResults)) {
            foreach ($checkpointResults as $checkpoint) {
                if (! $checkpoint instanceof FitnessTestCheckpointResult) {
                    continue;
                }
                $checkpoints[] = [
                    'checkpointCode' => $this->normalizeString($checkpoint->checkpoint_code),
                    'completed' => (bool) $checkpoint->completed,
                    'durationSeconds' => $this->normalizeInteger($checkpoint->duration_seconds),
                    'attempts' => $this->normalizeInteger($checkpoint->attempts),
                    'remarks' => $this->normalizeString($checkpoint->remarks),
                ];
            }
        }

        usort($checkpoints, function (array $left, array $right): int {
            return $this->checkpointOrder($left['checkpointCode']) <=> $this->checkpointOrder($right['checkpointCode']);
        });

        return $checkpoints;
    }

    private function computeResultCounts(array $groups): array
    {
        $result = [
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
                $result['participantCount']++;
                match ($participant['assessmentStatus'] ?? null) {
                    'passed' => $result['passedAssessmentCount']++,
                    'failed' => $result['failedAssessmentCount']++,
                    default => $result['incompleteAssessmentCount']++,
                };
            }
        }

        return $result;
    }

    private function normalizeResult(mixed $value): ?string
    {
        $value = $this->normalizeString($value);
        if ($value === '') {
            return null;
        }
        if (in_array($value, ['passed', 'pass', 'passes', 'pass/fail'], true)) {
            return 'passed';
        }
        if (in_array($value, ['failed', 'fail', 'failed result'], true)) {
            return 'failed';
        }

        return $value;
    }

    private function combineAssessmentStatus(?string $fitnessResult, ?string $proficiencyResult): string
    {
        if ($fitnessResult === 'passed' || $proficiencyResult === 'passed') {
            if ($fitnessResult === 'failed' || $proficiencyResult === 'failed') {
                return 'failed';
            }

            return 'passed';
        }
        if ($fitnessResult === 'failed' || $proficiencyResult === 'failed') {
            return 'failed';
        }

        return 'incomplete';
    }

    private function addMismatch(array &$types, array &$details, string $type, array $payload): void
    {
        if (! in_array($type, $types, true)) {
            $types[] = $type;
        }
        if (! isset($details[$type]) || ! is_array($details[$type])) {
            $details[$type] = [];
        }
        $details[$type][] = $payload;
    }

    private function latestForReport(int $reportId): ?FitnessShadowReconciliation
    {
        return FitnessShadowReconciliation::query()
            ->where('report_id', $reportId)
            ->orderBy('id', 'desc')
            ->first();
    }

    private function loadProjection(int $reportId): ?FitnessTestReport
    {
        return FitnessTestReport::query()
            ->where('report_id', $reportId)
            ->with([
                'shiftGroups' => fn ($query) => $query->orderBy('display_order')->orderBy('id'),
                'shiftGroups.participants' => fn ($query) => $query->orderBy('display_order')->orderBy('id'),
                'shiftGroups.participants.checkpointResults' => fn ($query) => $query->orderBy('display_order')->orderBy('id'),
            ])
            ->first();
    }

    private function hash(array $value): string
    {
        $sorted = $this->sortForHash($value);
        $serialized = json_encode($sorted, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($serialized === false) {
            $serialized = serialize($sorted);
        }

        return hash('sha256', $serialized);
    }

    private function sortForHash(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn ($item) => $this->sortForHash($item), $value);
        }

        ksort($value);
        foreach ($value as $key => $item) {
            $value[$key] = $this->sortForHash($item);
        }

        return $value;
    }

    private function normalizeString(mixed $value, ?string $fallback = null): string
    {
        $text = trim((string) $value);
        if ($text === '') {
            return $fallback === null ? '' : $fallback;
        }

        return $text;
    }

    private function normalizeInteger(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        if (is_float($value)) {
            return (int) $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }
        if (is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }

    private function checkpointOrder(string $checkpointCode): int
    {
        $trimmed = trim($checkpointCode);
        if (preg_match('/^(?:cp)?(\\d{1,3})/i', $trimmed, $match)) {
            return (int) $match[1];
        }
        if (preg_match('/(\\d{1,3})$/', $trimmed, $match)) {
            return (int) $match[1];
        }

        return 9999;
    }
}
