<?php

namespace App\Services;

use App\Models\InspectionExtinguisherResult;
use App\Models\InspectionFireExtinguisher;
use App\Models\InspectionSession;
use App\Models\InspectionSessionLocationProgress;
use Illuminate\Support\Str;

class InspectionFireExtinguisherSessionProgressService
{
    public function progress(InspectionSession $session): array
    {
        $results = $session->extinguisherResults()->get(['status', 'zone', 'main_location', 'sub_location']);
        $completed = $results->where('status', 'completed')->count();
        $locationProgress = $session->locationProgress()
            ->orderBy('main_location')
            ->orderBy('sub_location')
            ->get()
            ->map(fn (InspectionSessionLocationProgress $row): array => $this->formatLocationProgress($row))
            ->values()
            ->all();
        $completedLocations = collect($locationProgress)
            ->filter(fn (array $row): bool => $this->text($row['status'] ?? '') === 'completed')
            ->values()
            ->all();

        return [
            'sessionUid' => $session->session_uid,
            'sessionVersion' => (int) $session->version,
            'status' => $session->status,
            'totalResults' => $results->count(),
            'completedResults' => $completed,
            'locationProgress' => $locationProgress,
            'locationsCompleted' => count($completedLocations),
            'completedLocations' => $completedLocations,
        ];
    }

    /**
     * @param  array{zone?: string, mainLocation?: string, subLocation?: string}  $scope
     */
    public function sync(InspectionSession $session, ?int $actorUserId = null, array $scope = []): void
    {
        $scope = [
            'zone' => $this->text($scope['zone'] ?? ''),
            'mainLocation' => $this->text($scope['mainLocation'] ?? ''),
            'subLocation' => $this->text($scope['subLocation'] ?? ''),
        ];
        $catalogGroups = [];
        $catalogQuery = InspectionFireExtinguisher::query()
            ->where('is_active', true)
            ->where('lifecycle_status', 'active');
        if ($scope['mainLocation'] !== '') {
            $catalogQuery->where('main_location_name', $scope['mainLocation']);
        }
        if ($scope['subLocation'] !== '') {
            $catalogQuery->where('sub_location_name', $scope['subLocation']);
        }

        $catalogQuery->get()
            ->each(function (InspectionFireExtinguisher $row) use (&$catalogGroups): void {
                $zone = $this->text($row->zone ?? '');
                $mainLocation = $this->text($row->main_location_name ?? '');
                $subLocation = $this->text($row->sub_location_name ?? '');
                if ($mainLocation === '' || $subLocation === '') {
                    return;
                }

                $groupKey = $this->locationProgressKey($zone, $mainLocation, $subLocation);
                $assetKey = $this->canonicalAssetKey(
                    fireExtinguisherId: (int) $row->id,
                    activeIdentityKey: $this->text($row->active_identity_key ?? ''),
                    barcodeNo: $this->text($row->barcode_no ?? ''),
                    idLocNo: $this->text($row->id_loc_no ?? ''),
                    zone: $zone,
                    mainLocation: $mainLocation,
                    subLocation: $subLocation,
                );
                if ($groupKey === '' || $assetKey === '') {
                    return;
                }

                $catalogGroups[$groupKey] ??= [
                    'zone' => $zone,
                    'mainLocation' => $mainLocation,
                    'subLocation' => $subLocation,
                    'assetKeys' => [],
                ];
                $catalogGroups[$groupKey]['assetKeys'][$assetKey] = true;
            });

        $catalogGroups = array_filter(
            $catalogGroups,
            fn (array $group): bool => $this->locationMatchesScope(
                $group['zone'],
                $group['mainLocation'],
                $group['subLocation'],
                $scope,
            ),
        );

        if ($catalogGroups === []) {
            return;
        }

        $completedByGroup = [];
        $resultQuery = $session->extinguisherResults()->where('status', 'completed');
        if ($scope['mainLocation'] !== '') {
            $resultQuery->where('main_location', $scope['mainLocation']);
        }
        if ($scope['subLocation'] !== '') {
            $resultQuery->where('sub_location', $scope['subLocation']);
        }

        $resultQuery->get()
            ->each(function (InspectionExtinguisherResult $result) use (&$completedByGroup): void {
                $zone = $this->text($result->zone ?? '');
                $mainLocation = $this->text($result->main_location ?? '');
                $subLocation = $this->text($result->sub_location ?? '');
                if ($mainLocation === '' || $subLocation === '') {
                    return;
                }

                $assetKey = $this->text($result->canonical_asset_key ?? '');
                if ($assetKey === '') {
                    return;
                }
                $groupKey = $this->locationProgressKey($zone, $mainLocation, $subLocation);
                if ($groupKey === '') {
                    return;
                }
                $completedByGroup[$groupKey][$assetKey] = true;
            });

        $existingProgressQuery = $session->locationProgress();
        if ($scope['mainLocation'] !== '') {
            $existingProgressQuery->where('main_location', $scope['mainLocation']);
        }
        if ($scope['subLocation'] !== '') {
            $existingProgressQuery->where('sub_location', $scope['subLocation']);
        }
        $existingProgress = $existingProgressQuery->get();
        foreach ($catalogGroups as $groupKey => $group) {
            $expectedKeys = array_keys($group['assetKeys']);
            $completedKeys = array_keys($completedByGroup[$groupKey] ?? []);
            $expectedCount = count($expectedKeys);
            $completedCount = count(array_intersect($expectedKeys, $completedKeys));
            if ($expectedCount <= 0) {
                continue;
            }

            $progress = $existingProgress->first(
                fn (InspectionSessionLocationProgress $row): bool => $this->locationProgressKey(
                    $row->zone,
                    $row->main_location,
                    $row->sub_location,
                ) === $groupKey,
            );
            $isCompleted = $completedCount >= $expectedCount;
            $completedAt = null;
            $completedByUserId = null;
            if ($isCompleted) {
                $completedAt = $progress?->status === 'completed'
                    ? $progress->completed_at
                    : now();
                $completedByUserId = $progress?->status === 'completed'
                    ? $progress->completed_by_user_id
                    : $actorUserId;
            }
            $fields = [
                'zone' => $group['zone'],
                'main_location' => $group['mainLocation'],
                'sub_location' => $group['subLocation'],
                'status' => $isCompleted ? 'completed' : 'in_progress',
                'expected_count' => $expectedCount,
                'completed_count' => $completedCount,
                'completed_by_user_id' => $completedByUserId,
                'completed_at' => $completedAt,
            ];

            if ($progress) {
                if (! $this->progressFieldsChanged($progress, $fields)) {
                    continue;
                }
                $fields['version'] = ((int) $progress->version) + 1;
                $progress->update($fields);
            } else {
                $fields['version'] = 1;
                $progress = $session->locationProgress()->create($fields);
                $existingProgress->push($progress);
            }
        }
    }

    private function formatLocationProgress(InspectionSessionLocationProgress $progress): array
    {
        return [
            'id' => $progress->id,
            'zone' => $progress->zone,
            'mainLocation' => $progress->main_location,
            'subLocation' => $progress->sub_location,
            'status' => $progress->status,
            'expectedCount' => $progress->expected_count,
            'completedCount' => $progress->completed_count,
            'completedByUserId' => $progress->completed_by_user_id,
            'completedAt' => $progress->completed_at?->toIso8601String(),
            'version' => $progress->version,
            'updatedAt' => $progress->updated_at?->toIso8601String(),
        ];
    }

    private function canonicalAssetKey(
        ?int $fireExtinguisherId,
        string $activeIdentityKey,
        string $barcodeNo,
        string $idLocNo,
        string $zone,
        string $mainLocation,
        string $subLocation,
    ): string {
        if ($fireExtinguisherId) {
            return 'catalog:'.$fireExtinguisherId;
        }
        if ($activeIdentityKey !== '') {
            return 'identity:'.$activeIdentityKey;
        }
        if ($barcodeNo !== '') {
            return 'barcode:'.$this->identityPart($barcodeNo);
        }
        if ($idLocNo !== '' && $mainLocation !== '') {
            return 'location:'.hash('sha256', implode('|', [
                $this->identityPart($zone),
                $this->identityPart($mainLocation),
                $this->identityPart($subLocation),
                $this->identityPart($idLocNo),
            ]));
        }

        return '';
    }

    private function locationProgressKey(mixed $zone, mixed $mainLocation, mixed $subLocation): string
    {
        $zoneKey = $this->zoneIdentityPart($zone);
        $mainLocationKey = $this->identityPart($mainLocation);
        $subLocationKey = $this->identityPart($subLocation);
        if ($mainLocationKey === '' || $subLocationKey === '') {
            return '';
        }

        return implode('|', [$zoneKey, $mainLocationKey, $subLocationKey]);
    }

    /**
     * @param  array{zone: string, mainLocation: string, subLocation: string}  $scope
     */
    private function locationMatchesScope(
        mixed $zone,
        mixed $mainLocation,
        mixed $subLocation,
        array $scope,
    ): bool {
        if ($scope['zone'] !== '' && $this->zoneIdentityPart($zone) !== $this->zoneIdentityPart($scope['zone'])) {
            return false;
        }
        if ($scope['mainLocation'] !== '' && $this->identityPart($mainLocation) !== $this->identityPart($scope['mainLocation'])) {
            return false;
        }
        if ($scope['subLocation'] !== '' && $this->identityPart($subLocation) !== $this->identityPart($scope['subLocation'])) {
            return false;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function progressFieldsChanged(InspectionSessionLocationProgress $progress, array $fields): bool
    {
        return $this->text($progress->zone) !== $this->text($fields['zone'] ?? '')
            || $this->text($progress->main_location) !== $this->text($fields['main_location'] ?? '')
            || $this->text($progress->sub_location) !== $this->text($fields['sub_location'] ?? '')
            || $this->text($progress->status) !== $this->text($fields['status'] ?? '')
            || (int) $progress->expected_count !== (int) ($fields['expected_count'] ?? 0)
            || (int) $progress->completed_count !== (int) ($fields['completed_count'] ?? 0)
            || (int) ($progress->completed_by_user_id ?? 0) !== (int) ($fields['completed_by_user_id'] ?? 0);
    }

    private function text(mixed $value): string
    {
        return Str::of((string) $value)->squish()->toString();
    }

    private function identityPart(mixed $value): string
    {
        return Str::of(str_replace(["CO\u{00B2}", "CO\u{FFFD}"], 'CO2', (string) $value))
            ->squish()
            ->lower()
            ->toString();
    }

    private function zoneIdentityPart(mixed $value): string
    {
        return Str::of(preg_replace('/^zone\s+/i', '', $this->identityPart($value)) ?? '')
            ->squish()
            ->toString();
    }
}
