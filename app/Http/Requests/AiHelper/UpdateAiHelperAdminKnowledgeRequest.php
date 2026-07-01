<?php

namespace App\Http\Requests\AiHelper;

use App\Models\AiHelperKnowledgeEntry;
use Illuminate\Validation\Rule;

class UpdateAiHelperAdminKnowledgeRequest extends AiHelperRequest
{
    public function rules(): array
    {
        return [
            'review_status' => ['nullable', 'string', Rule::in(AiHelperKnowledgeEntry::REVIEW_STATUSES)],
            'status' => ['nullable', 'string', Rule::in([AiHelperKnowledgeEntry::STATUS_ACTIVE, AiHelperKnowledgeEntry::STATUS_DISABLED])],
            'review_note' => [
                Rule::requiredIf(fn () => $this->input('review_status') === AiHelperKnowledgeEntry::REVIEW_REJECTED),
                'nullable',
                'string',
                'max:2000',
            ],
        ];
    }
}
