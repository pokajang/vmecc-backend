<?php

namespace App\Http\Requests\AiHelper;

use App\Models\AiHelperKnowledgeEntry;
use Illuminate\Validation\Rule;

class UploadAiHelperKnowledgeRequest extends AiHelperRequest
{
    public function rules(): array
    {
        $maxKb = max(1, (int) config('ai_helper.knowledge_upload_max_kb', 10240));

        return [
            'file' => ['required', 'file', 'mimes:pdf', 'mimetypes:application/pdf', 'max:'.$maxKb],
            'title' => ['nullable', 'string', 'max:140'],
            'scope_type' => ['required', 'string', Rule::in([AiHelperKnowledgeEntry::SCOPE_GLOBAL, AiHelperKnowledgeEntry::SCOPE_MODULE])],
            'module_key' => ['nullable', 'string', 'max:80'],
            'visibility' => ['nullable', 'string', Rule::in(AiHelperKnowledgeEntry::VISIBILITIES)],
            'page_context' => ['nullable'],
            'path' => ['nullable', 'string', 'max:255'],
            'acknowledged' => ['accepted'],
        ];
    }
}
