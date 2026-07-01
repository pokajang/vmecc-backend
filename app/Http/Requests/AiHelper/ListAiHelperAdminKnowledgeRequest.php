<?php

namespace App\Http\Requests\AiHelper;


class ListAiHelperAdminKnowledgeRequest extends AiHelperRequest
{
    public function rules(): array
    {
        return [
            'status' => ['nullable', 'string', 'in:all,pending,approved,rejected,processing,active,disabled,failed'],
            'module_key' => ['nullable', 'string', 'max:255'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }
}
