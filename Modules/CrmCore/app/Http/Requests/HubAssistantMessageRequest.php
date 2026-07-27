<?php

namespace Modules\CrmCore\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class HubAssistantMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'message.required' => 'Votre message est obligatoire.',
            'message.string' => 'Votre message est invalide.',
            'message.max' => 'Votre message est trop long.',
        ];
    }

    public function assistantMessage(): string
    {
        return trim((string) $this->validated('message'));
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            response()
                ->json([
                    'ok' => false,
                    'error' => $validator->errors()->first(),
                ], 422, [], JSON_UNESCAPED_UNICODE)
        );
    }
}
