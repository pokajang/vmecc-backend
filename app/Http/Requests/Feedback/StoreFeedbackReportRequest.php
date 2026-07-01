<?php

namespace App\Http\Requests\Feedback;

use Illuminate\Foundation\Http\FormRequest;

class StoreFeedbackReportRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $pageContext = $this->input('page_context');

        if (! is_array($pageContext)) {
            $pageContext = [];
        }

        $this->merge([
            'message' => trim((string) $this->input('message', '')),
            'page_context' => array_filter([
                'path' => trim((string) ($pageContext['path'] ?? '')),
                'search' => trim((string) ($pageContext['search'] ?? '')),
                'title' => trim((string) ($pageContext['title'] ?? '')),
            ], static fn ($value) => $value !== ''),
        ]);
    }

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'min:10', 'max:2000'],
            'page_context' => ['nullable', 'array'],
            'page_context.path' => ['nullable', 'string', 'max:255'],
            'page_context.search' => ['nullable', 'string', 'max:1000'],
            'page_context.title' => ['nullable', 'string', 'max:255'],
        ];
    }
}
