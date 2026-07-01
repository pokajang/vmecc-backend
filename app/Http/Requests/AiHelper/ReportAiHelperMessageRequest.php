<?php

namespace App\Http\Requests\AiHelper;


class ReportAiHelperMessageRequest extends AiHelperRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'reason' => trim((string) $this->input('reason', '')),
        ]);
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ];
    }
}
