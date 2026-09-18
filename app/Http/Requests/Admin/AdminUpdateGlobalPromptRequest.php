<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class AdminUpdateGlobalPromptRequest extends FormRequest
{
    /**
     * The actual authorization check is performed in the controller via
     * `$this->authorize('update', AiPromptVersion::class)` so the ability
     * resolves through `AiPromptVersionPolicy`. Returning `true` here
     * keeps FormRequest focused on input validation.
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
            'body' => ['required', 'string', 'min:1', 'max:65535'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'body.required' => 'Текст промпта обязателен',
            'body.string' => 'Текст промпта должен быть строкой',
            'body.min' => 'Текст промпта не может быть пустым',
            'body.max' => 'Текст промпта не может превышать 65535 символов',
            'comment.string' => 'Комментарий должен быть строкой',
            'comment.max' => 'Комментарий не может превышать 1000 символов',
        ];
    }
}
