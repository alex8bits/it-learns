<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SubmitPracticeTaskRequest extends FormRequest
{
    /**
     * Единственный клиентский вход попытки (Этап 8): код решения
     * студента. Определение задания больше не приходит из запроса —
     * PracticeTaskInput собирается на сервере из route-bound модели
     * (спуфинг эталона/сида устранён, дизайн Этапа 8 В1-B).
     *
     * Авторизация — middleware `auth:web` группы маршрута; отдельная
     * политика не нужна (практика доступна всем авторизованным,
     * `concept.md §2.2`).
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
            'code' => ['required', 'string', 'max:65535'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.required' => 'Необходимо указать код решения',
            'code.string' => 'Код решения должен быть строкой',
            'code.max' => 'Код решения не может превышать 65535 символов',
        ];
    }
}
