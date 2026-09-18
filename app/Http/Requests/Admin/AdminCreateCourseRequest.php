<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\CourseStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminCreateCourseRequest extends FormRequest
{
    /**
     * The actual authorization check is performed in the controller via
     * `$this->authorize('create', Course::class)` so the ability resolves
     * through `CoursePolicy`. Returning `true` here keeps FormRequest
     * focused on input validation.
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
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:65535'],
            'status' => ['required', Rule::enum(CourseStatus::class)],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'preview_image' => [
                'nullable',
                'file',
                'mimes:jpg,jpeg,png,webp',
                'max:2048',
                'dimensions:min_width=200,min_height=200',
            ],
            'ai_course_prompt' => ['nullable', 'string', 'max:65535'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'title.required' => 'Название курса обязательно',
            'title.string' => 'Название курса должно быть строкой',
            'title.max' => 'Название курса не может превышать 255 символов',
            'description.required' => 'Описание курса обязательно',
            'description.string' => 'Описание курса должно быть строкой',
            'description.max' => 'Описание курса не может превышать 65535 символов',
            'status.required' => 'Статус курса обязателен',
            'status.Illuminate\Validation\Rules\Enum' => 'Недопустимый статус курса',
            'sort_order.integer' => 'Порядок курса должен быть целым числом',
            'sort_order.min' => 'Порядок курса не может быть отрицательным',
            'sort_order.max' => 'Порядок курса не может превышать 65535',
            'preview_image.file' => 'Превью курса должно быть загруженным файлом',
            'preview_image.mimes' => 'Превью курса должно быть изображением формата jpg, jpeg, png или webp',
            'preview_image.max' => 'Размер превью курса не может превышать 2 МБ',
            'preview_image.dimensions' => 'Превью курса должно быть не меньше 200×200 пикселей',
            'ai_course_prompt.string' => 'Промпт курса должен быть строкой',
            'ai_course_prompt.max' => 'Промпт курса не может превышать 65535 символов',
        ];
    }

    /**
     * Narrow the validated payload for the array-shape contracts of
     * `CreateCourse`/`UpdateCourse`; the uploaded file is fetched
     * separately via `$request->file('preview_image')`.
     *
     * @return array{title: string, description: string, status: string, sort_order?: int, ai_course_prompt?: string|null, preview_image?: mixed}
     */
    public function validated($key = null, $default = null)
    {
        return parent::validated($key, $default);
    }
}
