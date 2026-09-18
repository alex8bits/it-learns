<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class AdminUpdateUserLlmLimitRequest extends FormRequest
{
    /**
     * The actual authorization check is performed in the controller via
     * `$this->authorize('updateLlmLimit', $user)` so we also reach
     * `UserPolicy::updateLlmLimit`'s self-mutation guard. Returning
     * `true` here keeps FormRequest focused on input validation.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'extra_tokens' => ['required', 'integer', 'min:0', 'max:100000000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'extra_tokens.required' => 'Необходимо указать количество дополнительных токенов',
            'extra_tokens.integer' => 'Количество дополнительных токенов должно быть целым числом',
            'extra_tokens.min' => 'Количество дополнительных токенов не может быть отрицательным',
            'extra_tokens.max' => 'Количество дополнительных токенов не может превышать 100000000',
        ];
    }
}
