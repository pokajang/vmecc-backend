<?php

namespace App\Services;

use App\Models\FitnessTestCheckpointResult;
use App\Models\FitnessTestParticipantResult;
use App\Models\FitnessTestReport;
use App\Models\FitnessTestShiftGroup;
use App\Models\Report;
use Carbon\Carbon;

final class FitnessTestProjectionService
{
    public function project(Report $report, array $payload): FitnessTestReport
    {
        $fitnessReport = FitnessTestReport::query()->updateOrCreate(
            ['report_id' => (int) $report->id],
            [
                'reporting_month' => $this->deriveReportingMonth($payload, $report),
                'document_reference' => $this->normalizeText($payload['documentReference'] ?? null, 190),
                'protocol_revision' => $this->normalizeText($payload['protocolRevision'] ?? null, 64),
            ],
        );

        $fitnessReport->shiftGroups()->delete();

        $shiftGroups = is_array($payload['shiftGroups'] ?? null) ? $payload['shiftGroups'] : [];
        $stats = [
            'participant_count' => 0,
            'passed_assessment_count' => 0,
            'failed_assessment_count' => 0,
            'incomplete_assessment_count' => 0,
        ];
        $evaluationSource = (int) ($payload['fitnessSchemaVersion'] ?? 0) === 3
            ? ((string) ($payload['protocolEvaluation'] ?? '') === 'evaluated' ? 'protocol_evaluated' : 'pending_protocol')
            : 'legacy_reported';

        foreach ($shiftGroups as $groupIndex => $groupPayload) {
            if (! is_array($groupPayload)) {
                continue;
            }
            $shiftName = $this->normalizeText($groupPayload['shiftName'] ?? $groupPayload['shift'] ?? null, 190);
            $assessorName = $this->extractAssessorName($groupPayload['assessor'] ?? null);
            $shiftGroup = $fitnessReport->shiftGroups()->create([
                'source_group_uid' => $this->normalizeText($groupPayload['id'] ?? null, 190),
                'team_id' => $this->normalizeInt($groupPayload['teamId'] ?? null),
                'shift_name_snapshot' => $shiftName === '' ? null : $shiftName,
                'assessor_user_id' => $this->normalizeInt($groupPayload['assessor']['userId'] ?? $groupPayload['assessorUserId'] ?? null),
                'assessor_name_snapshot' => $assessorName === '' ? null : $assessorName,
                'display_order' => $this->normalizeInt($groupIndex, 0),
            ]);

            $participants = is_array($groupPayload['participants'] ?? null) ? $groupPayload['participants'] : [];
            foreach ($participants as $participantIndex => $participantPayload) {
                if (! is_array($participantPayload)) {
                    continue;
                }
                $result = $this->createParticipantResult(
                    $shiftGroup,
                    $participantPayload,
                    $this->normalizeInt($participantIndex, 0),
                    $evaluationSource,
                );

                if ($result['status'] === 'passed') {
                    $stats['passed_assessment_count'] += 1;
                } elseif ($result['status'] === 'failed') {
                    $stats['failed_assessment_count'] += 1;
                } else {
                    $stats['incomplete_assessment_count'] += 1;
                }
                $stats['participant_count'] += 1;
            }
        }

        $fitnessReport->update($stats);

        return $fitnessReport;
    }

    private function createParticipantResult(
        FitnessTestShiftGroup $shiftGroup,
        array $participantPayload,
        int $index,
        string $evaluationSource,
    ): array {
        $fitness = is_array($participantPayload['fitness'] ?? null) ? $participantPayload['fitness'] : [];
        $proficiency = is_array($participantPayload['proficiency'] ?? null) ? $participantPayload['proficiency'] : [];
        $fitnessResult = $this->normalizeResult($fitness['result'] ?? null);
        $proficiencyResult = $this->normalizeResult($proficiency['result'] ?? null);
        $result = $this->combineStatus($fitnessResult, $proficiencyResult);

        $participant = $shiftGroup->participants()->create([
            'source_participant_uid' => $this->normalizeText($participantPayload['id'] ?? null, 190),
            'user_id' => $this->normalizeInt($participantPayload['userId'] ?? $participantPayload['memberId'] ?? null),
            'participant_name_snapshot' => $this->normalizeText($participantPayload['name'] ?? null, 190),
            'role_snapshot' => $this->normalizeText($participantPayload['role'] ?? null, 190),
            'age_snapshot' => $this->normalizeInt($participantPayload['ageSnapshot'] ?? $participantPayload['age'] ?? null),
            'source' => $this->normalizeText($participantPayload['source'] ?? null, 80),
            'display_order' => $index,
            'fitness_tested_on' => $this->toDateTime($fitness['testedOn'] ?? $fitness['tested_at'] ?? null),
            'sit_ups' => $this->normalizeInt($fitness['sitUps'] ?? $fitness['sit_ups'] ?? null),
            'jumping_jacks' => $this->normalizeInt($fitness['jumpingJacks'] ?? $fitness['jumping_jacks'] ?? null),
            'push_ups' => $this->normalizeInt($fitness['pushUps'] ?? $fitness['push_ups'] ?? null),
            'fitness_result' => $fitnessResult,
            'fitness_result_source' => $evaluationSource,
            'proficiency_tested_on' => $this->toDateTime($proficiency['testedOn'] ?? $proficiency['tested_at'] ?? null),
            'proficiency_duration_seconds' => $this->normalizeInt($proficiency['durationSeconds'] ?? $proficiency['duration_seconds'] ?? null),
            'proficiency_result' => $proficiencyResult,
            'proficiency_result_source' => $evaluationSource,
        ]);

        $checkpointPayload = is_array($fitness['checkpoints'] ?? null)
            ? $fitness['checkpoints']
            : (is_array($fitness['checkpointResults'] ?? null)
                ? $fitness['checkpointResults']
                : (is_array($proficiency['checkpoints'] ?? null)
                    ? $proficiency['checkpoints']
                    : (is_array($proficiency['checkpointResults'] ?? null)
                        ? $proficiency['checkpointResults']
                        : [])));
        foreach ($checkpointPayload as $checkpointIndex => $checkpoint) {
            if (! is_array($checkpoint)) {
                continue;
            }
            $checkpointCode = $this->normalizeText(
                $checkpoint['checkpointCode'] ?? $checkpoint['checkpoint_code'] ?? $checkpoint['code'] ?? null,
                64,
            );
            if ($checkpointCode === '') {
                continue;
            }
            FitnessTestCheckpointResult::query()->updateOrCreate(
                [
                    'fitness_test_participant_result_id' => (int) $participant->id,
                    'checkpoint_code' => $checkpointCode,
                ],
                [
                    'completed' => $this->normalizeBoolean($checkpoint['completed'] ?? null),
                    'duration_seconds' => $this->normalizeInt($checkpoint['durationSeconds'] ?? $checkpoint['duration_seconds'] ?? null),
                    'attempts' => $this->normalizeInt($checkpoint['attempts'] ?? null),
                    'remarks' => $this->normalizeText($checkpoint['remarks'] ?? null, 4000),
                    'display_order' => $this->normalizeInt($checkpointIndex, 0),
                ],
            );
        }

        return ['status' => $result];
    }

    private function deriveReportingMonth(array $payload, Report $report): ?string
    {
        $value = $this->normalizeText($payload['reportingMonth'] ?? null, 7);
        if ($this->isValidReportingMonth($value)) {
            return $value;
        }
        if ($value !== '') {
            return null;
        }

        $reportDate = $this->toDateTime($payload['reportDate'] ?? null);
        if ($reportDate !== null) {
            return Carbon::parse($reportDate)->format('Y-m');
        }
        if ($report->submitted_at instanceof \DateTimeInterface) {
            return Carbon::parse($report->submitted_at)->format('Y-m');
        }

        return null;
    }

    private function isValidReportingMonth(string $value): bool
    {
        if ($value === '') {
            return false;
        }
        $parsed = Carbon::createFromFormat('Y-m', $value);

        return $parsed !== false && $parsed->format('Y-m') === $value;
    }

    private function extractAssessorName(mixed $assessor): string
    {
        if (! is_array($assessor)) {
            return '';
        }
        return $this->normalizeText(
            $assessor['name']
            ?? $assessor['assessorName']
            ?? $assessor['fullName']
            ?? null,
            190,
        );
    }

    private function combineStatus(?string $fitnessResult, ?string $proficiencyResult): string
    {
        if ($fitnessResult === 'passed' || $proficiencyResult === 'passed') {
            if ($fitnessResult === 'failed' || $proficiencyResult === 'failed') {
                return 'failed';
            }
            if ($fitnessResult === 'passed' && $proficiencyResult === 'passed') {
                return 'passed';
            }
        }
        if ($fitnessResult === 'failed' || $proficiencyResult === 'failed') {
            return 'failed';
        }
        if ($fitnessResult !== null || $proficiencyResult !== null) {
            return 'incomplete';
        }

        return 'incomplete';
    }

    private function normalizeResult(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_bool($value)) {
            return $value ? 'passed' : 'failed';
        }
        $text = strtolower(trim((string) $value));
        return match ($text) {
            'passed', 'pass', 'passes', 'pass/fail' => 'passed',
            'failed', 'fail', 'failed result' => 'failed',
            default => $text === '' ? null : null,
        };
    }

    private function normalizeText(mixed $value, int $maxLength): string
    {
        $text = trim((string) $value);
        if ($text === '') {
            return '';
        }
        return mb_strlen($text) > $maxLength ? mb_substr($text, 0, $maxLength) : $text;
    }

    private function normalizeInt(mixed $value, ?int $fallback = null): ?int
    {
        if ($value === null || $value === '') {
            return $fallback;
        }
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        if (is_int($value)) {
            return $value >= 0 ? $value : $fallback;
        }
        if (is_float($value)) {
            return $value >= 0.0 ? (int) $value : $fallback;
        }
        if (is_string($value)) {
            $trimmed = trim($value);
            if (preg_match('/^\\d+$/', $trimmed)) {
                return (int) $trimmed;
            }
        }
        if (is_numeric($value)) {
            $normalized = trim((string) $value);
            if (preg_match('/^\\d+(?:\\.0+)?$/', $normalized)) {
                return (int) floor((float) $normalized);
            }
        }

        return $fallback;
    }

    private function toDateTime(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return Carbon::parse((string) $value)->toDateTimeString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function normalizeBoolean(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }
        if ($value === '') {
            return false;
        }
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (int) $value !== 0;
        }
        if (is_string($value)) {
            $text = strtolower(trim($value));
            if (in_array($text, ['1', 'true', 'yes', 'on', 'y'], true)) {
                return true;
            }
            if (in_array($text, ['0', 'false', 'no', 'off', 'n', ''], true)) {
                return false;
            }
        }

        return false;
    }
}
