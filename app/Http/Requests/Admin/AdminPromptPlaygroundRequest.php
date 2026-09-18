<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminPromptPlaygroundRequest extends FormRequest
{
    /**
     * The actual authorization check is performed in the controller via
     * `$this->authorize('playground', AiPromptVersion::class)` so the
     * ability resolves through `AiPromptVersionPolicy`. Returning `true`
     * here keeps FormRequest focused on input validation.
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
            'course_id' => ['nullable', 'integer', Rule::exists('courses', 'id')],
            'user_message' => ['required', 'string', 'min:1', 'max:65535'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'course_id.integer' => 'Курс должен быть числом',
            'course_id.exists' => 'Выбранный курс не найден',
            'user_message.required' => 'Сообщение для теста обязательно',
            'user_message.string' => 'Сообщение должно быть строкой',
            'user_message.max' => 'Сообщение не может превышать 65535 символов',
        ];
    }
}
