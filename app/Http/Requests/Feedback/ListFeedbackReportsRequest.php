<?php

namespace App\Http\Requests\Feedback;

use App\Models\FeedbackReport;
use Illuminate\Foundation\Http\FormRequest;

class ListFeedbackReportsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'status' => ['nullable', 'string', 'in:all,'.implode(',', FeedbackReport::STATUSES)],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }
}
