<?php

namespace App\Http\Requests\AiHelper;

use App\Models\AiHelperResponseReport;

class ListAiHelperReportsRequest extends AiHelperRequest
{
    public function rules(): array
    {
        return [
            'status' => ['nullable', 'string', 'in:all,'.implode(',', AiHelperResponseReport::STATUSES)],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }
}
