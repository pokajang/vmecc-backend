<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class FireExtinguisherExceptionExportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'categories' => ['required', 'array', 'min:1', 'max:2'],
            'categories.*' => ['required', 'string', 'distinct', Rule::in(['issues', 'expired'])],
            'scope' => ['sometimes', 'string', Rule::in(['current_filters', 'all'])],
            'filters' => ['sometimes', 'array'],
            'filters.search' => ['nullable', 'string', 'max:190'],
            'filters.period' => ['nullable', 'string', Rule::in([
                'all', 'today', 'thisweek', 'thismonth', 'lastmonth', 'last7', 'last30', 'last90', 'custom',
            ])],
            'filters.periodFrom' => ['nullable', 'date_format:Y-m-d'],
            'filters.periodTo' => ['nullable', 'date_format:Y-m-d'],
            'filters.zone' => ['nullable', 'string', 'max:190'],
            'filters.location' => ['nullable', 'string', 'max:190'],
            'filters.inspectedBy' => ['nullable', 'string', 'max:190'],
            'filters.status' => ['nullable', 'string', Rule::in([
                'all', 'inspected', 'not-inspected', 'issues', 'duplicates',
            ])],
            'filters.duplicateScope' => ['nullable', 'string', Rule::in([
                'all', 'report', 'reports', 'locator', 'id-loc',
            ])],
            'format' => $this->formatRules(),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $period = data_get($this->all(), 'filters.period', 'all');
            if ($period !== 'custom') {
                return;
            }

            $from = (string) data_get($this->all(), 'filters.periodFrom', '');
            $to = (string) data_get($this->all(), 'filters.periodTo', '');
            if ($from === '' || $to === '' || $from > $to) {
                $validator->errors()->add('filters.period', 'A valid custom period range is required.');
            }
        });
    }

    /** @return array<int, mixed> */
    protected function formatRules(): array
    {
        return ['nullable', 'string', Rule::in(['pdf', 'docx'])];
    }
}
