<?php

namespace App\Services;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class ErAssessmentPayloadService
{
    public function __construct(private readonly ErAssessmentCatalog $catalog) {}

    private static function normalizePhotos(array $photos): array
    {
        $result = [];
        foreach ($photos as $photo) {
            if (! is_array($photo)) {
                continue;
            }

            $mediaId = trim((string) ($photo['mediaId'] ?? $photo['media_id'] ?? $photo['id'] ?? ''));
            $url = trim((string) ($photo['url'] ?? $photo['dataUrl'] ?? $photo['data_url'] ?? ''));
            if ($mediaId === '' && $url === '') {
                continue;
            }

            $result[] = [
                'id' => trim((string) ($photo['id'] ?? $mediaId)),
                'mediaId' => $mediaId,
                'url' => $url,
                'thumbnailUrl' => trim((string) ($photo['thumbnailUrl'] ?? $photo['thumbnail_url'] ?? '')),
                'fileName' => trim((string) ($photo['fileName'] ?? $photo['file_name'] ?? $photo['name'] ?? '')),
                'description' => trim((string) ($photo['description'] ?? $photo['caption'] ?? '')),
                'mimeType' => trim((string) ($photo['mimeType'] ?? $photo['mime_type'] ?? '')),
                'sizeBytes' => max(0, (int) ($photo['sizeBytes'] ?? $photo['size_bytes'] ?? $photo['size'] ?? 0)),
                'width' => max(0, (int) ($photo['width'] ?? $photo['width_px'] ?? 0)),
                'height' => max(0, (int) ($photo['height'] ?? $photo['height_px'] ?? 0)),
                'thumbnailSizeBytes' => max(
                    0,
                    (int) ($photo['thumbnailSizeBytes'] ?? $photo['thumbnail_size_bytes'] ?? 0),
                ),
                'thumbnailWidth' => max(0, (int) ($photo['thumbnailWidth'] ?? $photo['thumbnail_width'] ?? 0)),
                'thumbnailHeight' => max(0, (int) ($photo['thumbnailHeight'] ?? $photo['thumbnail_height'] ?? 0)),
                'checksumSha256' => trim((string) ($photo['checksumSha256'] ?? $photo['checksum_sha256'] ?? '')),
                'leaseId' => trim((string) ($photo['leaseId'] ?? $photo['lease_id'] ?? '')),
                'leaseExpiresAt' => trim((string) ($photo['leaseExpiresAt'] ?? $photo['lease_expires_at'] ?? '')),
                'leaseAbsoluteExpiresAt' => trim((string) ($photo['leaseAbsoluteExpiresAt'] ?? $photo['lease_absolute_expires_at'] ?? '')),
                'uploadId' => trim((string) ($photo['uploadId'] ?? $photo['upload_id'] ?? '')),
            ];
        }

        return $result;
    }

    public function normalize(array $payload): array
    {
        $type = $this->catalog->type($payload['assessmentType'] ?? $payload['assessmentTypeLabel'] ?? $payload['incidentType'] ?? '');
        $incoming = collect(is_array($payload['responses'] ?? null) ? $payload['responses'] : []);
        $responses = [];

        foreach ($type['requirements'] ?? [] as $index => $requirement) {
            $row = $incoming->first(function ($candidate) use ($requirement): bool {
                if (! is_array($candidate)) {
                    return false;
                }

                return trim((string) ($candidate['requirementId'] ?? '')) === $requirement['id']
                    || trim((string) ($candidate['requirement'] ?? '')) === $requirement['label'];
            }) ?? $incoming->get($index, []);
            $requirementId = trim((string) ($requirement['id'] ?? ''));
            $isEscapeRoute = str_ends_with($requirementId, '.escape-routes');
            $responses[] = [
                'requirementId' => $requirementId,
                'requirement' => $requirement['label'],
                'response' => trim((string) ($row['response'] ?? '')),
                'remarks' => trim((string) ($row['remarks'] ?? '')),
                'assemblyArea' => $isEscapeRoute ? trim((string) ($row['assemblyArea'] ?? '')) : '',
                'photos' => self::normalizePhotos(is_array($row['photos'] ?? null) ? $row['photos'] : []),
            ];
        }

        $layout = is_array($payload['rescueAccessLayout'] ?? null) ? $payload['rescueAccessLayout'] : null;
        if ($layout !== null) {
            $layout = [
                'mediaId' => trim((string) ($layout['mediaId'] ?? $layout['media_id'] ?? '')),
                'url' => trim((string) ($layout['url'] ?? '')),
                'thumbnailUrl' => trim((string) ($layout['thumbnailUrl'] ?? $layout['thumbnail_url'] ?? '')),
                'name' => trim((string) ($layout['name'] ?? $layout['fileName'] ?? 'Rescue access layout')),
                'sizeBytes' => max(0, (int) ($layout['sizeBytes'] ?? $layout['size_bytes'] ?? 0)),
            ];
        }

        $normalizeSignatory = fn (mixed $value): array => [
            'name' => trim((string) (is_array($value) ? ($value['name'] ?? '') : '')),
            'company' => trim((string) (is_array($value) ? ($value['company'] ?? '') : '')),
            'signature' => trim((string) (is_array($value) ? ($value['signature'] ?? '') : '')),
        ];

        return [
            'schemaVersion' => ErAssessmentCatalog::SCHEMA_VERSION,
            'templateVersion' => ErAssessmentCatalog::TEMPLATE_VERSION,
            'document' => ErAssessmentCatalog::DOCUMENT,
            'workflowStep' => trim((string) ($payload['workflowStep'] ?? 'setup')),
            'company' => trim((string) ($payload['company'] ?? '')),
            'assessmentDate' => trim((string) ($payload['assessmentDate'] ?? $payload['reportDate'] ?? '')),
            'location' => trim((string) ($payload['location'] ?? '')),
            'scopeOfWork' => trim((string) ($payload['scopeOfWork'] ?? $payload['details'] ?? $payload['description'] ?? '')),
            'assessmentType' => $type['id'] ?? trim((string) ($payload['assessmentType'] ?? '')),
            'assessmentTypeLabel' => $type['label'] ?? '',
            'worstCaseScenario' => $type['worstCaseScenario'] ?? '',
            'responses' => $responses,
            'rescuePlan' => trim((string) ($payload['rescuePlan'] ?? $payload['summary'] ?? '')),
            'rescueAccessLayout' => $layout,
            'rescueEquipment' => collect(is_array($payload['rescueEquipment'] ?? null) ? $payload['rescueEquipment'] : [])
                ->map(fn ($item): string => trim((string) $item))
                ->filter()
                ->unique()
                ->take(10)
                ->values()->all(),
            'inspectedBy' => $normalizeSignatory($payload['inspectedBy'] ?? []),
            'jobLeader' => $normalizeSignatory($payload['jobLeader'] ?? []),
        ];
    }

    public function validateForDraft(array $payload): array
    {
        $normalized = $this->normalize($payload);
        $this->validate($normalized, false);

        return $normalized;
    }

    public function validateForSubmit(array $payload): array
    {
        $this->validateRawResponseContract($payload);
        $normalized = $this->normalize($payload);
        $this->validate($normalized, true);
        unset($normalized['workflowStep']);

        return $normalized;
    }

    private function validateRawResponseContract(array $payload): void
    {
        $type = $this->catalog->type($payload['assessmentType'] ?? $payload['assessmentTypeLabel'] ?? $payload['incidentType'] ?? '');
        if ($type === null) {
            return;
        }

        $requirements = collect($type['requirements']);
        $expected = $requirements->pluck('id')->all();
        $labels = $requirements->pluck('id', 'label');
        $responses = is_array($payload['responses'] ?? null) ? $payload['responses'] : [];
        $actual = collect($responses)->map(function ($row) use ($labels): string {
            if (! is_array($row)) {
                return '';
            }
            $id = trim((string) ($row['requirementId'] ?? ''));
            if ($id !== '') {
                return $id;
            }

            return (string) ($labels[trim((string) ($row['requirement'] ?? ''))] ?? '');
        })->all();

        if ($actual !== $expected || count(array_unique($actual)) !== count($actual)) {
            throw ValidationException::withMessages([
                'responses' => ['Responses must contain every canonical requirement exactly once and in template order.'],
            ]);
        }
    }

    private function validate(array $payload, bool $forSubmit): void
    {
        $required = $forSubmit ? 'required' : 'nullable';
        $rules = [
            'schemaVersion' => ['required', 'integer', 'in:'.ErAssessmentCatalog::SCHEMA_VERSION],
            'templateVersion' => ['required', 'string', 'in:'.ErAssessmentCatalog::TEMPLATE_VERSION],
            'workflowStep' => ['nullable', 'string', 'in:setup,requirements,rescue,equipment,signoff'],
            'company' => [$required, 'string', 'max:190'],
            'assessmentDate' => [$required, 'date_format:Y-m-d', 'before_or_equal:today'],
            'location' => [$required, 'string', 'max:1000'],
            'scopeOfWork' => [$required, 'string', 'max:20000'],
            'assessmentType' => [$required, 'string', 'max:80'],
            'responses' => [$forSubmit ? 'required' : 'nullable', 'array', $forSubmit ? 'min:1' : 'max:10', 'max:10'],
            'responses.*.requirementId' => ['required_with:responses', 'string', 'max:100'],
            'responses.*.requirement' => ['required_with:responses', 'string', 'max:500'],
            'responses.*.response' => [$forSubmit ? 'required' : 'nullable', 'string', 'in:'.implode(',', ErAssessmentCatalog::RESPONSE_OPTIONS)],
            'responses.*.remarks' => ['nullable', 'string', 'max:4000'],
            'responses.*.assemblyArea' => ['nullable', 'string', 'max:4000'],
            'responses.*.photos' => ['nullable', 'array'],
            'responses.*.photos.*.id' => ['nullable', 'string', 'max:40'],
            'responses.*.photos.*.mediaId' => ['required_with:responses.*.photos.*.url', 'nullable', 'string', 'max:80'],
            'responses.*.photos.*.url' => ['nullable', 'string', 'max:2000'],
            'responses.*.photos.*.thumbnailUrl' => ['nullable', 'string', 'max:2000'],
            'responses.*.photos.*.fileName' => ['nullable', 'string', 'max:255'],
            'responses.*.photos.*.description' => ['nullable', 'string', 'max:2000'],
            'responses.*.photos.*.mimeType' => ['nullable', 'string', 'max:64'],
            'responses.*.photos.*.sizeBytes' => ['nullable', 'integer', 'min:1', 'max:1572864'],
            'responses.*.photos.*.width' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'responses.*.photos.*.height' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'responses.*.photos.*.thumbnailSizeBytes' => ['nullable', 'integer', 'min:0'],
            'responses.*.photos.*.thumbnailWidth' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'responses.*.photos.*.thumbnailHeight' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'responses.*.photos.*.checksumSha256' => ['nullable', 'string', 'max:128'],
            'responses.*.photos.*.leaseId' => ['nullable', 'string', 'max:80'],
            'responses.*.photos.*.leaseExpiresAt' => ['nullable', 'string', 'max:80'],
            'responses.*.photos.*.leaseAbsoluteExpiresAt' => ['nullable', 'string', 'max:80'],
            'responses.*.photos.*.uploadId' => ['nullable', 'string', 'max:80'],
            'rescuePlan' => [$required, 'string', 'max:20000'],
            'rescueAccessLayout' => [$forSubmit ? 'required' : 'nullable', 'array'],
            'rescueAccessLayout.mediaId' => [$forSubmit ? 'required' : 'nullable', 'string', 'max:80'],
            'rescueAccessLayout.url' => [$forSubmit ? 'required' : 'nullable', 'string', 'max:2000'],
            'rescueAccessLayout.thumbnailUrl' => ['nullable', 'string', 'max:2000'],
            'rescueAccessLayout.name' => ['nullable', 'string', 'max:255'],
            'rescueEquipment' => [$forSubmit ? 'required' : 'nullable', 'array', $forSubmit ? 'min:1' : 'max:10', 'max:10'],
            'rescueEquipment.*' => ['required', 'string', 'max:500'],
            'inspectedBy' => [$forSubmit ? 'required' : 'nullable', 'array'],
            'inspectedBy.name' => [$required, 'string', 'max:190'],
            'inspectedBy.company' => [$required, 'string', 'max:190'],
            'inspectedBy.signature' => [$required, 'string', 'max:190'],
            'jobLeader' => [$forSubmit ? 'required' : 'nullable', 'array'],
            'jobLeader.name' => [$required, 'string', 'max:190'],
            'jobLeader.company' => [$required, 'string', 'max:190'],
            'jobLeader.signature' => [$required, 'string', 'max:190'],
        ];

        $validator = Validator::make($payload, $rules);
        $validator->after(function ($validator) use ($payload, $forSubmit): void {
            $type = $this->catalog->type($payload['assessmentType'] ?? '');
            if (($payload['assessmentType'] ?? '') !== '' && $type === null) {
                $validator->errors()->add('assessmentType', 'Select a supported ER Assessment type.');

                return;
            }
            if (! $forSubmit || $type === null) {
                return;
            }

            $expected = collect($type['requirements'])->pluck('id')->all();
            $actual = collect($payload['responses'] ?? [])->pluck('requirementId')->all();
            if ($actual !== $expected) {
                $validator->errors()->add('responses', 'Responses must match every requirement in the selected ER Assessment template.');
            }
            $seenPhotoMediaIds = [];
            foreach ($payload['responses'] ?? [] as $index => $response) {
                if (($response['response'] ?? '') === 'No' && trim((string) ($response['remarks'] ?? '')) === '') {
                    $validator->errors()->add(
                        "responses.{$index}.remarks",
                        'Explain the gap and immediate action required.',
                    );
                }
                if (($response['response'] ?? '') !== 'No' && is_array($response['photos'] ?? null)) {
                    $photos = collect($response['photos'])->filter(
                        static fn (mixed $photo): bool => is_array($photo) && trim((string) ($photo['mediaId'] ?? '')) !== '',
                    )->all();
                    if ($photos !== []) {
                        $validator->errors()->add("responses.{$index}.photos", 'Photos are only allowed for No responses.');
                    }
                }
                $photoRows = collect($response['photos'] ?? []);
                $invalidPhoto = $photoRows->first(
                    static fn (mixed $photo): bool => is_array($photo) && (
                        (trim((string) ($photo['mediaId'] ?? '')) !== '' &&
                            trim((string) ($photo['url'] ?? '')) === '') ||
                        (trim((string) ($photo['mediaId'] ?? '')) === '' &&
                            trim((string) ($photo['url'] ?? '')) !== '')
                    ),
                );
                if ($invalidPhoto !== null) {
                    $validator->errors()->add("responses.{$index}.photos", 'Photo media reference is incomplete.');
                }
                if (($response['response'] ?? '') === 'No' && $photoRows->isNotEmpty()) {
                    $invalidManaged = $photoRows->filter(
                        static fn (mixed $photo): bool => is_array($photo) && ! empty(trim((string) ($photo['mediaId'] ?? ''))),
                    );
                    foreach ($invalidManaged as $photo) {
                        $mediaId = trim((string) ($photo['mediaId'] ?? ''));
                        $url = trim((string) ($photo['url'] ?? ''));
                        if ($mediaId !== '' && ! $this->urlMatchesMediaId($url, $mediaId)) {
                            $validator->errors()->add("responses.{$index}.photos", 'Photo URLs must reference the attached media ID.');
                            break;
                        }
                        if (isset($seenPhotoMediaIds[$mediaId])) {
                            $validator->errors()->add("responses.{$index}.photos", 'Each managed evidence photo may be referenced only once.');
                            break;
                        }
                        $seenPhotoMediaIds[$mediaId] = true;
                    }
                }

            }

            $mediaId = trim((string) data_get($payload, 'rescueAccessLayout.mediaId', ''));
            $url = trim((string) data_get($payload, 'rescueAccessLayout.url', ''));
            if ($mediaId !== '' && ! $this->urlMatchesMediaId($url, $mediaId)) {
                $validator->errors()->add('rescueAccessLayout.url', 'The rescue access layout URL must reference its managed media ID.');
            }
        });
        $validator->validate();
    }

    private function urlMatchesMediaId(string $url, string $mediaId): bool
    {
        if ($mediaId === '') {
            return false;
        }

        $path = parse_url($url, PHP_URL_PATH);
        if (! is_string($path)) {
            return false;
        }

        return str_ends_with(rtrim($path, '/'), '/report-media/'.rawurlencode($mediaId))
            || str_ends_with(rtrim($path, '/'), '/report-media/'.$mediaId);
    }
}
