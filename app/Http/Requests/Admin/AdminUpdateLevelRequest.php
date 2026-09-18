<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class AdminUpdateLevelRequest extends FormRequest
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
     * The level title is the mutable identity of the row within its
     * course: it is required (a level cannot be untitled since the
     * difficulty enum was dropped), and its per-course uniqueness is
     * enforced in the `UpdateLevel` Action through a pre-check with a
     * human-readable message. The `order` position is required too —
     * the edit form always sends it.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'order' => ['required', 'integer', 'min:0'],
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
            'order.required' => 'Порядковый номер уровня обязателен',
            'order.integer' => 'Порядковый номер уровня должен быть целым числом',
            'order.min' => 'Порядковый номер уровня не может быть отрицательным',
        ];
    }

    /**
     * Narrow the validated payload for the array-shape contract of
     * `UpdateLevel`.
     *
     * @return array{title: string, order: int}
     */
    public function validated($key = null, $default = null)
    {
        return parent::validated($key, $default);
    }
}
