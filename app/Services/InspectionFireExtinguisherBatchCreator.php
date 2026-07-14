<?php

namespace App\Services;

use App\Models\InspectionFireExtinguisher;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InspectionFireExtinguisherBatchCreator
{
    public function __construct(
        private readonly InspectionSiteLocationCatalogService $siteLocationCatalog,
    ) {}

    /**
     * @param  array<string, mixed>  $location
     * @param  array<int, array<string, mixed>>  $items
     * @return array{rows?: Collection<int, InspectionFireExtinguisher>, conflicts?: array<int, array<string, mixed>>}
     */
    public function create(array $location, array $items, ?int $userId): array
    {
        $payloads = collect($items)
            ->map(fn (array $item): array => array_merge($location, $item))
            ->values();

        return DB::transaction(function () use ($payloads, $userId): array {
            $this->siteLocationCatalog->assertCompletePath($payloads->first(), lock: true);
            $databaseMatches = $this->matchingActiveLocators($payloads);
            $conflicts = $this->unconfirmedConflicts($payloads, $databaseMatches);

            if ($conflicts !== []) {
                return ['conflicts' => $conflicts];
            }

            $mainLocation = $this->text($payloads->first()['mainLocation'] ?? '');
            $sortOrder = ((int) InspectionFireExtinguisher::query()
                ->where('main_location_name', $mainLocation)
                ->select('sort_order')
                ->lockForUpdate()
                ->get()
                ->max('sort_order')) + 1;
            $identityKeys = $payloads
                ->map(fn (array $payload): ?string => $this->activeIdentityKey($payload))
                ->filter()
                ->unique()
                ->values();
            $existingIdentityKeys = InspectionFireExtinguisher::query()
                ->where('is_active', true)
                ->whereIn('active_identity_key', $identityKeys)
                ->lockForUpdate()
                ->pluck('active_identity_key')
                ->filter()
                ->flip();

            $rows = collect();
            foreach ($payloads as $payload) {
                $identityKey = $this->activeIdentityKey($payload);
                $confirmDuplicate = (bool) ($payload['confirmDuplicate'] ?? false);
                $identityAlreadyUsed = $identityKey !== null && $existingIdentityKeys->has($identityKey);

                $attributes = $this->payloadToAttributes($payload, [
                    'source' => 'custom',
                    'created_by' => $userId,
                    'is_active' => true,
                    'sort_order' => $sortOrder++,
                ]);
                if ($identityAlreadyUsed && $confirmDuplicate) {
                    $attributes['active_identity_key'] = null;
                }

                $row = InspectionFireExtinguisher::query()->create($attributes);
                $rows->push($row);
                if ($identityKey !== null && $attributes['active_identity_key'] !== null) {
                    $existingIdentityKeys->put($identityKey, true);
                }
            }

            return ['rows' => $rows];
        });
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $payloads
     * @return Collection<int, InspectionFireExtinguisher>
     */
    private function matchingActiveLocators(Collection $payloads): Collection
    {
        $locators = $payloads
            ->flatMap(fn (array $payload): array => $this->locatorCandidates($payload))
            ->unique()
            ->values();

        if ($locators->isEmpty()) {
            return collect();
        }

        return InspectionFireExtinguisher::query()
            ->where('is_active', true)
            ->where(function ($query) use ($locators): void {
                $query
                    ->whereIn(DB::raw('LOWER(TRIM(barcode_no))'), $locators)
                    ->orWhereIn(DB::raw('LOWER(TRIM(id_loc_no))'), $locators);
            })
            ->lockForUpdate()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $payloads
     * @param  Collection<int, InspectionFireExtinguisher>  $databaseMatches
     * @return array<int, array<string, mixed>>
     */
    private function unconfirmedConflicts(Collection $payloads, Collection $databaseMatches): array
    {
        $payloadLocators = $payloads
            ->map(fn (array $payload): array => $this->locatorCandidates($payload));

        return $payloads
            ->map(function (array $payload, int $index) use ($databaseMatches, $payloadLocators, $payloads): ?array {
                $locators = $payloadLocators[$index];
                $matches = $databaseMatches
                    ->filter(fn (InspectionFireExtinguisher $row): bool => $this->locatorsOverlap(
                        $locators,
                        $this->locatorCandidates([
                            'idLocNo' => $row->id_loc_no,
                            'barcodeNo' => $row->barcode_no,
                        ]),
                    ))
                    ->values();
                $batchMatches = $payloadLocators
                    ->filter(fn (array $candidateLocators, int $candidateIndex): bool => $candidateIndex !== $index && $this->locatorsOverlap($locators, $candidateLocators))
                    ->keys()
                    ->map(fn (int $candidateIndex): array => [
                        'index' => $candidateIndex,
                        'item' => $payloads[$candidateIndex],
                    ])
                    ->values();

                if (($matches->isEmpty() && $batchMatches->isEmpty()) || ($payload['confirmDuplicate'] ?? false)) {
                    return null;
                }

                return [
                    'index' => $index,
                    'matches' => $matches,
                    'batchMatches' => $batchMatches,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $left
     * @param  array<int, string>  $right
     */
    private function locatorsOverlap(array $left, array $right): bool
    {
        return array_intersect($left, $right) !== [];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function payloadToAttributes(array $data, array $extra): array
    {
        $validity = trim((string) ($data['certificationValidity'] ?? ''));

        return array_merge([
            'zone' => $this->text($data['zone'] ?? '') ?: null,
            'main_location_name' => $this->text($data['mainLocation'] ?? ''),
            'sub_location_name' => $this->text($data['subLocation'] ?? '') ?: null,
            'id_loc_no' => $this->text($data['idLocNo'] ?? '') ?: null,
            'barcode_no' => $this->text($data['barcodeNo'] ?? '') ?: null,
            'active_identity_key' => $this->activeIdentityKey($data),
            'fe_type' => $this->normalizeFeType($data['feType'] ?? '') ?: null,
            'certification_validity' => $validity !== '' ? $validity : null,
        ], $extra);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, string>
     */
    private function locatorCandidates(array $data): array
    {
        return collect([
            $this->locatorPart($data['barcodeNo'] ?? ''),
            $this->locatorPart($data['idLocNo'] ?? ''),
        ])->filter()->unique()->sort()->values()->all();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function activeIdentityKey(array $data): ?string
    {
        $mainLocation = $this->identityPart($data['mainLocation'] ?? '');
        $subLocation = $this->identityPart($data['subLocation'] ?? '');
        $idLocNo = $this->identityPart($data['idLocNo'] ?? '');
        $barcodeNo = $this->identityPart($data['barcodeNo'] ?? '');

        if ($mainLocation === '' || ($idLocNo === '' && $barcodeNo === '')) {
            return null;
        }

        return hash('sha256', implode('|', [$mainLocation, $subLocation, $idLocNo, $barcodeNo]));
    }

    private function identityPart(mixed $value): string
    {
        return Str::of($this->normalizeFeType($value))->squish()->lower()->toString();
    }

    private function locatorPart(mixed $value): string
    {
        $locator = Str::of((string) $value)->squish()->toString();
        $locator = preg_replace(
            '/^(?:s\s*\/?\s*n|serial(?:\s*(?:number|no\.?))?|barcode)\s*[:#-]?\s*/i',
            '',
            $locator,
        ) ?? $locator;

        return Str::of($locator)->squish()->lower()->toString();
    }

    private function text(mixed $value): string
    {
        return Str::of((string) $value)->squish()->toString();
    }

    private function normalizeFeType(mixed $value): string
    {
        return str_replace(["CO\u{00B2}", "CO\u{FFFD}"], 'CO2', $this->text($value));
    }
}
