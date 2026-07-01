<?php

namespace App\Http\Requests\AiHelper;

use Illuminate\Validation\Rule;

class StreamAiHelperMessageRequest extends AiHelperRequest
{
    public function rules(): array
    {
        $maxLength = max(1, (int) config('ai_helper.max_message_length', 2000));

        return [
            'thread_id' => ['nullable', 'integer'],
            'message' => ['required', 'string', 'max:'.$maxLength],
            'page_context' => ['nullable', 'array'],
            'new_thread' => ['nullable', 'boolean'],
            'response_language' => ['nullable', 'string', Rule::in(['auto', 'en', 'bm'])],
        ];
    }
}
