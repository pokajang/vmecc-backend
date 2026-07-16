<?php

namespace App\Services\InspectionFireExtinguishers;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class FireExtinguisherCoveragePolicy
{
    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, string>
     */
    public function normalizeFilters(array $filters): array
    {
        $direction = strtolower($this->text($filters['direction'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';

        return [
            'search' => $this->text($filters['search'] ?? ''),
            'period' => strtolower($this->text($filters['period'] ?? 'all')) ?: 'all',
            'periodFrom' => $this->text($filters['periodFrom'] ?? $filters['from'] ?? ''),
            'periodTo' => $this->text($filters['periodTo'] ?? $filters['to'] ?? ''),
            'zone' => $this->text($filters['zone'] ?? ''),
            'location' => $this->text($filters['location'] ?? $filters['mainLocation'] ?? ''),
            'inspectedBy' => $this->text($filters['inspectedBy'] ?? $filters['lastInspectedBy'] ?? 'all') ?: 'all',
            'status' => strtolower($this->text($filters['status'] ?? 'all')) ?: 'all',
            'issues' => strtolower($this->text($filters['issues'] ?? 'all')) ?: 'all',
            'certification' => strtolower($this->text($filters['certification'] ?? 'all')) ?: 'all',
            'duplicateScope' => strtolower($this->text($filters['duplicateScope'] ?? 'all')) ?: 'all',
            'sort' => strtolower($this->text($filters['sort'] ?? 'zone-location')) ?: 'zone-location',
            'direction' => $direction,
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  array<string, string>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function filter(Collection $rows, array $filters): Collection
    {
        return $rows->filter(function (array $row) use ($filters): bool {
            if ($filters['status'] !== 'all' && $this->inspectionStatus($row) !== $filters['status']) {
                return false;
            }
            if ($filters['inspectedBy'] !== 'all' && $this->text($row['inspectedBy'] ?? '') !== $filters['inspectedBy']) {
                return false;
            }
            if ($filters['duplicateScope'] === 'locator' && (int) ($row['locatorDuplicateCount'] ?? 0) <= 1) {
                return false;
            }
            if (in_array($filters['duplicateScope'], ['report', 'reports'], true) && (int) ($row['duplicateCount'] ?? 0) <= 1) {
                return false;
            }
            if ($filters['issues'] === 'with-issues' && (int) ($row['issueCount'] ?? 0) <= 0) {
                return false;
            }
            if ($filters['issues'] === 'no-issues' && (int) ($row['issueCount'] ?? 0) > 0) {
                return false;
            }
            if ($filters['certification'] !== 'all' && $this->certificationStatus($row) !== $filters['certification']) {
                return false;
            }

            return true;
        })->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    public function sort(Collection $rows, string $sort, string $direction): Collection
    {
        $sorted = $rows->sort(function (array $a, array $b) use ($sort): int {
            return match ($sort) {
                'latest' => $this->compareValues($b['latestInspectionAt'] ?? '', $a['latestInspectionAt'] ?? ''),
                'days-left' => $this->compareValues((int) ($a['daysLeft'] ?? 0), (int) ($b['daysLeft'] ?? 0)),
                'issues' => $this->compareValues((int) ($b['issueCount'] ?? 0), (int) ($a['issueCount'] ?? 0)),
                'duplicates', 'reports' => $this->compareValues((int) ($b['duplicateCount'] ?? 0), (int) ($a['duplicateCount'] ?? 0)),
                'locator-duplicates' => $this->compareValues(
                    (int) ($b['locatorDuplicateCount'] ?? 0),
                    (int) ($a['locatorDuplicateCount'] ?? 0),
                ),
                default => $this->compareLocation($a, $b),
            };
        })->values();

        return $direction === 'desc' && $sort === 'zone-location'
            ? $sorted->reverse()->values()
            : $sorted;
    }

    /** @param Collection<int, array<string, mixed>> $rows */
    public function summary(Collection $rows): array
    {
        return [
            'total' => $rows->count(),
            'inspected' => $rows->filter(fn (array $row): bool => (string) ($row['latestInspectionAt'] ?? '') !== '')->count(),
            'notInspected' => $rows->filter(fn (array $row): bool => (string) ($row['latestInspectionAt'] ?? '') === '')->count(),
            'issues' => $rows->filter(fn (array $row): bool => (int) ($row['issueCount'] ?? 0) > 0)->count(),
            'duplicates' => $rows->filter(fn (array $row): bool => (int) ($row['duplicateCount'] ?? 0) > 1)->count(),
            'locatorDuplicates' => $rows->filter(fn (array $row): bool => (int) ($row['locatorDuplicateCount'] ?? 0) > 1)->count(),
            'expired' => $rows->filter(fn (array $row): bool => $this->text($row['daysLeft'] ?? '') !== '' && (int) $row['daysLeft'] < 0)->count(),
        ];
    }

    public function zoneSortValue(string $zone): int
    {
        if (preg_match('/^zone\s+(\d+)/i', trim($zone), $match) === 1) {
            return (int) $match[1];
        }
        if (preg_match('/^\d+$/', trim($zone)) === 1) {
            return (int) $zone;
        }

        return PHP_INT_MAX;
    }

    private function compareLocation(array $a, array $b): int
    {
        $zoneCompare = $this->compareValues(
            $this->zoneSortValue((string) ($a['zone'] ?? '')),
            $this->zoneSortValue((string) ($b['zone'] ?? '')),
        );
        if ($zoneCompare !== 0) {
            return $zoneCompare;
        }

        return strnatcasecmp(
            implode(' ', [$a['zone'] ?? '', $a['location'] ?? '', $a['subLocation'] ?? '', $a['idLocNo'] ?? '']),
            implode(' ', [$b['zone'] ?? '', $b['location'] ?? '', $b['subLocation'] ?? '', $b['idLocNo'] ?? '']),
        );
    }

    private function compareValues(mixed $a, mixed $b): int
    {
        return $a <=> $b;
    }

    private function inspectionStatus(array $row): string
    {
        if ($this->text($row['latestInspectionAt'] ?? '') === '') {
            return 'not-inspected';
        }
        if ((int) ($row['issueCount'] ?? 0) > 0) {
            return 'issues';
        }
        if ((int) ($row['duplicateCount'] ?? 0) > 1) {
            return 'duplicates';
        }

        return 'inspected';
    }

    private function certificationStatus(array $row): string
    {
        if ($this->text($row['daysLeft'] ?? '') === '') {
            return 'unknown';
        }
        if ((int) $row['daysLeft'] < 0) {
            return 'expired';
        }
        if ((int) $row['daysLeft'] <= 20) {
            return 'expiring';
        }

        return 'valid';
    }

    private function text(mixed $value): string
    {
        return Str::of((string) $value)->squish()->toString();
    }
}
