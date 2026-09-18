<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class AdminLevelRequest extends FormRequest
{
    /**
     * The actual authorization check is performed in the controller via
     * `$this->authorize('update', $course)` — a level is managed through
     * the abilities over its parent course (see `CoursePolicy`).
     * Returning `true` here keeps FormRequest focused on input
     * validation.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The title is required, but its per-course uniqueness is enforced in
     * the `CreateLevel` Action (the course is not reliably resolvable
     * here) through a pre-check with a human-readable message.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'order' => ['nullable', 'integer', 'min:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'title.required' => 'Название уровня обязательно',
            'title.string' => 'Название уровня должно быть строкой',
            'title.max' => 'Название уровня не может превышать 255 символов',
            'order.integer' => 'Порядковый номер уровня должен быть целым числом',
            'order.min' => 'Порядковый номер уровня не может быть отрицательным',
        ];
    }

    /**
     * Narrow the validated payload for the array-shape contract of
     * `CreateLevel`.
     *
     * @return array{title: string, order?: int}
     */
    public function validated($key = null, $default = null)
    {
        return parent::validated($key, $default);
    }
}
