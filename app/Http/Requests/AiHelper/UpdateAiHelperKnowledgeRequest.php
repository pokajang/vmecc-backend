<?php

namespace App\Http\Requests\AiHelper;

use App\Models\AiHelperKnowledgeEntry;
use Illuminate\Validation\Rule;

class UpdateAiHelperKnowledgeRequest extends AiHelperRequest
{
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in([AiHelperKnowledgeEntry::STATUS_ACTIVE, AiHelperKnowledgeEntry::STATUS_DISABLED])],
        ];
    }
}
