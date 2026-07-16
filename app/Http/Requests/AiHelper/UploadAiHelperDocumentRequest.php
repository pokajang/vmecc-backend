<?php

namespace App\Http\Requests\AiHelper;

use App\Models\AiHelperDocument;
use Illuminate\Validation\Rule;

class UploadAiHelperDocumentRequest extends AiHelperRequest
{
    public function rules(): array
    {
        $maxKb = max(1, (int) config('ai_helper.document_upload_max_kb', 10240));

        return [
            'file' => ['required', 'file', 'mimes:pdf', 'mimetypes:application/pdf', 'max:'.$maxKb],
            'title' => ['nullable', 'string', 'max:140'],
            'visibility' => ['nullable', 'string', Rule::in(AiHelperDocument::VISIBILITIES)],
            'acknowledged' => ['accepted'],
        ];
    }
}
