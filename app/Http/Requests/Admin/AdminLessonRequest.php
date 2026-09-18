<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class AdminLessonRequest extends FormRequest
{
    /**
     * The actual authorization check is performed in the controller via
     * `$this->authorize('update', $level->course)` so the ability
     * resolves through `CoursePolicy`. Returning `true` here keeps
     * FormRequest focused on input validation.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Shared by create and update: the same lesson fields are writable
     * in both operations.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'material' => ['nullable', 'string', 'max:65535'],
            'is_published' => ['nullable', 'boolean'],
            'order' => ['nullable', 'integer', 'min:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'title.required' => 'Название урока обязательно',
            'title.string' => 'Название урока должно быть строкой',
            'title.max' => 'Название урока не может превышать 255 символов',
            'material.string' => 'Материал урока должен быть строкой',
            'material.max' => 'Материал урока не может превышать 65535 символов',
            'is_published.boolean' => 'Признак публикации урока должен быть булевым значением',
            'order.integer' => 'Порядковый номер урока должен быть целым числом',
            'order.min' => 'Порядковый номер урока не может быть отрицательным',
        ];
    }

    /**
     * Narrow the validated payload for the array-shape contracts of
     * `CreateLesson`/`UpdateLesson`.
     *
     * @return array{title: string, material?: string|null, is_published?: bool, order?: int}
     */
    public function validated($key = null, $default = null)
    {
        return parent::validated($key, $default);
    }
}
