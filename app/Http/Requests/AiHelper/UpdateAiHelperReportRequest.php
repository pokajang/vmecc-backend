<?php

namespace App\Http\Requests\AiHelper;

use App\Models\AiHelperResponseReport;

class UpdateAiHelperReportRequest extends AiHelperRequest
{
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', 'in:'.implode(',', AiHelperResponseReport::STATUSES)],
            'admin_note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
