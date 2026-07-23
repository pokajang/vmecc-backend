<?php

namespace App\Services;

use Illuminate\Support\Str;

final class FitnessTestResultCalculator
{
    public function calculate(array $payload): array
    {
        if ((int) ($payload['fitnessSchemaVersion'] ?? 0) < 3) {
            return $payload;
        }

        $protocolRevision = trim((string) ($payload['protocolRevision'] ?? ''));
        $protocols = config('fitness.protocols', []);
        $protocol = is_array($protocols) && is_array($protocols[$protocolRevision] ?? null)
            ? $protocols[$protocolRevision]
            : null;

        $payload['protocolEvaluation'] = $protocol === null ? 'pending_protocol' : 'evaluated';
        $groups = is_array($payload['shiftGroups'] ?? null) ? $payload['shiftGroups'] : [];

        foreach ($groups as $groupIndex => $group) {
            if (! is_array($group)) {
                continue;
            }
            $participants = is_array($group['participants'] ?? null) ? $group['participants'] : [];
            foreach ($participants as $participantIndex => $participant) {
                if (! is_array($participant)) {
                    continue;
                }
                $fitness = is_array($participant['fitness'] ?? null) ? $participant['fitness'] : [];
                $proficiency = is_array($participant['proficiency'] ?? null) ? $participant['proficiency'] : [];

                $fitness['reportedResult'] = $this->normalizeReportedResult($fitness['result'] ?? null);
                $proficiency['reportedResult'] = $this->normalizeReportedResult($proficiency['result'] ?? null);
                $fitness['result'] = $protocol === null ? 'incomplete' : $this->fitnessResult($fitness, $protocol);
                $proficiency['result'] = $protocol === null ? 'incomplete' : $this->proficiencyResult($proficiency, $fitness, $protocol);

                $participant['fitness'] = $fitness;
                $participant['proficiency'] = $proficiency;
                $participants[$participantIndex] = $participant;
            }
            $group['participants'] = $participants;
            $groups[$groupIndex] = $group;
        }

        $payload['shiftGroups'] = $groups;

        return $payload;
    }

    private function fitnessResult(array $fitness, array $protocol): string
    {
        $minimums = is_array($protocol['fitnessMinimums'] ?? null) ? $protocol['fitnessMinimums'] : [];
        foreach (['sitUps' => 'sitUps', 'jumpingJacks' => 'jumpingJacks', 'pushUps' => 'pushUps'] as $field => $rule) {
            if (! array_key_exists($rule, $minimums)) {
                return 'incomplete';
            }
            $value = $this->integer($fitness[$field] ?? $fitness[Str::snake($field)] ?? null);
            if ($value === null) {
                return 'incomplete';
            }
            if ($value < (int) $minimums[$rule]) {
                return 'failed';
            }
        }

        return 'passed';
    }

    private function proficiencyResult(array $proficiency, array $fitness, array $protocol): string
    {
        $duration = $this->integer($proficiency['durationSeconds'] ?? $proficiency['duration_seconds'] ?? null);
        $maximumDuration = $this->integer($protocol['proficiencyMaxDurationSeconds'] ?? null);
        if ($duration === null || $maximumDuration === null) {
            return 'incomplete';
        }
        if ($duration > $maximumDuration) {
            return 'failed';
        }

        $required = is_array($protocol['requiredCheckpoints'] ?? null) ? $protocol['requiredCheckpoints'] : [];
        $checkpoints = is_array($fitness['checkpoints'] ?? null) ? $fitness['checkpoints'] : [];
        $completed = [];
        foreach ($checkpoints as $checkpoint) {
            if (! is_array($checkpoint)) {
                continue;
            }
            $code = strtoupper(trim((string) ($checkpoint['checkpointCode'] ?? $checkpoint['checkpoint_code'] ?? $checkpoint['code'] ?? '')));
            if ($code !== '') {
                $completed[$code] = (bool) ($checkpoint['completed'] ?? false);
            }
        }
        foreach ($required as $code) {
            if (($completed[strtoupper(trim((string) $code))] ?? false) !== true) {
                return 'failed';
            }
        }

        return 'passed';
    }

    private function normalizeReportedResult(mixed $value): ?string
    {
        $value = strtolower(trim((string) $value));

        return match ($value) {
            'passed', 'pass', 'passes', 'pass/fail' => 'passed',
            'failed', 'fail', 'failed result' => 'failed',
            default => null,
        };
    }

    private function integer(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^\d+$/', trim($value))) {
            return (int) trim($value);
        }

        return null;
    }
}
