<?php

namespace App\Services\InspectionReports;

use Illuminate\Support\Str;

class InspectionReportLocationService
{
    public function fromRow(array $row): array
    {
        $explicitMain = $this->component($row['mainLocation'] ?? $row['main_location'] ?? $row['kit'] ?? '');
        $location = $this->component($row['location'] ?? '');
        $mainLocation = $explicitMain ?: $location;
        $details = [];
        if ($explicitMain !== '' && $location !== '' && ! $this->same($explicitMain, $location)) {
            $details[] = $location;
        }
        $subLocation = $this->component($row['subLocation'] ?? $row['sub_location'] ?? '');
        if ($subLocation !== '' && ! $this->same($subLocation, $mainLocation) && ! $this->same($subLocation, end($details) ?: '')) {
            $details[] = $subLocation;
        }

        return [
            'zone' => $this->component($row['zone'] ?? ''),
            'mainLocation' => $mainLocation,
            'subLocation' => implode(' > ', $details),
        ];
    }

    public function derive(iterable $locations): array
    {
        $normalized = $this->normalize($locations);
        $paths = array_map(fn (array $location): string => $this->path($location), $normalized);
        $pathParts = array_map(fn (array $location): array => $this->pathParts($location), $normalized);
        $zones = $this->uniqueValues($normalized, 'zone');
        $mainLocations = $this->uniqueValues($normalized, 'mainLocation');
        $allHaveZones = $this->allHaveValue($normalized, 'zone');
        $allHaveMainLocations = $this->allHaveValue($normalized, 'mainLocation');

        return [
            'locations' => $normalized,
            'paths' => $paths,
            'pathParts' => $pathParts,
            'summary' => $this->summary($normalized, $zones, $mainLocations, $allHaveZones, $allHaveMainLocations),
            'zone' => $allHaveZones && count($zones) === 1 ? $zones[0] : '',
            'mainLocation' => $allHaveMainLocations && count($mainLocations) === 1 ? $mainLocations[0] : '',
            'subLocation' => count($normalized) === 1 ? $normalized[0]['subLocation'] : '',
        ];
    }

    public function path(array $location): string
    {
        return implode(' > ', $this->pathParts($location));
    }

    private function normalize(iterable $locations): array
    {
        $unique = [];
        foreach ($locations as $location) {
            if (! is_array($location)) {
                continue;
            }
            $normalized = [
                'zone' => $this->component($location['zone'] ?? ''),
                'mainLocation' => $this->component($location['mainLocation'] ?? $location['main_location'] ?? $location['location'] ?? ''),
                'subLocation' => $this->component($location['subLocation'] ?? $location['sub_location'] ?? ''),
            ];
            if ($normalized['zone'] === '' && $normalized['mainLocation'] === '' && $normalized['subLocation'] === '') {
                continue;
            }
            $key = collect($normalized)
                ->map(fn (string $value): string => Str::lower($value))
                ->implode("\0");
            $unique[$key] ??= $normalized;
        }

        $normalized = array_values($unique);
        usort($normalized, fn (array $left, array $right): int => strnatcasecmp(
            $this->path($left),
            $this->path($right),
        ));

        return $normalized;
    }

    private function pathParts(array $location): array
    {
        return array_values(array_filter([
            $this->zoneLabel($this->component($location['zone'] ?? '')),
            $this->component($location['mainLocation'] ?? $location['main_location'] ?? $location['location'] ?? ''),
            $this->component($location['subLocation'] ?? $location['sub_location'] ?? ''),
        ], fn (string $value): bool => $value !== ''));
    }

    private function summary(
        array $locations,
        array $zones,
        array $mainLocations,
        bool $allHaveZones,
        bool $allHaveMainLocations,
    ): string {
        if ($locations === []) {
            return '';
        }
        if (count($locations) === 1) {
            return $this->path($locations[0]);
        }
        if ($allHaveMainLocations && count($mainLocations) === 1) {
            return implode(' > ', array_filter([
                $allHaveZones && count($zones) === 1 ? $this->zoneLabel($zones[0]) : '',
                $mainLocations[0],
            ])).' · '.count($locations).' locations';
        }
        if (! $allHaveMainLocations) {
            return sprintf('%d inspection locations', count($locations));
        }

        return sprintf('%d locations across %d areas', count($locations), count($mainLocations));
    }

    private function uniqueValues(array $rows, string $key): array
    {
        $values = [];
        foreach ($rows as $row) {
            $value = $this->text($row[$key] ?? '');
            if ($value !== '') {
                $values[Str::lower($value)] ??= $value;
            }
        }

        return array_values($values);
    }

    private function allHaveValue(array $rows, string $key): bool
    {
        return $rows !== [] && collect($rows)->every(
            fn (array $row): bool => $this->text($row[$key] ?? '') !== '',
        );
    }

    private function zoneLabel(string $zone): string
    {
        if ($zone === '' || Str::startsWith(Str::lower($zone), 'zone ')) {
            return $zone;
        }

        return 'Zone '.$zone;
    }

    private function text(mixed $value): string
    {
        return Str::of((string) $value)->squish()->toString();
    }

    private function same(string $left, string $right): bool
    {
        return $left !== '' && $right !== '' && Str::lower($left) === Str::lower($right);
    }

    private function component(mixed $value): string
    {
        $component = $this->text($value);

        return strcasecmp($component, 'N/A') === 0 ? '' : $component;
    }
}
