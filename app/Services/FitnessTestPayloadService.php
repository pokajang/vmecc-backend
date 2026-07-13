<?php

namespace App\Services;

use Illuminate\Support\Facades\Validator;

final class FitnessTestPayloadService
{
    private const SCHEMA_VERSION = 1;

    public function validateForDraft(array $payload): void
    {
        $this->validate($payload, false);
    }

    public function validateForSubmit(array $payload): void
    {
        $this->validate($payload, true);
    }

    private function validate(array $payload, bool $forSubmit): void
    {
        $rules = [
            'schemaVersion' => [$forSubmit ? 'required' : 'nullable', 'integer', 'in:'.self::SCHEMA_VERSION],
            'reportDate' => ['nullable', 'date_format:Y-m-d'],
            'reportTime' => ['nullable', 'date_format:H:i'],
            'weather' => ['nullable', 'string', 'max:190'],
            'incidentType' => ['nullable', 'string', 'max:190'],
            'location' => ['nullable', 'string', 'max:1000'],
            'details' => ['nullable', 'string', 'max:20000'],
            'summary' => ['nullable', 'string', 'max:20000'],
            'chronology' => ['nullable', 'array', 'max:250'],
            'chronology.*' => ['array'],
            'chronology.*.time' => ['nullable', 'date_format:H:i'],
            'chronology.*.action' => ['nullable', 'string', 'max:4000'],
        ];

        if ($forSubmit) {
            $rules = array_replace($rules, [
                'reportDate' => ['required', 'date_format:Y-m-d'],
                'reportTime' => ['required', 'date_format:H:i'],
                'weather' => ['required', 'string', 'max:190'],
                'incidentType' => ['required', 'string', 'max:190'],
                'location' => ['required', 'string', 'max:1000'],
                'details' => ['required', 'string', 'max:20000'],
                'summary' => ['required', 'string', 'max:20000'],
                'chronology' => ['required', 'array', 'min:1', 'max:250'],
                'chronology.*.time' => ['required', 'date_format:H:i'],
                'chronology.*.action' => ['required', 'string', 'max:4000'],
            ]);
        }

        Validator::make($payload, $rules)->validate();
    }
}
