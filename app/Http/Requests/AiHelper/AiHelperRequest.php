<?php

namespace App\Http\Requests\AiHelper;

use App\Services\AiHelperApiResponder;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

abstract class AiHelperRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function failedValidation(Validator $validator): void
    {
        $responder = app(AiHelperApiResponder::class);

        throw new HttpResponseException(response()->json([
            'message' => 'The given data was invalid.',
            'code' => 'AI_HELPER_VALIDATION_FAILED',
            'request_id' => $responder->requestId($this),
            'errors' => $validator->errors(),
        ], 422));
    }
}
