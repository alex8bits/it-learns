<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class AdminPromptHistoryIndexRequest extends FormRequest
{
    /**
     * The actual authorization check is performed in the controller via
     * `$this->authorize('viewAny', AiPromptVersion::class)`. Returning
     * `true` here keeps FormRequest focused on input validation.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Only canonical prompt keys are accepted: the global system prompt
     * or a course-scoped key built by `PromptKeys::forCourse()`.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'key' => ['required', 'string', 'regex:/^(ai\.global_system_prompt|course\.\d+\.ai_course_prompt)$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'key.required' => 'Необходимо указать ключ промпта',
            'key.string' => 'Ключ промпта должен быть строкой',
            'key.regex' => 'Недопустимый ключ промпта',
        ];
    }
}
