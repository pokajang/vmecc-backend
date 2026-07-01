<?php

namespace App\Http\Requests\Feedback;

use App\Models\FeedbackReport;
use Illuminate\Foundation\Http\FormRequest;

class UpdateFeedbackReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'string', 'in:'.implode(',', FeedbackReport::STATUSES)],
            'admin_note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
