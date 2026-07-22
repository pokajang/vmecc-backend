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
            'page_context' => ['nullable', 'array:path,search,route_key,route_name,module_key,title,params'],
            'page_context.path' => ['nullable', 'string', 'max:255'],
            'page_context.search' => ['nullable', 'string', 'max:1000'],
            'page_context.route_key' => ['nullable', 'string', 'max:120'],
            'page_context.route_name' => ['nullable', 'string', 'max:160'],
            'page_context.module_key' => ['nullable', 'string', 'max:120'],
            'page_context.title' => ['nullable', 'string', 'max:160'],
            'page_context.params' => ['nullable', 'array', 'max:20'],
            'page_context.params.*' => ['nullable', 'string', 'max:120'],
            'ui_state' => ['nullable', 'array:record_status,current_step,record_kind,selected_type,missing_fields,available_actions'],
            'ui_state.record_status' => ['nullable', 'string', 'max:64', 'regex:/^[a-z0-9][a-z0-9._-]*$/'],
            'ui_state.current_step' => ['nullable', 'string', 'max:64', 'regex:/^[a-z0-9][a-z0-9._-]*$/'],
            'ui_state.record_kind' => ['nullable', 'string', 'max:64', 'regex:/^[a-z0-9][a-z0-9._-]*$/'],
            'ui_state.selected_type' => ['nullable', 'string', 'max:64', 'regex:/^[a-z0-9][a-z0-9._-]*$/'],
            'ui_state.missing_fields' => ['nullable', 'array', 'max:12'],
            'ui_state.missing_fields.*' => ['required', 'string', 'max:64', 'distinct', 'regex:/^[a-z0-9][a-z0-9._-]*$/'],
            'ui_state.available_actions' => ['nullable', 'array', 'max:8'],
            'ui_state.available_actions.*' => ['required', 'string', 'max:64', 'distinct', 'regex:/^[a-z0-9][a-z0-9._-]*$/'],
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
