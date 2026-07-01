<?php

namespace App\Http\Requests\AiHelper;

use App\Models\AiHelperKnowledgeEntry;
use Illuminate\Validation\Rule;

class UploadAiHelperMarkdownKnowledgeRequest extends AiHelperRequest
{
    public function rules(): array
    {
        $maxKb = max(1, (int) config('ai_helper.markdown_upload_max_kb', 1024));

        return [
            'file' => ['required', 'file', 'max:'.$maxKb],
            'title' => ['nullable', 'string', 'max:140'],
            'scope_type' => ['nullable', 'string', Rule::in([AiHelperKnowledgeEntry::SCOPE_GLOBAL, AiHelperKnowledgeEntry::SCOPE_MODULE])],
            'module_key' => ['nullable', 'string', 'max:80'],
            'acknowledged' => ['accepted'],
        ];
    }
}
