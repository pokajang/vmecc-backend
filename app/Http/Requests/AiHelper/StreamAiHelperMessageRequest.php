<?php

namespace App\Http\Requests\AiHelper;

use App\Services\AiHelperEmbeddedTaskService;
use Illuminate\Validation\Rule;

class StreamAiHelperMessageRequest extends AiHelperRequest
{
    public function rules(): array
    {
        $maxLength = max(1, (int) config('ai_helper.max_message_length', 2000));
        if (trim((string) $this->input('embedded_task')) !== '') {
            $maxLength = max(
                $maxLength,
                min(20000, max(2000, (int) config('ai_helper.embedded_task_max_message_length', 12000))),
            );
        }

        return [
            'thread_id' => ['nullable', 'integer'],
            'message' => ['required', 'string', 'max:'.$maxLength],
            'page_context' => ['nullable', 'array'],
            'new_thread' => ['nullable', 'boolean'],
            'response_language' => ['nullable', 'string', Rule::in(['auto', 'en', 'bm'])],
            'conversation_purpose' => ['nullable', 'string', Rule::in(['chat', 'embedded_helper'])],
            'embedded_task' => [
                Rule::requiredIf(fn () => $this->input('conversation_purpose') === 'embedded_helper'),
                'nullable',
                'string',
                Rule::in(AiHelperEmbeddedTaskService::TASKS),
                Rule::prohibitedIf(fn () => $this->input('conversation_purpose') !== 'embedded_helper'),
            ],
            'request_uuid' => ['nullable', 'uuid'],
        ];
    }
}
